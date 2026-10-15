<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Decompressor\DecompressorInterface;
use EbookReader\Decompressor\HuffCdic;
use EbookReader\Decompressor\NoCompression;
use EbookReader\Decompressor\PalmDocLz77;
use EbookReader\Driver\Mobi\Font;
use EbookReader\Driver\Mobi\Index;
use EbookReader\Exception\EncryptedBookException;
use EbookReader\Exception\ParserException;
use EbookReader\Exception\UnsupportedCompressionException;
use EbookReader\Model\Cover\Cover;
use EbookReader\Model\Format;
use EbookReader\Model\Meta\Metadata;
use EbookReader\Model\Meta\PublishDate;
use EbookReader\Model\Resource\Resource as EbookResource;
use EbookReader\Model\Section\Section;
use EbookReader\Model\TableOfContents\TableOfContentsEntry;

/**
 * @see https://wiki.mobileread.com/wiki/MOBI
 * @see https://github.com/choccybiccy/mobi
 * @see https://github.com/rodzyk/readiverse
 */
class MobiDriver extends AbstractDriver
{
    #[\Override]
    public function getFormat(): Format
    {
        return Format::MOBI;
    }

    #[\Override]
    public function isValid(): bool
    {
        try {
            $f = new \SplFileObject($this->getFile(), 'r');
            $palmDbData = $this->parsePalmDb($f);
            $this->parsePalmDoc($f);
            $this->parseMobiHeader($f);
            $this->parseExth($f);
            if ($this->isKf8($f, $palmDbData)) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        } finally {
            unset($f); // close file
        }

        return true;
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
            $palmDocData = $this->parsePalmDoc($f);
            $this->assertNotEncrypted($palmDocData['encryption']);
            $mobiData = $this->parseMobiHeader($f);
            $this->parseExth($f);

            $decompressor = $this->createDecompressor($f, $palmDbData['records'], $mobiData, $palmDocData['compression']);

            if (!isset($palmDbData['records'][0])) {
                throw new ParserException();
            }

            $fileSize = \filesize($this->getFile());
            if (false === $fileSize) {
                throw new ParserException();
            }

            $text = '';
            for ($i = 1; $i <= $palmDocData['record_count']; ++$i) {
                $flags = $mobiData['extra_record_data_flags'];
                $begin = $palmDbData['records'][$i]['data_offset'] ?? null;
                $end = $palmDbData['records'][$i + 1]['data_offset'] ?? $fileSize;

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

            $resources = $this->collectResources($f, $palmDbData['records'], $mobiData, $text, $fileSize);
        } finally {
            unset($f); // close file
        }

        return [
            new Section('0', $text, null, 'text/html', $resources),
        ];
    }

    /**
     * @param list<array{data_offset: int, attributes: int, unique_id: string}>                                                                                                                                                                          $records
     * @param array{full_name_offset: int, full_name_length: int, type: int, extra_record_data_flags: int, text_encoding: int, huffman_record_offset: int, huffman_record_count: int, first_non_book_index: int, first_image_index: int, ncx_index: int} $mobiData
     *
     * @return list<EbookResource>
     */
    private function collectResources(\SplFileObject $f, array $records, array $mobiData, string $text, int $fileSize): array
    {
        if (!\preg_match_all('~recindex="(\d+)"~i', $text, $matches)) {
            return [];
        }

        $resources = [];
        $seen = [];
        foreach ($matches[1] as $recindex) {
            if (isset($seen[$recindex])) {
                continue;
            }
            $seen[$recindex] = true;

            $recordIndex = $mobiData['first_image_index'] + (int) $recindex - 1;
            if ($recordIndex <= 0 || !isset($records[$recordIndex])) {
                continue;
            }

            $data = $this->readRecord($f, $records, $recordIndex, $fileSize);

            $font = Font::parse($data);
            if (null !== $font) {
                $resources[] = new EbookResource($recindex, $font, $this->getMimeDetector()->detect($font) ?? 'font/ttf');
                continue;
            }

            $mime = $this->getMimeDetector()->detect($data);
            if (null === $mime) {
                continue;
            }

            $resources[] = new EbookResource($recindex, $data, $mime);
        }

        return $resources;
    }

