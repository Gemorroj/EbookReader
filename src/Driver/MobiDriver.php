<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Data\MobiData;
use EbookReader\Decompressor\PalmDocLz77;
use EbookReader\EbookCoverInterface;
use EbookReader\Exception\ParserException;
use EbookReader\Meta\MobiMeta;
use EbookReader\Meta\PublishDate;

/**
 * @see https://wiki.mobileread.com/wiki/MOBI
 * @see https://github.com/choccybiccy/mobi
 * @see https://github.com/rodzyk/readiverse
 */
final class MobiDriver extends AbstractDriver
{
    public function isValid(): bool
    {
        try {
            $f = new \SplFileObject($this->getFile(), 'r');
            $this->parsePalmDb($f);
            $this->parsePalmDoc($f);
            $this->parseMobiHeader($f);
            $this->parseExth($f);
        } catch (\Throwable $e) {
            return false;
        } finally {
            unset($f); // close file
        }

        return true;
    }

    /**
     * @return MobiData[]
     */
    public function getData(): array
    {
        try {
            $f = new \SplFileObject($this->getFile(), 'r');
        } catch (\Exception $e) {
            throw new ParserException(previous: $e);
        }

        try {
            $palmDbData = $this->parsePalmDb($f);
            $palmDocData = $this->parsePalmDoc($f);
            $mobiData = $this->parseMobiHeader($f);
            $this->parseExth($f);

            if (2 === $palmDocData['compression']) {
                $decompressor = new PalmDocLz77();
            } elseif (17480 === $palmDocData['compression']) {
                throw new ParserException('HUFF/CDIC compression is not supported.');
            } else {
                $decompressor = null;
            }

            if (!isset($palmDbData['records'][0])) {
                throw new ParserException();
            }

            $f->fseek($palmDbData['records'][0]['data_offset'] + $mobiData['full_name_offset']);
            $title = $this->readBytes($f, $mobiData['full_name_length']);

            $text = '';
            for ($i = 1; $i <= $palmDocData['record_count']; ++$i) {
                $flags = $mobiData['extra_record_data_flags'];
                $begin = $palmDbData['records'][$i]['data_offset'] ?? null;
                $end = $palmDbData['records'][$i + 1]['data_offset'] ?? null;

                if (null === $begin || null === $end) {
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
                $text .= $decompressor?->decompress($data);
            }
        } finally {
            unset($f); // close file
        }

        return [
            new MobiData($text, $title, []),
        ];
    }

    /**
     * @param int[] $data
     */
    private function getRecordExtraSize(array $data, int $flags): int
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
     * @param int[] $data
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
    private function readBytes(\SplFileObject $file, int $length): string
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
    private function readInt(\SplFileObject $file, int $length): int
    {
        return (int) \hexdec(\bin2hex($this->readBytes($file, $length)));
    }

    public function getCover(): ?EbookCoverInterface
    {
        // todo
        throw new \RuntimeException('Not implemented');
    }

    public function getMeta(): MobiMeta
    {
        try {
            $f = new \SplFileObject($this->getFile(), 'r');
        } catch (\Exception $e) {
            throw new ParserException(previous: $e);
        }

        try {
            $palmDbData = $this->parsePalmDb($f);
            $this->parsePalmDoc($f);
            $mobiData = $this->parseMobiHeader($f);
            $exthData = $this->parseExth($f);

            if (!isset($palmDbData['records'][0])) {
                throw new ParserException();
            }

            $f->fseek($palmDbData['records'][0]['data_offset'] + $mobiData['full_name_offset']);
            $title = $this->readBytes($f, $mobiData['full_name_length']);
        } finally {
            unset($f); // close file
        }

        $publishDate = $exthData['publishDate'];

        return new MobiMeta(
            $title,
            $exthData['author'],
            $exthData['publisher'],
            $exthData['isbn'],
            $exthData['description'],
            $exthData['language'],
            $exthData['license'],
            $publishDate
                ? new PublishDate((int) $publishDate->format('Y'), (int) $publishDate->format('m'), (int) $publishDate->format('d'))
                : new PublishDate(),
        );
    }

    /**
     * @return array{author: string|null, publisher: string|null, description: string|null, isbn: string|null, language: string|null, license: string|null, publishDate: \DateTimeInterface|null, coveroffset: string|null}
     */
    protected function parseExth(\SplFileObject $f): array
    {
        $f->fseek(4, \SEEK_CUR); // EXTH
        $f->fseek(4, \SEEK_CUR); // length
        $records = $this->readInt($f, 4);

        $meta = [
            'author' => null,
            'publisher' => null,
            'description' => null,
            'isbn' => null,
            'publishDate' => null,
            'language' => null,
            'license' => null,
            'coveroffset' => null,
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
                    $meta['author'] = $data;
                    break;
                case 101:
                    $meta['publisher'] = $data;
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
                case 201:
                    $meta['coveroffset'] = $data;
                    break;
            }
        }

        return $meta;
    }

    /**
     * @see https://wiki.mobileread.com/wiki/PDB#Palm_Database_Format
     *
     * @return array{records: array{data_offset: int, attributes: int, unique_id: string}[]}
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
     * @return array{compression: int, record_count: int}
     */
    protected function parsePalmDoc(\SplFileObject $f): array
    {
        $compression = $this->readInt($f, 2); // 1 == no compression, 2 = PalmDOC compression, 17480 = HUFF/CDIC compression
        $f->fseek(2, \SEEK_CUR);
        $textLength = $this->readInt($f, 4);
        $recordCount = $this->readInt($f, 2);
        $recordSize = $this->readInt($f, 2);
        $f->fseek(4, \SEEK_CUR);

        return [
            'compression' => $compression,
            'record_count' => $recordCount,
        ];
    }

    /**
     * @see https://wiki.mobileread.com/wiki/MOBI#MOBI_Header
     *
     * @return array{full_name_offset: int, full_name_length: int, type: int, extra_record_data_flags: int, text_encoding: int}
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
        ];
    }
}
