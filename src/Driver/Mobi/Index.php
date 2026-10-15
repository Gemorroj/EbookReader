<?php

declare(strict_types=1);

namespace EbookReader\Driver\Mobi;

use EbookReader\Exception\ParserException;

/**
 * MOBI INDX index parser (used for the NCX/table of contents).
 *
 * Format reverse engineered by the MobileRead community (see calibre's
 * reader/index.py and KindleUnpack's mobi_index.py).
 */
final class Index
{
    /**
     * @param callable(int): string $loadRecord loads a PDB record by index
     *
     * @return array{entries: list<array{0: string, 1: array<int, list<int>>}>, cncx: array<int, string>}
     */
    public static function parse(int $indexOffset, callable $loadRecord, int $codec): array
    {
        $meta = $loadRecord($indexOffset);
        $header = self::parseHeader($meta);
        $indexCount = $header['count'];
        $nctoc = $header['nctoc'];

        $tagStart = $header['len'];
        if ('TAGX' !== \substr($meta, $tagStart, 4)) {
            if ($header['tagx'] > 0 && 'TAGX' === \substr($meta, $header['tagx'], 4)) {
                $tagStart = $header['tagx'];
            } else {
                $found = \strpos($meta, 'TAGX');
                if (false === $found) {
                    return ['entries' => [], 'cncx' => []];
                }
                $tagStart = $found;
            }
        }
        [$controlByteCount, $tagTable] = self::parseTagx($meta, $tagStart);

        $cncx = [];
        $recordOffset = 0;
        for ($j = 0; $j < $nctoc; ++$j) {
            $cncx += self::parseCncx($loadRecord($indexOffset + $indexCount + 1 + $j), $recordOffset, $codec);
            $recordOffset += 0x10000;
        }

        $entries = [];
        for ($i = $indexOffset + 1; $i <= $indexOffset + $indexCount; ++$i) {
            foreach (self::parseEntries($loadRecord($i), $controlByteCount, $tagTable, $codec) as $entry) {
                $entries[] = $entry;
            }
        }

        return ['entries' => $entries, 'cncx' => $cncx];
    }

    /**
     * @return array{len: int, start: int, count: int, code: int, tagx: int, nctoc: int}
     */
    public static function parseHeader(string $data): array
    {
        if (!\str_starts_with($data, 'INDX')) {
            throw new ParserException();
        }

        return [
            'len' => self::uint32($data, 4),
            'start' => self::uint32($data, 20),
            'count' => self::uint32($data, 24),
            'code' => self::uint32($data, 28),
            'nctoc' => self::uint32($data, 52),
            'tagx' => self::uint32($data, 180),
        ];
    }

    /**
     * @return array{0: int, 1: list<array{0: int, 1: int, 2: int, 3: int}>}
     */
    private static function parseTagx(string $data, int $start): array
    {
        if ('TAGX' !== \substr($data, $start, 4)) {
            return [0, []];
        }

        $firstEntryOffset = self::uint32($data, $start + 4);
        $controlByteCount = self::uint32($data, $start + 8);

        $tags = [];
        for ($i = $start + 12; $i + 4 <= $start + $firstEntryOffset; $i += 4) {
            $tags[] = [\ord($data[$i]), \ord($data[$i + 1]), \ord($data[$i + 2]), \ord($data[$i + 3])];
        }

        return [$controlByteCount, $tags];
    }

    /**
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $tagTable
     *
     * @return list<array{0: string, 1: array<int, list<int>>}>
     */
    private static function parseEntries(string $record, int $controlByteCount, array $tagTable, int $codec): array
    {
        $header = self::parseHeader($record);
        $idxt = $header['start'];
        $count = $header['count'];

        $recordLength = \strlen($record);

        $entries = [];
        for ($j = 0; $j < $count; ++$j) {
            $startPos = self::uint16($record, $idxt + 4 + 2 * $j);
            $textLength = $startPos < $recordLength ? \ord($record[$startPos]) : 0;
            $ident = self::decode(\substr($record, $startPos + 1, $textLength), $codec);
            $entries[] = [$ident, self::getTagMap($controlByteCount, $tagTable, $record, $startPos + 1 + $textLength)];
        }

        return $entries;
    }