    /**
     * MOBI text encoding: 65001 = UTF-8, 1252 = Windows-1252, anything else falls back to Latin-1.
     */
    protected function decodeText(string $data, int $encoding): string
    {
        if ('' === $data || 65001 === $encoding) {
            return $data;
        }

        return \mb_convert_encoding($data, 'UTF-8', 1252 === $encoding ? 'Windows-1252' : 'ISO-8859-1');
    }

    private function decodeNullable(?string $data, int $encoding): ?string
    {
        return null === $data ? null : $this->decodeText($data, $encoding);
    }

    /**
     * PalmDOC is self-contained; HUFF/CDIC needs the HUFF + CDIC records read from the PDB.
     *
     * @param list<array{data_offset: int, attributes: int, unique_id: string}>                                                                                                                                                                          $records
     * @param array{full_name_offset: int, full_name_length: int, type: int, extra_record_data_flags: int, text_encoding: int, huffman_record_offset: int, huffman_record_count: int, first_non_book_index: int, first_image_index: int, ncx_index: int} $mobiData
     */
    protected function createDecompressor(\SplFileObject $f, array $records, array $mobiData, int $compression): DecompressorInterface
    {
        return match ($compression) {
            1 => new NoCompression(),
            2 => new PalmDocLz77(),
            17480 => $this->makeHuffCdic($f, $records, $mobiData),
            default => throw new UnsupportedCompressionException(\sprintf('Unsupported MOBI compression: %d', $compression)),
        };
    }

    /**
     * @param list<array{data_offset: int, attributes: int, unique_id: string}>                                                                                                                                                                          $records
     * @param array{full_name_offset: int, full_name_length: int, type: int, extra_record_data_flags: int, text_encoding: int, huffman_record_offset: int, huffman_record_count: int, first_non_book_index: int, first_image_index: int, ncx_index: int} $mobiData
     */
    private function makeHuffCdic(\SplFileObject $f, array $records, array $mobiData): HuffCdic
    {
        $fileSize = \filesize($this->getFile());
        if (false === $fileSize) {
            throw new ParserException();
        }

        $huffRecords = [];
        for ($i = $mobiData['huffman_record_offset'], $count = \count($records); $i < $count; ++$i) {
            $data = $this->readRecord($f, $records, $i, $fileSize);
            if (!$huffRecords) {
                if (!\str_starts_with($data, 'HUFF')) {
                    throw new ParserException();
                }
            } elseif (!\str_starts_with($data, 'CDIC')) {
                break;
            }
            $huffRecords[] = $data;
        }

        if (\count($huffRecords) < 2) {
            throw new ParserException();
        }

        return new HuffCdic($huffRecords);
    }

    /**
     * @param list<array{data_offset: int, attributes: int, unique_id: string}> $records
     */
    protected function readRecord(\SplFileObject $f, array $records, int $index, int $fileSize): string
    {
        if (!isset($records[$index])) {
            throw new ParserException();
        }

        $begin = $records[$index]['data_offset'];
        $end = $records[$index + 1]['data_offset'] ?? $fileSize;
        if ($end < $begin) {
            throw new ParserException();
        }

        $f->fseek($begin);

        return $this->readBytes($f, $end - $begin);
    }

    /**
     * @param list<int> $data
     */
    protected function getRecordExtraSize(array $data, int $flags): int
    {
        $pos = \count($data) - 1;
        $extra = 0;

        $MULTIBYTE_OVERLAP = 0x0001;
        $MAX_TRAILING_BITS = 15;

        for ($i = $MAX_TRAILING_BITS; $i > 0; --$i) {
            if ($flags & (1 << $i)) {
                [$size, $length, $newPos] = $this->bufferGetVarLen($data, $pos);
                $pos = $newPos - ($size - $length);
                $extra += $size;
            }
        }

        if ($flags & $MULTIBYTE_OVERLAP) {
            $a = $data[$pos];
            $extra += ($a & 0x3) + 1;
        }

        return $extra;
    }

