<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Driver\Html\Sanitizer;
use EbookReader\Driver\Html\Title;
use EbookReader\Driver\Mobi\Font;
use EbookReader\Driver\Mobi\Index;
use EbookReader\Driver\Mobi\Kf8;
use EbookReader\Exception\ParserException;
use EbookReader\Model\Format;
use EbookReader\Model\Resource\Resource as EbookResource;
use EbookReader\Model\Section\Section;
use EbookReader\Model\TableOfContents\TableOfContentsEntry;

/**
 * Kindle Format 8 (AZW3/KF8).
 *
 * Shares the MOBI/Palm container and metadata with {@see MobiDriver}. The
 * content is stored as HTML skeletons and fragments which are reassembled by
 * {@see Kf8}.
 *
 * Two layouts are supported:
 * - standalone KF8: the MOBI header of the file itself is KF8 (version 8);
 * - joint MOBI6 + KF8: a legacy MOBI6 book with a KF8 section appended after a
 *   `BOUNDARY` record, referenced by EXTH tag 121.
 *
 * @see https://wiki.mobileread.com/wiki/KF8
 */
final class Azw3Driver extends MobiDriver
{
    private const int NULL_INDEX = 0xFFFFFFFF;

    #[\Override]
    public function getFormat(): Format
    {
        return Format::AZW3;
    }

    #[\Override]
    public function isValid(): bool
    {
        try {
            $f = new \SplFileObject($this->getFile(), 'r');
            $palmDbData = $this->parsePalmDb($f);
            $fileSize = \filesize($this->getFile());
            if (false === $fileSize) {
                return false;
            }

            return null !== $this->kf8Layout($f, $palmDbData, $fileSize);
        } catch (\Throwable $e) {
            return false;
        } finally {
            unset($f); // close file
        }
    }

