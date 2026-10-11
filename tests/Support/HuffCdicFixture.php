<?php

declare(strict_types=1);

namespace EbookReader\Tests\Support;

/**
 * Builds a minimal but spec-conformant HUFF/CDIC MOBI for tests.
 *
 * The Huffman tables below describe a tiny code:
 *   0x41        (8-bit)  -> dictionary[0] = "X"
 *   0x43        (8-bit)  -> dictionary[2] = "(hi)"   (multi-byte phrase)
 *   0x142       (9-bit)  -> dictionary[1] = "Y"      (crosses a byte boundary)
 *   0x42        (8-bit)  -> dictionary[3] = nested HUFF stream "XX"
 */
final class HuffCdicFixture
{
    public const TITLE = 'Huff Test';
    public const TEXT = 'X(hi)YXXX';

    public static function buildMobi(): string
    {
        return MobiFixture::build(
            self::TITLE,
            17480,
            65001,
            \strlen(self::TEXT),
            1,
            [self::compressedText(), self::buildHuff(), self::buildCdic()],
            2,
            1,
        );
    }

    public static function buildHuff(): string
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

    public static function buildCdic(): string
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

    public static function compressedText(): string
    {
        $bits = '01000001'   // 0x41  -> "X"
            .'01000011'      // 0x43  -> "(hi)"
            .'101000010'     // 0x142 -> "Y" (9 bits)
            .'01000010'      // 0x42  -> nested "XX"
            .'01000001';     // 0x41  -> "X"

        return self::packBits($bits);
    }

    private static function packBits(string $bits): string
    {
        $bits = \str_pad($bits, (int) (\ceil(\strlen($bits) / 8) * 8), '0');
        $out = '';
        for ($i = 0, $length = \strlen($bits); $i < $length; $i += 8) {
            $out .= \pack('C', \bindec(\substr($bits, $i, 8)));
        }

        return $out;
    }
}