    /**
     * @param list<int> $data
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function bufferGetVarLen(array $data, int $pos): array
    {
        $size = 0;
        $byteCount = 0;
        $shift = 0;

        while ($byteCount < 4) {
            $byte = $data[$pos--];
            $size |= ($byte & 0x7F) << $shift;
            $shift += 7;
            ++$byteCount;

            if ($byte & 0x80) {
                break;
            }
        }

        return [$size, $byteCount, $pos];
    }

    /**
     * Reads exactly $length bytes or throws.
     */
    protected function readBytes(\SplFileObject $file, int $length): string
    {
        if ($length < 0) {
            throw new ParserException();
        }
        if (0 === $length) {
            return '';
        }

        $data = '';
        while (\strlen($data) < $length && !$file->eof()) {
            $chunk = $file->fread($length - \strlen($data));
            if (false === $chunk) {
                throw new ParserException();
            }
            if ('' === $chunk) {
                break;
            }
            $data .= $chunk;
        }

        if (\strlen($data) !== $length) {
            throw new ParserException();
        }

        return $data;
    }

    /**
     * Reads a big-endian unsigned integer of $length bytes or throws.
     */
    protected function readInt(\SplFileObject $file, int $length): int
    {
        return (int) \hexdec(\bin2hex($this->readBytes($file, $length)));
    }

    #[\Override]
    public function getCover(): ?Cover
    {
        try {
            $f = new \SplFileObject($this->getFile(), 'r');
        } catch (\Exception $e) {
            throw new ParserException(previous: $e);
        }

        try {
            $palmDbData = $this->parsePalmDb($f);
            $palmDocData = $this->parsePalmDoc($f);
            $this->assertNotEncrypted($palmDocData['encryption']);
            $mobiData = $this->parseMobiHeader($f);
            $exthData = $this->parseExth($f);

            $coverOffset = $exthData['coveroffset'];
            if (null === $coverOffset) {
                return null;
            }

            $fileSize = \filesize($this->getFile());
            if (false === $fileSize) {
                throw new ParserException();
            }

            $recordIndex = $mobiData['first_image_index'] + $coverOffset;
            if ($recordIndex <= 0 || !isset($palmDbData['records'][$recordIndex])) {
                return null;
            }

            $data = $this->readRecord($f, $palmDbData['records'], $recordIndex, $fileSize);
            $mime = $this->getMimeDetector()->detect($data);
            if (null === $mime) {
                return null;
            }

            return new Cover($data, $mime);
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
            $palmDocData = $this->parsePalmDoc($f);
            $this->assertNotEncrypted($palmDocData['encryption']);
            $mobiData = $this->parseMobiHeader($f);

            $indexOffset = $mobiData['ncx_index'];
            if (0xFFFFFFFF === $indexOffset || !isset($palmDbData['records'][$indexOffset])) {
                return [];
            }

            $fileSize = \filesize($this->getFile());
            if (false === $fileSize) {
                throw new ParserException();
            }

            $records = $palmDbData['records'];
            $parsed = Index::parse(
                $indexOffset,
                fn (int $index): string => $this->readRecord($f, $records, $index, $fileSize),
                $mobiData['text_encoding'],
            );
        } finally {
            unset($f); // close file
        }

        $toc = [];
        foreach ($parsed['entries'] as [$name, $tagMap]) {
            $title = isset($tagMap[3][0], $parsed['cncx'][$tagMap[3][0]]) ? $parsed['cncx'][$tagMap[3][0]] : $name;
            $title = \mb_trim($title);
            if ('' === $title) {
                continue;
            }

            $level = isset($tagMap[4][0]) ? $tagMap[4][0] + 1 : 1;
            $toc[] = new TableOfContentsEntry($title, \max(1, $level), 0, null, $tagMap[1][0] ?? null);
        }

        return $toc;
    }

    #[\Override]
    public function getMeta(): Metadata
    {
        try {
            $f = new \SplFileObject($this->getFile(), 'r');
        } catch (\Exception $e) {
            throw new ParserException(previous: $e);
        }

        try {
            $palmDbData = $this->parsePalmDb($f);
            $palmDocData = $this->parsePalmDoc($f);
            $this->assertNotEncrypted($palmDocData['encryption']);
            $mobiData = $this->parseMobiHeader($f);
            $exthData = $this->parseExth($f);

            if (!isset($palmDbData['records'][0])) {
                throw new ParserException();
            }

            $f->fseek($palmDbData['records'][0]['data_offset'] + $mobiData['full_name_offset']);
            $title = $this->decodeText($this->readBytes($f, $mobiData['full_name_length']), $mobiData['text_encoding']);
        } finally {
            unset($f); // close file
        }

        $publishDate = $exthData['publishDate'];
        $encoding = $mobiData['text_encoding'];

        return new Metadata(
            title: $title,
            authors: \array_map(fn (string $value): string => $this->decodeText($value, $encoding), $exthData['authors']),
            publisher: $this->decodeNullable($exthData['publisher'], $encoding),
            isbn: $this->decodeNullable($exthData['isbn'], $encoding),
            description: $this->decodeNullable($exthData['description'], $encoding),
            language: $this->decodeNullable($exthData['language'], $encoding),
            subjects: \array_map(fn (string $value): string => $this->decodeText($value, $encoding), $exthData['subjects']),
            license: $this->decodeNullable($exthData['license'], $encoding),
            publishDate: $publishDate
                ? new PublishDate((int) $publishDate->format('Y'), (int) $publishDate->format('m'), (int) $publishDate->format('d'))
                : new PublishDate(),
        );
    }