    /**
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $tagTable
     *
     * @return array<int, list<int>>
     */
    private static function getTagMap(int $controlByteCount, array $tagTable, string $data, int $startPos): array
    {
        $length = \strlen($data);
        $pending = [];
        $controlByteIndex = 0;
        $dataStart = $startPos + $controlByteCount;

        foreach ($tagTable as [$tag, $valuesPerEntry, $mask, $endFlag]) {
            if (0x01 === $endFlag) {
                ++$controlByteIndex;
                continue;
            }

            $controlPos = $startPos + $controlByteIndex;
            $value = ($controlPos < $length ? \ord($data[$controlPos]) : 0) & $mask;
            if (0 === $value) {
                continue;
            }

            if ($value === $mask) {
                if (self::countBits($mask) > 1) {
                    $pending[] = [$tag, null, self::readVwi($data, $dataStart), $valuesPerEntry];
                } else {
                    $pending[] = [$tag, 1, null, $valuesPerEntry];
                }
            } else {
                while (0 === ($mask & 1)) {
                    $mask >>= 1;
                    $value >>= 1;
                }
                $pending[] = [$tag, $value, null, $valuesPerEntry];
            }
        }

        $map = [];
        foreach ($pending as [$tag, $valueCount, $valueBytes, $valuesPerEntry]) {
            $values = [];
            if (null !== $valueCount) {
                for ($k = 0; $k < $valueCount * $valuesPerEntry; ++$k) {
                    $values[] = self::readVwi($data, $dataStart);
                }
            } else {
                $consumed = 0;
                while ($consumed < $valueBytes && $dataStart < $length) {
                    $before = $dataStart;
                    $values[] = self::readVwi($data, $dataStart);
                    $consumed += $dataStart - $before;
                }
            }
            $map[$tag] = $values;
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private static function parseCncx(string $record, int $recordOffset, int $codec): array
    {
        $map = [];
        $offset = 0;
        $length = \strlen($record);

        while ($offset < $length && "\x00" !== $record[$offset]) {
            $key = $offset + $recordOffset;
            $size = self::readVwi($record, $offset);
            $map[$key] = self::decode(\substr($record, $offset, $size), $codec);
            $offset += $size;
        }

        return $map;
    }

    /**
     * Reads a MOBI variable-width integer (7 bits per byte, MSB terminates).
     */
    private static function readVwi(string $data, int &$offset): int
    {
        $value = 0;
        $length = \strlen($data);

        while (true) {
            if ($offset >= $length) {
                break;
            }
            $byte = \ord($data[$offset]);
            ++$offset;
            $value = ($value << 7) | ($byte & 0x7F);
            if (0 !== ($byte & 0x80)) {
                break;
            }
        }

        return $value;
    }

    private static function decode(string $data, int $codec): string
    {
        if ('' === $data || 65001 === $codec) {
            return $data;
        }

        return \mb_convert_encoding($data, 'UTF-8', 1252 === $codec ? 'Windows-1252' : 'ISO-8859-1');
    }

    private static function countBits(int $value): int
    {
        $count = 0;
        while ($value > 0) {
            $count += $value & 1;
            $value >>= 1;
        }

        return $count;
    }

    private static function uint16(string $data, int $offset): int
    {
        $value = \unpack('n', \substr($data, $offset, 2));
        if (false === $value) {
            throw new ParserException();
        }

        return $value[1];
    }

    private static function uint32(string $data, int $offset): int
    {
        $value = \unpack('N', \substr($data, $offset, 4));
        if (false === $value) {
            throw new ParserException();
        }

        return $value[1];
    }
}