    /**
     * @return list<Section>
     */
    #[\Override]
    public function getSections(): array
    {
        try {
            $f = new \SplFileObject($this->getFile(), 'r');
        } catch (\Exception $e) {
            throw new ParserException(previous: $e);
        }

        try {
            $palmDbData = $this->parsePalmDb($f);
            $records = $palmDbData['records'];

            $fileSize = \filesize($this->getFile());
            if (false === $fileSize) {
                throw new ParserException();
            }

            $layout = $this->kf8Layout($f, $palmDbData, $fileSize);
            if (null === $layout) {
                throw new ParserException();
            }

            $base = $layout['base'];
            $indexBase = $layout['index_base'];
            $record0 = $records[$base]['data_offset'];

            $f->fseek($record0);
            $palmDocData = $this->parsePalmDoc($f);
            $this->assertNotEncrypted($palmDocData['encryption']);
            $mobiData = $this->parseMobiHeader($f);

            $f->fseek($record0 + 192);
            $fdstIndex = $this->readInt($f, 4);
            $fdstCount = $this->readInt($f, 4);
            $f->fseek($record0 + 248);
            $divIndex = $this->readInt($f, 4);
            $skelIndex = $this->readInt($f, 4);
            if (self::NULL_INDEX === $skelIndex) {
                throw new ParserException();
            }

            // The KF8 index fields are relative to the start of the KF8 section.
            if (self::NULL_INDEX !== $fdstIndex) {
                $fdstIndex += $indexBase;
            }
            if (self::NULL_INDEX !== $divIndex) {
                $divIndex += $indexBase;
            }
            $skelIndex += $indexBase;
            if ($indexBase > 0) {
                $mobiData['huffman_record_offset'] += $indexBase;
            }

            $decompressor = $this->createDecompressor($f, $records, $mobiData, $palmDocData['compression']);

            $text = '';
            $textRecord = $base + 1;
            $textRecordEnd = $textRecord + $palmDocData['record_count'];
            for (; $textRecord < $textRecordEnd; ++$textRecord) {
                $flags = $mobiData['extra_record_data_flags'];
                $begin = $records[$textRecord]['data_offset'] ?? null;
                $end = $records[$textRecord + 1]['data_offset'] ?? $fileSize;

                if (null === $begin) {
                    continue;
                }

                $f->fseek($begin);
                $unpacked = \unpack('C*', $this->readBytes($f, $end - $begin));
                if (false === $unpacked) {
                    throw new ParserException();
                }
                $preData = \array_values($unpacked);

                $extraSize = $this->getRecordExtraSize($preData, $flags);
                $f->fseek($begin);
                $data = $this->readBytes($f, $end - $begin - $extraSize);
                $text .= $decompressor->decompress($data);
            }

            $textLength = $palmDocData['text_length'];
            if ($textLength > 0 && \strlen($text) > $textLength) {
                $text = \substr($text, 0, $textLength);
            }
            $text = $this->decodeText($text, $mobiData['text_encoding']);

            $flowTable = [];
            if (self::NULL_INDEX !== $fdstIndex && $fdstCount > 1 && isset($records[$fdstIndex])) {
                $flowTable = Kf8::flowTable($this->readRecord($f, $records, $fdstIndex, $fileSize));
            }
            $flows = Kf8::splitFlows($text, $flowTable);

            $loadRecord = fn (int $index): string => $this->readRecord($f, $records, $index, $fileSize);
            $skeletons = Index::parse($skelIndex, $loadRecord, $mobiData['text_encoding']);
            /** @var array{entries: list<array{0: string, 1: array<int, list<int>>}>, cncx: array<int, string>} $fragments */
            $fragments = self::NULL_INDEX === $divIndex
                ? ['entries' => [], 'cncx' => []]
                : Index::parse($divIndex, $loadRecord, $mobiData['text_encoding']);

            $parts = Kf8::buildParts($flows[0], $skeletons['entries'], $fragments['entries']);

            // The DIV index gives, per fragment, its insert position and file
            // number; a `kindle:pos:fid:F:off:O` link addresses fragment F
            // (base32) plus a base32 offset, i.e. a position in the assembled
            // markup.
            $fragmentInfo = [];
            foreach ($fragments['entries'] as [$identifier, $tags]) {
                $fragmentInfo[] = [(int) $identifier, $tags[3][0] ?? null];
            }
            $resolvePos = static fn (string $fid, string $offset): ?array => Kf8::resolvePosition($fragmentInfo, $parts, $fid, $offset);

            $resourceRecords = $layout['resource_records'];
            $resolveEmbed = function (string $embed) use ($f, $records, $fileSize, $resourceRecords): ?EbookResource {
                $number = Kf8::base32Decode($embed);
                if ($number < 1 || !isset($resourceRecords[$number - 1])) {
                    return null;
                }

                $recordIndex = $resourceRecords[$number - 1];
                if (!isset($records[$recordIndex])) {
                    return null;
                }

                $data = $this->readRecord($f, $records, $recordIndex, $fileSize);

                $font = Font::parse($data);
                if (null !== $font) {
                    return new EbookResource($embed, $font, $this->getMimeDetector()->detect($font) ?? 'font/ttf');
                }

                $mime = $this->getMimeDetector()->detect($data);
                if (null === $mime) {
                    return null;
                }

                return new EbookResource($embed, $data, $mime);
            };

            $data = [];
            foreach ($parts as $sectionIndex => $part) {
                [$html, $resources] = $this->expand($part['html'], $flows, $resolveEmbed, $resolvePos, $sectionIndex);
                $data[] = new Section($part['name'], $html, Title::fromHtml($html), 'text/html', $resources);
            }

            return $data;
        } finally {
            unset($f); // close file
        }
    }

