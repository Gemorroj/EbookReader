<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Data\MobiData;
use EbookReader\Decompressor\DecompressorInterface;
use EbookReader\Decompressor\PalmDocLz77;
use EbookReader\Exception\ParserException;
use EbookReader\Meta\MobiMeta;

/**
 * @see https://wiki.mobileread.com/wiki/MOBI
 * @see https://github.com/choccybiccy/mobi
 */
final class MobiDriver extends AbstractDriver
{
    public function isValid(): bool
    {
        try {
            $f = new \SplFileObject($this->getFile(), 'r');
            $palmDbData = $this->parsePalmDb($f);
            $palmDocData = $this->parsePalmDoc($f);
            $mobiData = $this->parseMobiHeader($f);
            $exthData = $this->parseExth($f);
        } catch (\Exception $e) {
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
            $exthData = $this->parseExth($f);

            /* @var DecompressorInterface|null $decompressor */
            if (2 === $palmDocData['compression']) {
                $decompressor = new PalmDocLz77();
            } elseif (17480 === $palmDocData['compression']) {
                throw new \RuntimeException('HUFF/CDIC compression is not supported.');
            } else {
                $decompressor = null;
            }

            $f->fseek($palmDbData['records'][0]['data_offset'] + $mobiData['full_name_offset']);
            $title = $f->fread($mobiData['full_name_length']);

            $text = '';
            for ($i = 1; $i <= $palmDocData['record_count']; ++$i) {
                $flags = $mobiData['extra_record_data_flags'];
                $begin = $palmDbData['records'][$i]['data_offset'] ?? null;
                $end = $palmDbData['records'][$i + 1]['data_offset'] ?? null;

                if (!$begin || !$end) {
                    continue;
                }

                $f->fseek($begin);
                $preData = \array_values(\unpack('C*', $f->fread($end - $begin)));

                $extraSize = $this->getRecordExtraSize($preData, $flags);
                $f->fseek($begin);
                $data = $f->fread($end - $begin - $extraSize);
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

    public function getCover(): ?string
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
            $palmDocData = $this->parsePalmDoc($f);
            $mobiData = $this->parseMobiHeader($f);
            $exthData = $this->parseExth($f);

            $f->fseek($palmDbData['records'][0]['data_offset'] + $mobiData['full_name_offset']);
            $title = $f->fread($mobiData['full_name_length']);
        } finally {
            unset($f); // close file
        }

        return new MobiMeta(
            $title,
            $exthData['author'],
            $exthData['publisher'],
            $exthData['isbn'],
            $exthData['description'],
            $exthData['language'],
            $exthData['license'],
            $exthData['publishDate'] ? (int) $exthData['publishDate']->format('Y') : null,
            $exthData['publishDate'] ? (int) $exthData['publishDate']->format('m') : null,
            $exthData['publishDate'] ? (int) $exthData['publishDate']->format('d') : null,
        );
    }

    /**
     * @return array{author: string|null, publisher: string|null, description: string|null, isbn: string|null, language: string|null, license: string|null, publishDate: \DateTimeInterface|null}
     */
    protected function parseExth(\SplFileObject $f): array
    {
        $f->fseek(4, \SEEK_CUR); // EXTH
        $f->fseek(4, \SEEK_CUR); // length
        $rawRecords = $f->fread(4);
        if (false === $rawRecords) {
            throw new ParserException();
        }
        $records = (int) \hexdec(\bin2hex($rawRecords));

        $meta = [
            'author' => null,
            'publisher' => null,
            'description' => null,
            'isbn' => null,
            'publishDate' => null,
            'language' => null,
            'license' => null,
        ];
        for ($i = 0; $i < $records; ++$i) {
            $rawType = $f->fread(4);
            if (false === $rawType) {
                throw new ParserException();
            }
            $rawLength = $f->fread(4);
            if (false === $rawLength) {
                throw new ParserException();
            }

            $type = (int) \hexdec(\bin2hex($rawType));
            $length = (int) \hexdec(\bin2hex($rawLength));

            if ($length > 0) {
                $data = $f->fread($length - 8);
                if (false === $data) {
                    throw new ParserException();
                }
            } else {
                $data = null;
            }

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
                    $meta['publishDate'] = new \DateTimeImmutable($data, new \DateTimeZone('UTC'));
                    break;
                case 524:
                    $meta['language'] = $data;
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
        $name = \mb_trim($f->fread(32));
        $attributes = \hexdec(\bin2hex($f->fread(2)));
        $version = \hexdec(\bin2hex($f->fread(2)));
        $creationDate = \hexdec(\bin2hex($f->fread(4)));
        $modificationDate = \hexdec(\bin2hex($f->fread(4)));
        $lastBackupDate = \hexdec(\bin2hex($f->fread(4)));
        $modificationNumber = \hexdec(\bin2hex($f->fread(4)));
        $appInfoId = \hexdec(\bin2hex($f->fread(4)));
        $sortInfoId = \hexdec(\bin2hex($f->fread(4)));
        $type = $f->fread(4);
        $creator = $f->fread(4);
        $uniqueIdSeed = \hexdec(\bin2hex($f->fread(4)));
        $nextRecordListId = \hexdec(\bin2hex($f->fread(4)));
        $recordsNumber = \hexdec(\bin2hex($f->fread(2)));

        $records = [];
        for ($i = 0; $i < $recordsNumber; ++$i) {
            $records[] = [
                'data_offset' => \hexdec(\bin2hex($f->fread(4))),
                'attributes' => \hexdec(\bin2hex($f->fread(1))),
                'unique_id' => $f->fread(3),
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
        $compression = \hexdec(\bin2hex($f->fread(2))); // 1 == no compression, 2 = PalmDOC compression, 17480 = HUFF/CDIC compression
        $f->fseek(2, \SEEK_CUR);
        $textLength = \hexdec(\bin2hex($f->fread(4)));
        $recordCount = \hexdec(\bin2hex($f->fread(2)));
        $recordSize = \hexdec(\bin2hex($f->fread(2)));
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
        if ('MOBI' !== $f->fread(4)) {
            throw new ParserException();
        }
        $length = \hexdec(\bin2hex($f->fread(4)));
        $type = \hexdec(\bin2hex($f->fread(4)));
        $textEncoding = \hexdec(\bin2hex($f->fread(4))); // 1252 = CP1252; 65001 = UTF-8
        $uniqueId = \hexdec(\bin2hex($f->fread(4)));
        $fileVersion = \hexdec(\bin2hex($f->fread(4)));

        $ortographicIndex = \hexdec(\bin2hex($f->fread(4)));
        $inflectionIndex = \hexdec(\bin2hex($f->fread(4)));
        $indexNames = \hexdec(\bin2hex($f->fread(4)));
        $indexKeys = \hexdec(\bin2hex($f->fread(4)));
        $extraIndex0 = \hexdec(\bin2hex($f->fread(4)));
        $extraIndex1 = \hexdec(\bin2hex($f->fread(4)));
        $extraIndex2 = \hexdec(\bin2hex($f->fread(4)));
        $extraIndex3 = \hexdec(\bin2hex($f->fread(4)));
        $extraIndex4 = \hexdec(\bin2hex($f->fread(4)));
        $extraIndex5 = \hexdec(\bin2hex($f->fread(4)));

        $firstNonBookIndex = \hexdec(\bin2hex($f->fread(4)));
        $fullNameOffset = \hexdec(\bin2hex($f->fread(4)));
        $fullNameLength = \hexdec(\bin2hex($f->fread(4)));
        $locale = \hexdec(\bin2hex($f->fread(4)));
        $inputLanguage = \hexdec(\bin2hex($f->fread(4)));
        $outputLanguage = \hexdec(\bin2hex($f->fread(4)));
        $minVersion = \hexdec(\bin2hex($f->fread(4)));
        $firstImageIndex = \hexdec(\bin2hex($f->fread(4)));
        $huffmanRecordOffset = \hexdec(\bin2hex($f->fread(4)));
        $huffmanRecordCount = \hexdec(\bin2hex($f->fread(4)));
        $huffmanTableOffset = \hexdec(\bin2hex($f->fread(4)));
        $huffmanTableLength = \hexdec(\bin2hex($f->fread(4)));
        $EXTHflags = \hexdec(\bin2hex($f->fread(4)));
        // $outputLanguage = \hexdec(\bin2hex($f->fread(4)));

        $f->fseek(32, \SEEK_CUR); // 32 unknown bytes, if MOBI is long enough

        $f->fseek(4, \SEEK_CUR); // Unknown | Use 0xFFFFFFFF

        $DRMOffset = \hexdec(\bin2hex($f->fread(4)));
        $DRMCount = \hexdec(\bin2hex($f->fread(4)));
        $DRMSize = \hexdec(\bin2hex($f->fread(4)));
        $DRMFlags = \hexdec(\bin2hex($f->fread(4)));

        $f->fseek(8, \SEEK_CUR); // Bytes to the end of the MOBI header, including the following if the header length >= 228 (244 from start of record). Use 0x0000000000000000.

        $firstContentRecordNumber = \hexdec(\bin2hex($f->fread(2)));
        $lastContentRecordNumber = \hexdec(\bin2hex($f->fread(2)));

        $f->fseek(4, \SEEK_CUR); // Unknown	Use 0x00000001.

        $FCISRecordNumber = \hexdec(\bin2hex($f->fread(4)));
        $FCISRecordCount = \hexdec(\bin2hex($f->fread(4))); // Unknown

        $FLISRecordNumber = \hexdec(\bin2hex($f->fread(4)));
        $FLISRecordCount = \hexdec(\bin2hex($f->fread(4))); // Unknown

        $f->fseek(8, \SEEK_CUR); // Use 0x0000000000000000
        $f->fseek(4, \SEEK_CUR); // Use 0xFFFFFFFF.

        $firstCompilationDataSectionCount = \hexdec(\bin2hex($f->fread(4)));
        $numberOfCompilationDataSections = \hexdec(\bin2hex($f->fread(4)));

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
        $extraRecordDataFlags = \hexdec(\bin2hex($f->fread(2)));

        $INDXRecordOffset = \hexdec(\bin2hex($f->fread(4))); // (If not 0xFFFFFFFF)The record number of the first INDX record created from an ncx file.

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
