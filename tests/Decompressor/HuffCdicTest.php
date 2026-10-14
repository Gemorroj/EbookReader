<?php

declare(strict_types=1);

namespace EbookReader\Tests\Decompressor;

use EbookReader\Decompressor\HuffCdic;
use EbookReader\Exception\ParserException;
use PHPUnit\Framework\TestCase;

/**
 * The HUFF and CDIC records below (and the matching compressed text) describe a
 * tiny code, so the decompressor can be exercised on exact bytes and on
 * deliberately corrupt input:
 *   0x41        (8-bit)  -> dictionary[0] = "X"
 *   0x43        (8-bit)  -> dictionary[2] = "(hi)"   (multi-byte phrase)
 *   0x142       (9-bit)  -> dictionary[1] = "Y"      (crosses a byte boundary)
 *   0x42        (8-bit)  -> dictionary[3] = nested HUFF stream "XX".
 */
final class HuffCdicTest extends TestCase
{
    private const TEXT = 'X(hi)YXXX';

    public function testDecompress(): void
    {
        $huffCdic = new HuffCdic([self::buildHuff(), self::buildCdic()]);

        self::assertSame(self::TEXT, $huffCdic->decompress(self::compressedText()));
    }

    public function testDecompressEmpty(): void
    {
        $huffCdic = new HuffCdic([self::buildHuff(), self::buildCdic()]);

        self::assertSame('', $huffCdic->decompress(''));
    }

    public function testEmptyRecords(): void
    {
        $this->expectException(ParserException::class);
        new HuffCdic([]);
    }

    public function testInvalidHuffHeader(): void
    {
        $this->expectException(ParserException::class);
        new HuffCdic(['not a huff record']);
    }

    public function testInvalidCdicHeader(): void
    {
        $this->expectException(ParserException::class);
        new HuffCdic([self::buildHuff(), 'not a cdic record']);
    }

    public function testInvalidHuffCodeLength(): void
    {
        // dict1[0] with a zero code length is rejected
        $huff = \substr_replace(self::buildHuff(), \pack('N', 0), 24, 4);

        $this->expectException(ParserException::class);
        new HuffCdic([$huff, self::buildCdic()]);
    }

    public function testDictionaryIndexOutOfRange(): void
    {
        // make code 0x41 resolve to dictionary index 100, but only 4 entries exist
        $huff = \substr_replace(
            self::buildHuff(),
            \pack('N', 0x88 | ((100 + 0x41) << 8)),
            24 + 0x41 * 4,
            4
        );
        $huffCdic = new HuffCdic([$huff, self::buildCdic()]);

        $this->expectException(ParserException::class);
        $huffCdic->decompress(self::compressedText());
    }

    private static function buildHuff(): string
    {
        $dict1 = \array_fill(0, 256, 0x88); // default: 8-bit terminal code
        $dict1[0x41] = 0x88 | (0x41 << 8);  // r = 0
        $dict1[0x42] = 0x88 | (0x45 << 8);  // r = 3
        $dict1[0x43] = 0x88 | (0x45 << 8);  // r = 2
        $dict1[0xA1] = 0x09;                // non-terminal, 9-bit codes

        $dict2 = \array_fill(0, 64, 0);
        $dict2[16] = 0;   // min raw for length 9
        $dict2[17] = 323; // max raw: r = 323 - 0x142 = 1

        $huff = "HUFF\x00\x00\x00\x18";
        $huff .= \pack('N', 24);
        $huff .= \pack('N', 1048);
        $huff .= \str_repeat("\x00", 8);
        foreach ($dict1 as $value) {
            $huff .= \pack('N', $value);
        }
        foreach ($dict2 as $value) {
            $huff .= \pack('N', $value);
        }

        return $huff;
    }

    private static function buildCdic(): string
    {
        /** @var array<array{0: int, 1: string}> $entries */
        $entries = [
            [0x8001, 'X'],
            [0x8001, 'Y'],
            [0x8004, '(hi)'],
            [0x0002, "\x41\x41"], // non-terminal: itself HUFF-compressed
        ];

        $offsets = '';
        $body = '';
        $off = \count($entries) * 2;
        foreach ($entries as [$blen, $data]) {
            $offsets .= \pack('n', $off);
            $body .= \pack('n', $blen).$data;
            $off += 2 + \strlen($data);
        }

        return "CDIC\x00\x00\x00\x10"
            .\pack('N', \count($entries))
            .\pack('N', 2)
            .$offsets
            .$body;
    }

    private static function compressedText(): string
    {
        $bits = '01000001'   // 0x41  -> "X"
            .'01000011'      // 0x43  -> "(hi)"
            .'101000010'     // 0x142 -> "Y" (9 bits)
            .'01000010'      // 0x42  -> nested "XX"
            .'01000001';     // 0x41  -> "X"

        $bits = \str_pad($bits, (int) (\ceil(\strlen($bits) / 8) * 8), '0');
        $out = '';
        for ($i = 0, $length = \strlen($bits); $i < $length; $i += 8) {
            $out .= \pack('C', \bindec(\substr($bits, $i, 8)));
        }

        return $out;
    }
}
