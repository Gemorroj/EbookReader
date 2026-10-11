<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver\Mobi;

use EbookReader\Driver\Mobi\Index;
use EbookReader\Exception\ParserException;
use PHPUnit\Framework\TestCase;

final class IndexTest extends TestCase
{
    public function testParseHeaderInvalid(): void
    {
        $this->expectException(ParserException::class);
        Index::parseHeader('XXXX');
    }

    public function testParseHeader(): void
    {
        $data = \str_pad('INDX', 184, "\x00");
        $data = \substr_replace($data, \pack('N', 240), 4, 4);   // len
        $data = \substr_replace($data, \pack('N', 100), 20, 4);  // start
        $data = \substr_replace($data, \pack('N', 5), 24, 4);    // count
        $data = \substr_replace($data, \pack('N', 65001), 28, 4); // code
        $data = \substr_replace($data, \pack('N', 2), 52, 4);    // nctoc
        $data = \substr_replace($data, \pack('N', 240), 180, 4); // tagx

        $header = Index::parseHeader($data);

        self::assertSame(240, $header['len']);
        self::assertSame(100, $header['start']);
        self::assertSame(5, $header['count']);
        self::assertSame(65001, $header['code']);
        self::assertSame(2, $header['nctoc']);
        self::assertSame(240, $header['tagx']);
    }

    public function testParseWithoutTagxReturnsEmpty(): void
    {
        $meta = \str_pad('INDX', 184, "\x00");

        $result = Index::parse(0, static fn (int $index): string => $meta, 65001);

        self::assertSame(['entries' => [], 'cncx' => []], $result);
    }

    public function testParseEntriesWithTagVariants(): void
    {
        $meta = $this->buildMeta();
        $entry = $this->buildEntry();

        $result = Index::parse(
            0,
            static fn (int $index): string => 0 === $index ? $meta : $entry,
            1252
        );

        self::assertSame([], $result['cncx']);
        self::assertSame(
            [['key', [1 => [5], 3 => [10, 11], 4 => [7]]]],
            $result['entries']
        );
    }

    private function buildMeta(): string
    {
        $header = \str_pad('INDX', 240, "\x00");
        $header = \substr_replace($header, \pack('N', 240), 4, 4);    // len = TAGX start
        $header = \substr_replace($header, \pack('N', 1), 24, 4);     // count = 1 entry record
        $header = \substr_replace($header, \pack('N', 65001), 28, 4);
        $header = \substr_replace($header, \pack('N', 0), 52, 4);     // nctoc
        $header = \substr_replace($header, \pack('N', 240), 180, 4);  // tagx

        // tag, valuesPerEntry, bitmask, endFlag
        $tags = [
            [1, 1, 0x01, 0x00], // single bit
            [2, 1, 0x02, 0x00], // absent
            [3, 1, 0x0C, 0x00], // multi-bit, fully set -> valueBytes path
            [4, 1, 0x30, 0x00], // multi-bit, partial -> shift path
            [9, 1, 0x01, 0x01], // end-of-control-byte marker
        ];
        $firstEntryOffset = 12 + 4 * \count($tags);

        $tagx = 'TAGX'.\pack('N', $firstEntryOffset).\pack('N', 1);
        foreach ($tags as [$tag, $valuesPerEntry, $mask, $endFlag]) {
            $tagx .= \chr($tag).\chr($valuesPerEntry).\chr($mask).\chr($endFlag);
        }

        return $header.$tagx;
    }

    private function buildEntry(): string
    {
        $ident = 'key';
        // tag1 set (0x01), tag3 fully set (0x0C), tag4 partial (0x10)
        $controlByte = 0x01 | 0x0C | 0x10;
        // For a fully-set multi-bit tag the value-bytes count is read while
        // scanning the control bytes, i.e. before the tag values themselves.
        $values = \chr(0x82)              // tag3 valueBytes = 2
            .\chr(0x85)                   // tag1 value = 5
            .\chr(0x8A).\chr(0x8B)        // tag3 values = [10, 11]
            .\chr(0x87);                  // tag4 value = 7

        $entry = \chr(\strlen($ident)).$ident.\chr($controlByte).$values;

        $entryOffset = 184;
        $idxtPos = $entryOffset + \strlen($entry);

        $header = \str_pad('INDX', $entryOffset, "\x00");
        $header = \substr_replace($header, \pack('N', $idxtPos), 20, 4); // start (IDXT)
        $header = \substr_replace($header, \pack('N', 1), 24, 4);        // count
        $header = \substr_replace($header, \pack('N', 65001), 28, 4);

        return $header.$entry.'IDXT'.\pack('n', $entryOffset);
    }
}
