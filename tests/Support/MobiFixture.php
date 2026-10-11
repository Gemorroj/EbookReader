<?php

declare(strict_types=1);

namespace EbookReader\Tests\Support;

/**
 * Builds a minimal but spec-conformant MOBI (Palm Database) in memory.
 */
final class MobiFixture
{
    /**
     * @param string[] $records records after record 0 (text/HUFF/CDIC/...)
     */
    public static function build(
        string $title,
        int $compression,
        int $encoding,
        int $textLength,
        int $textRecordCount,
        array $records,
        int $huffmanRecordOffset = 0,
        int $huffmanRecordCount = 0,
    ): string {
        $nameOffset = 260; // 16 (PalmDOC) + 232 (MOBI header) + 12 (EXTH)
        $record0 = self::palmDocHeader($compression, $textLength, $textRecordCount)
            .self::mobiHeader($nameOffset, \strlen($title), $encoding, $huffmanRecordOffset, $huffmanRecordCount)
            .'EXTH'.\pack('N', 12).\pack('N', 0)
            .$title;

        return self::buildPdb(\array_merge([$record0], $records));
    }

    /**
     * @param string[] $records
     */
    public static function buildPdb(array $records): string
    {
        $count = \count($records);

        $pdb = \str_pad('TestBook', 32, "\x00");
        $pdb .= \pack('n', 0).\pack('n', 0); // attributes, version
        $pdb .= \str_repeat("\x00", 16);     // dates + modification number
        $pdb .= \pack('N', 0).\pack('N', 0); // appInfoId, sortInfoId
        $pdb .= 'BOOKMOBI';
        $pdb .= \pack('N', 0).\pack('N', 0); // uniqueIdSeed, nextRecordListId
        $pdb .= \pack('n', $count);

        $offset = \strlen($pdb) + $count * 8 + 2;
        $list = '';
        foreach ($records as $index => $record) {
            $list .= \pack('N', $offset)."\x00".\substr(\pack('N', $index), 1);
            $offset += \strlen($record);
        }

        return $pdb.$list."\x00\x00".\implode('', $records);
    }

    private static function palmDocHeader(int $compression, int $textLength, int $textRecordCount): string
    {
        return \pack('n', $compression)
            .\pack('n', 0)
            .\pack('N', $textLength)
            .\pack('n', $textRecordCount)
            .\pack('n', 4096)
            .\pack('N', 0);
    }

    private static function mobiHeader(
        int $nameOffset,
        int $nameLength,
        int $encoding,
        int $huffmanRecordOffset,
        int $huffmanRecordCount,
    ): string {
        $header = \str_repeat("\x00", 232);

        $put = static function (int $relOffset, string $bytes) use (&$header): void {
            $header = \substr_replace($header, $bytes, $relOffset - 16, \strlen($bytes));
        };

        $put(16, 'MOBI');
        $put(20, \pack('N', 232));    // header length
        $put(24, \pack('N', 2));      // mobi type
        $put(28, \pack('N', $encoding));
        $put(80, \pack('N', 4));      // first non-book index
        $put(84, \pack('N', $nameOffset));
        $put(88, \pack('N', $nameLength));
        $put(112, \pack('N', $huffmanRecordOffset));
        $put(116, \pack('N', $huffmanRecordCount));
        $put(128, \pack('N', 0x40));  // EXTH flags
        $put(242, \pack('n', 0));     // extra record data flags

        return $header;
    }
}