    /**
     * @return array{authors: list<string>, subjects: list<string>, publisher: string|null, description: string|null, isbn: string|null, language: string|null, license: string|null, publishDate: \DateTimeInterface|null, coveroffset: int|null, kf8_header: int|null}
     */
    protected function parseExth(\SplFileObject $f): array
    {
        $f->fseek(4, \SEEK_CUR); // EXTH
        $f->fseek(4, \SEEK_CUR); // length
        $records = $this->readInt($f, 4);

        $meta = [
            'authors' => [],
            'subjects' => [],
            'publisher' => null,
            'description' => null,
            'isbn' => null,
            'publishDate' => null,
            'language' => null,
            'license' => null,
            'coveroffset' => null,
            'kf8_header' => null,
        ];
        for ($i = 0; $i < $records; ++$i) {
            $type = $this->readInt($f, 4);
            $length = $this->readInt($f, 4);
            if ($length < 8) {
                throw new ParserException();
            }
            $data = $this->readBytes($f, $length - 8);

            // https://wiki.mobileread.com/wiki/MOBI#EXTH_Header
            switch ($type) {
                case 100:
                    $meta['authors'][] = $data;
                    break;
                case 101:
                    $meta['publisher'] = $data;
                    break;
                case 105:
                    $meta['subjects'][] = $data;
                    break;
                case 103:
                    $meta['description'] = $data;
                    break;
                case 104:
                    $meta['isbn'] = $data;
                    break;
                case 106:
                    try {
                        $meta['publishDate'] = new \DateTimeImmutable($data, new \DateTimeZone('UTC'));
                    } catch (\Throwable) {
                        $meta['publishDate'] = null;
                    }
                    break;
                case 524:
                    $meta['language'] = $data;
                    break;
                case 201: // cover offset: 4-byte big-endian, added to the first image index
                    $unpacked = \unpack('N', \substr($data, 0, 4));
                    $meta['coveroffset'] = false !== $unpacked ? $unpacked[1] : null;
                    break;
                case 121: // record number of the KF8 header in a joint MOBI/KF8 file
                    $unpacked = \unpack('N', \substr($data, 0, 4));
                    $meta['kf8_header'] = false !== $unpacked ? $unpacked[1] : null;
                    break;
            }
        }

        return $meta;
    }

    /**
     * @see https://wiki.mobileread.com/wiki/PDB#Palm_Database_Format
     *
     * @return array{records: list<array{data_offset: int, attributes: int, unique_id: string}>}
     */
    protected function parsePalmDb(\SplFileObject $f): array
    {
        $name = \mb_trim($this->readBytes($f, 32));
        /*
            bit field.
            0x0002 Read-Only
            0x0004 Dirty AppInfoArea
            0x0008 Backup this database (i.e. no conduit exists)
            0x0010 (16 decimal) Okay to install newer over existing copy, if present on PalmPilot
            0x0020 (32 decimal) Force the PalmPilot to reset after this database is installed
            0x0040 (64 decimal) Don't allow copy of file to be beamed to other Pilot.
         */
        $attributes = $this->readInt($f, 2);
        $version = $this->readInt($f, 2);
        $creationDate = $this->readInt($f, 4);
        $modificationDate = $this->readInt($f, 4);
        $lastBackupDate = $this->readInt($f, 4);
        $modificationNumber = $this->readInt($f, 4);
        $appInfoId = $this->readInt($f, 4);
        $sortInfoId = $this->readInt($f, 4);
        $type = $this->readBytes($f, 4);
        $creator = $this->readBytes($f, 4);
        if ('BOOK' !== $type || 'MOBI' !== $creator) {
            throw new ParserException();
        }
        $uniqueIdSeed = $this->readInt($f, 4);
        $nextRecordListId = $this->readInt($f, 4);
        $recordsNumber = $this->readInt($f, 2);

        $records = [];
        for ($i = 0; $i < $recordsNumber; ++$i) {
            $records[] = [
                'data_offset' => $this->readInt($f, 4),
                'attributes' => $this->readInt($f, 1),
                'unique_id' => $this->readBytes($f, 3),
            ];
        }

        $f->fseek(2, \SEEK_CUR);

        return [
            'records' => $records,
        ];
    }

