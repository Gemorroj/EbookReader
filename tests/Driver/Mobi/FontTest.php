<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver\Mobi;

use EbookReader\Driver\Mobi\Font;
use PHPUnit\Framework\TestCase;

final class FontTest extends TestCase
{
    public function testPlainFontRecord(): void
    {
        $font = 'OTTO'.\str_repeat("\x11", 100);

        self::assertSame($font, Font::parse(self::build($font, false, false)));
    }

    public function testCompressedFontRecord(): void
    {
        $font = "\x00\x01\x00\x00".\str_repeat('font-data-', 200);

        self::assertSame($font, Font::parse(self::build($font, true, false)));
    }

    public function testObfuscatedFontRecord(): void
    {
        $font = \str_repeat("\xAB\xCD", 1200);

        self::assertSame($font, Font::parse(self::build($font, false, true)));
    }

    public function testObfuscatedAndCompressedFontRecord(): void
    {
        // Incompressible data so the compressed payload stays above the 1040-byte
        // obfuscation window, exercising both flags together (the kindlegen case).
        $font = \random_bytes(4096);

        self::assertSame($font, Font::parse(self::build($font, true, true)));
    }

    public function testRejectsNonFontRecord(): void
    {
        self::assertNull(Font::parse('not a font record at all'));
        self::assertNull(Font::parse('FONT'));
    }

    public function testRejectsCorruptCompressedRecord(): void
    {
        $record = self::build('OTTO'.\str_repeat("\x00", 32), true, false);
        $record = \substr_replace($record, 'garbage', 24, 7); // break the zlib payload

        self::assertNull(Font::parse($record));
    }

    private static function build(string $font, bool $compress, bool $obfuscate): string
    {
        $data = $compress ? (string) \gzcompress($font, 9) : $font;

        $key = '';
        if ($obfuscate && \strlen($data) >= 1040) {
            $key = \random_bytes(20);
            for ($i = 0; $i < 1040; ++$i) {
                $data[$i] = \chr(\ord($data[$i]) ^ \ord($key[$i % 20]));
            }
        }

        $flags = ($compress ? 0x1 : 0) | ('' !== $key ? 0x2 : 0);
        $keyStart = 24;
        $dataStart = $keyStart + \strlen($key);

        return 'FONT'.\pack('N5', \strlen($font), $flags, $dataStart, \strlen($key), $keyStart).$key.$data;
    }
}