    /**
     * @return list<TableOfContentsEntry>
     */
    #[\Override]
    public function getTableOfContents(): array
    {
        try {
            $f = new \SplFileObject($this->getFile(), 'r');
        } catch (\Exception $e) {
            throw new ParserException(previous: $e);
        }

        try {
            $palmDbData = $this->parsePalmDb($f);
            $records = $palmDbData['records'];

            $fileSize = \filesize($this->getFile());
            if (false === $fileSize) {
                throw new ParserException();
            }

            $layout = $this->kf8Layout($f, $palmDbData, $fileSize);
            if (null === $layout) {
                throw new ParserException();
            }

            $record0 = $records[$layout['base']]['data_offset'];
            $f->fseek($record0);
            $this->parsePalmDoc($f);
            $mobiData = $this->parseMobiHeader($f);

            $ncxIndex = $mobiData['ncx_index'];
            if (self::NULL_INDEX !== $ncxIndex) {
                $ncxIndex += $layout['index_base'];
            }
            if (self::NULL_INDEX === $ncxIndex || !isset($records[$ncxIndex])) {
                return [];
            }

            $loadRecord = fn (int $index): string => $this->readRecord($f, $records, $index, $fileSize);
            $parsed = Index::parse($ncxIndex, $loadRecord, $mobiData['text_encoding']);
            $starts = $this->skeletonStarts($f, $records, $record0, $layout['index_base'], $fileSize, $mobiData['text_encoding']);
        } finally {
            unset($f); // close file
        }

        $ranges = [];
        $count = \count($starts);
        for ($i = 0; $i < $count; ++$i) {
            $ranges[] = [$starts[$i], $starts[$i + 1] ?? \PHP_INT_MAX];
        }

        $toc = [];
        foreach ($parsed['entries'] as [$name, $tagMap]) {
            $title = isset($tagMap[3][0], $parsed['cncx'][$tagMap[3][0]]) ? $parsed['cncx'][$tagMap[3][0]] : $name;
            $title = \mb_trim($title);
            if ('' === $title) {
                continue;
            }

            $level = isset($tagMap[4][0]) ? $tagMap[4][0] + 1 : 1;
            $position = $tagMap[1][0] ?? null;
            $toc[] = new TableOfContentsEntry($title, \max(1, $level), self::sectionForPosition($position, $ranges), null, $position);
        }

        return $toc;
    }

    /**
     * Locates the KF8 section: a standalone KF8 header, or the section appended
     * after a `BOUNDARY` record in a joint MOBI6 + KF8 file.
     *
     * `kindle:embed:N` addresses a resource map built from the MOBI6 image range
     * followed by the KF8 image range (records 1-based, see calibre).
     *
     * @param array{records: list<array{data_offset: int, attributes: int, unique_id: string}>} $palmDbData
     *
     * @return array{base: int, index_base: int, resource_records: list<int>}|null
     */
    private function kf8Layout(\SplFileObject $f, array $palmDbData, int $fileSize): ?array
    {
        $records = $palmDbData['records'];
        $count = \count($records);
        if (!isset($records[0])) {
            return null;
        }

        $record0 = $records[0]['data_offset'];

        // Standalone KF8: the file's own MOBI header is KF8.
        if ($this->isKf8($f, $palmDbData)) {
            $f->fseek($record0 + 108);
            $firstImage = $this->readInt($f, 4);

            return [
                'base' => 0,
                'index_base' => 0,
                'resource_records' => self::recordRange($firstImage, $count),
            ];
        }

        // Joint MOBI6 + KF8: EXTH tag 121 points at the KF8 header, preceded by BOUNDARY.
        $f->fseek($record0);
        $this->parsePalmDoc($f);
        $mobi6 = $this->parseMobiHeader($f);
        $exth = $this->exthData($f, $mobi6);

        $base = $exth['kf8_header'];
        if (null === $base || $base < 1 || $base >= $count) {
            return null;
        }
        if ('BOUNDARY' !== $this->readRecord($f, $records, $base - 1, $fileSize)) {
            return null;
        }

        $kf8Record0 = $records[$base]['data_offset'];
        $f->fseek($kf8Record0 + 36);
        if (8 !== $this->readInt($f, 4)) {
            return null;
        }
        $f->fseek($kf8Record0 + 108);
        $kf8FirstImage = $this->readInt($f, 4);

        return [
            'base' => $base,
            'index_base' => $base,
            'resource_records' => \array_merge(
                self::recordRange($mobi6['first_image_index'], $base - 1),
                self::recordRange($kf8FirstImage + $base, $count),
            ),
        ];
    }