    /**
     * @see https://wiki.mobileread.com/wiki/MOBI#PalmDOC_Header
     *
     * @return array{compression: int, record_count: int, text_length: int, encryption: int}
     */
    protected function parsePalmDoc(\SplFileObject $f): array
    {
        $compression = $this->readInt($f, 2); // 1 == no compression, 2 = PalmDOC compression, 17480 = HUFF/CDIC compression
        $f->fseek(2, \SEEK_CUR);
        $textLength = $this->readInt($f, 4);
        $recordCount = $this->readInt($f, 2);
        $recordSize = $this->readInt($f, 2);
        $encryption = $this->readInt($f, 2); // 0 == none, 1 = old Mobipocket, 2 = Mobipocket
        $f->fseek(2, \SEEK_CUR);

        return [
            'compression' => $compression,
            'record_count' => $recordCount,
            'text_length' => $textLength,
            'encryption' => $encryption,
        ];
    }

    protected function assertNotEncrypted(int $encryption): void
    {
        if (0 !== $encryption) {
            throw new EncryptedBookException(\sprintf('Encrypted MOBI (encryption type %d) is not supported', $encryption));
        }
    }

    /**
     * KF8 (AZW3) shares the MOBI container: the MOBI header version is 8 and a
     * skeleton index (SKEL) record number is present in the extended header.
     *
     * @param array{records: list<array{data_offset: int, attributes: int, unique_id: string}>} $palmDbData
     */
    protected function isKf8(\SplFileObject $f, array $palmDbData): bool
    {
        if (!isset($palmDbData['records'][0])) {
            return false;
        }

        $record0 = $palmDbData['records'][0]['data_offset'];
        $f->fseek($record0 + 36); // MOBI header version
        if (8 !== $this->readInt($f, 4)) {
            return false;
        }

        $f->fseek($record0 + 252); // SKEL index record number

        return 0xFFFFFFFF !== $this->readInt($f, 4);
    }

