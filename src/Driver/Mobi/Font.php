<?php

declare(strict_types=1);

namespace EbookReader\Driver\Mobi;

/**
 * Parses a MOBI/KF8 FONT record into the embedded font file.
 *
 * Record layout (big-endian): "FONT", uncompressed size, flags (bit 1 = zlib
 * compressed, bit 2 = XOR obfuscated), data offset, XOR key length, XOR key
 * offset. KindleGen obfuscates and compresses the first 1040 bytes of the font.
 *
 * @see https://wiki.mobileread.com/wiki/MOBI#FONT_Records
 */
final class Font
{
    private function __construct()
    {
    }

    /**
     * @return string|null the decoded font file, or null when the data is not a
     *                     FONT record or cannot be decoded
     */
    public static function parse(string $record): ?string
    {
        if (!\str_starts_with($record, 'FONT') || \strlen($record) < 24) {
            return null;
        }

        $header = \unpack('N5', \substr($record, 4, 20));
        if (false === $header) {
            return null;
        }
        $flags = $header[2];
        $dataOffset = $header[3];
        $xorLength = $header[4];
        $xorOffset = $header[5];

        $data = \substr($record, $dataOffset);

        if (0 !== ($flags & 0x2) && $xorLength > 0) {
            $key = \substr($record, $xorOffset, $xorLength);
            if ('' === $key || \strlen($key) !== $xorLength) {
                return null;
            }
            $extent = \min(1040, \strlen($data));
            for ($i = 0; $i < $extent; ++$i) {
                $data[$i] = \chr(\ord($data[$i]) ^ \ord($key[$i % $xorLength]));
            }
        }

        if (0 !== ($flags & 0x1)) {
            $inflated = @\gzuncompress($data);
            if (false === $inflated) {
                return null;
            }
            $data = $inflated;
        }

        return '' === $data ? null : $data;
    }
}