    /**
     * @param list<array{data_offset: int, attributes: int, unique_id: string}> $records
     *
     * @return list<int>
     */
    private function skeletonStarts(\SplFileObject $f, array $records, int $record0, int $indexBase, int $fileSize, int $encoding): array
    {
        $f->fseek($record0 + 252);
        $skelIndex = $this->readInt($f, 4);
        if (self::NULL_INDEX === $skelIndex) {
            return [];
        }
        $skelIndex += $indexBase;
        if (!isset($records[$skelIndex])) {
            return [];
        }

        $loadRecord = fn (int $index): string => $this->readRecord($f, $records, $index, $fileSize);
        $skeletons = Index::parse($skelIndex, $loadRecord, $encoding);

        $starts = [];
        foreach ($skeletons['entries'] as [, $tags]) {
            $starts[] = $tags[6][0] ?? 0;
        }
        \sort($starts);

        return $starts;
    }

    /**
     * @param array<int, array{0: int, 1: int}> $ranges
     */
    private static function sectionForPosition(?int $position, array $ranges): ?int
    {
        if (null === $position) {
            return null;
        }

        foreach ($ranges as $index => [$start, $end]) {
            if ($position >= $start && $position < $end) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private static function recordRange(int $start, int $end): array
    {
        $range = [];
        for ($i = \max(0, $start); $i < $end; ++$i) {
            $range[] = $i;
        }

        return $range;
    }

    /**
     * Inlines referenced flows (CSS/SVG), resolves `kindle:pos` links and image
     * references.
     *
     * @param list<string>                                                              $flows
     * @param callable(string): (?EbookResource)                                        $resolveEmbed
     * @param callable(string, string): (array{0: int, 1: string, 2: string|null}|null) $resolvePos
     *
     * @return array{0: string, 1: list<EbookResource>}
     */
    private function expand(string $html, array $flows, callable $resolveEmbed, callable $resolvePos, int $sectionIndex): array
    {
        $html = \preg_replace_callback(
            '~<[^>]*kindle:flow:([0-9A-V]+)\?mime=([^"\'\s>]+)[^>]*>~i',
            static function (array $matches) use ($flows): string {
                $index = Kf8::base32Decode($matches[1]);
                if (0 === $index || !isset($flows[$index])) {
                    return $matches[0];
                }

                $content = $flows[$index];

                return \str_contains(\strtolower($matches[2]), 'svg') || \str_contains($content, '<svg')
                    ? $content
                    : '<style type="text/css">'."\n".$content."\n".'</style>';
            },
            $html
        ) ?? $html;

        $html = \preg_replace_callback(
            '~kindle:pos:fid:([0-9A-V]+):off:([0-9A-V]+)~i',
            static function (array $matches) use ($resolvePos, $sectionIndex): string {
                $resolved = $resolvePos($matches[1], $matches[2]);
                if (null === $resolved) {
                    return $matches[0];
                }
                [$index, $name, $anchor] = $resolved;
                if ($index === $sectionIndex) {
                    return null === $anchor ? '#' : '#'.$anchor;
                }

                return $name.(null === $anchor ? '' : '#'.$anchor);
            },
            $html
        ) ?? $html;

        $resources = [];
        $seen = [];
        $html = \preg_replace_callback(
            '~kindle:embed:([0-9A-V]+)(?:\?mime=[^"\'\s)>]*)?~i',
            static function (array $matches) use ($resolveEmbed, &$resources, &$seen): string {
                $embed = $matches[1];
                if (!\array_key_exists($embed, $seen)) {
                    $seen[$embed] = $resolveEmbed($embed);
                }

                $resource = $seen[$embed];
                if (null === $resource) {
                    return $matches[0];
                }
                if (!\in_array($resource, $resources, true)) {
                    $resources[] = $resource;
                }

                return $embed;
            },
            $html
        ) ?? $html;

        return [Sanitizer::sanitize($html), $resources];
    }
}