    /**
     * @see https://wiki.mobileread.com/wiki/MOBI#MOBI_Header
     *
     * @return array{full_name_offset: int, full_name_length: int, type: int, extra_record_data_flags: int, text_encoding: int, huffman_record_offset: int, huffman_record_count: int, first_non_book_index: int, first_image_index: int, ncx_index: int}
     */
    protected function parseMobiHeader(\SplFileObject $f): array
    {
        $mobiHeaderStart = $f->ftell();
        if (false === $mobiHeaderStart) {
            throw new ParserException();
        }
        if ('MOBI' !== $this->readBytes($f, 4)) {
            throw new ParserException();
        }
        $length = $this->readInt($f, 4);
        $type = $this->readInt($f, 4);
        $textEncoding = $this->readInt($f, 4); // 1252 = CP1252; 65001 = UTF-8
        $uniqueId = $this->readInt($f, 4);
        $fileVersion = $this->readInt($f, 4);

        $ortographicIndex = $this->readInt($f, 4);
        $inflectionIndex = $this->readInt($f, 4);
        $indexNames = $this->readInt($f, 4);
        $indexKeys = $this->readInt($f, 4);
        $extraIndex0 = $this->readInt($f, 4);
        $extraIndex1 = $this->readInt($f, 4);
        $extraIndex2 = $this->readInt($f, 4);
        $extraIndex3 = $this->readInt($f, 4);
        $extraIndex4 = $this->readInt($f, 4);
        $extraIndex5 = $this->readInt($f, 4);

        $firstNonBookIndex = $this->readInt($f, 4);
        $fullNameOffset = $this->readInt($f, 4);
        $fullNameLength = $this->readInt($f, 4);
        $locale = $this->readInt($f, 4);
        $inputLanguage = $this->readInt($f, 4);
        $outputLanguage = $this->readInt($f, 4);
        $minVersion = $this->readInt($f, 4);
        $firstImageIndex = $this->readInt($f, 4);
        $huffmanRecordOffset = $this->readInt($f, 4);
        $huffmanRecordCount = $this->readInt($f, 4);
        $huffmanTableOffset = $this->readInt($f, 4);
        $huffmanTableLength = $this->readInt($f, 4);
        $EXTHflags = $this->readInt($f, 4);

        $f->fseek(32, \SEEK_CUR); // 32 unknown bytes, if MOBI is long enough

        $f->fseek(4, \SEEK_CUR); // Unknown | Use 0xFFFFFFFF

        $DRMOffset = $this->readInt($f, 4);
        $DRMCount = $this->readInt($f, 4);
        $DRMSize = $this->readInt($f, 4);
        $DRMFlags = $this->readInt($f, 4);

        $f->fseek(8, \SEEK_CUR); // Bytes to the end of the MOBI header, including the following if the header length >= 228 (244 from start of record). Use 0x0000000000000000.

        $firstContentRecordNumber = $this->readInt($f, 2);
        $lastContentRecordNumber = $this->readInt($f, 2);

        $f->fseek(4, \SEEK_CUR); // Unknown	Use 0x00000001.

        $FCISRecordNumber = $this->readInt($f, 4);
        $FCISRecordCount = $this->readInt($f, 4); // Unknown

        $FLISRecordNumber = $this->readInt($f, 4);
        $FLISRecordCount = $this->readInt($f, 4); // Unknown

        $f->fseek(8, \SEEK_CUR); // Use 0x0000000000000000
        $f->fseek(4, \SEEK_CUR); // Use 0xFFFFFFFF.

        $firstCompilationDataSectionCount = $this->readInt($f, 4);
        $numberOfCompilationDataSections = $this->readInt($f, 4);

        $f->fseek(4, \SEEK_CUR); // Use 0xFFFFFFFF.
        /*
         * A set of binary flags, some of which indicate extra data at the end of each text block.
         * This only seems to be valid for Mobipocket format version 5 and 6 (and higher?), when the
         * header length is 228 (0xE4) or 232 (0xE8).
         *   bit 1 (0x1): <extra multibyte bytes><size>
         *   bit 2 (0x2): <TBS indexing description of this HTML record><size>
         *   bit 3 (0x4): <uncrossable breaks><size>
         * Setting bit 2 (0x2) disables <guide><reference type="start"> functionality.
         */
        $f->fseek(2, \SEEK_CUR); // ?
        $extraRecordDataFlags = $this->readInt($f, 2);

        $INDXRecordOffset = $this->readInt($f, 4); // (If not 0xFFFFFFFF)The record number of the first INDX record created from an ncx file.

        $f->fseek(4, \SEEK_CUR); // 0xFFFFFFFF In new MOBI file, the MOBI header length is 256, skip this to EXTH header.
        $f->fseek(4, \SEEK_CUR); // 0xFFFFFFFF In new MOBI file, the MOBI header length is 256, skip this to EXTH header.
        $f->fseek(4, \SEEK_CUR); // 0xFFFFFFFF In new MOBI file, the MOBI header length is 256, skip this to EXTH header.
        $f->fseek(4, \SEEK_CUR); // 0xFFFFFFFF In new MOBI file, the MOBI header length is 256, skip this to EXTH header.
        $f->fseek(4, \SEEK_CUR); // 0xFFFFFFFF In new MOBI file, the MOBI header length is 256, skip this to EXTH header.
        $f->fseek(4, \SEEK_CUR); // 0 In new MOBI file, the MOBI header length is 256, skip this to EXTH header, MOBI Header length 256, and add 12 bytes from PalmDOC Header so this index is 268.

        $f->fseek($mobiHeaderStart + $length);

        return [
            'full_name_offset' => $fullNameOffset,
            'full_name_length' => $fullNameLength,
            'type' => $type,
            'extra_record_data_flags' => $extraRecordDataFlags,
            'text_encoding' => $textEncoding,
            'huffman_record_offset' => $huffmanRecordOffset,
            'huffman_record_count' => $huffmanRecordCount,
            'first_non_book_index' => $firstNonBookIndex,
            'first_image_index' => $firstImageIndex,
            'ncx_index' => $INDXRecordOffset,
        ];
    }
}
