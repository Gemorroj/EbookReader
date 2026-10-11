<?php

declare(strict_types=1);

namespace EbookReader\Tests\Decompressor;

use EbookReader\Decompressor\HuffCdic;
use EbookReader\Exception\ParserException;
use EbookReader\Tests\Support\HuffCdicFixture;
use PHPUnit\Framework\TestCase;

final class HuffCdicTest extends TestCase
{
    public function testDecompress(): void
    {
        $huffCdic = new HuffCdic([HuffCdicFixture::buildHuff(), HuffCdicFixture::buildCdic()]);

        self::assertSame(HuffCdicFixture::TEXT, $huffCdic->decompress(HuffCdicFixture::compressedText()));
    }

    public function testDecompressEmpty(): void
    {
        $huffCdic = new HuffCdic([HuffCdicFixture::buildHuff(), HuffCdicFixture::buildCdic()]);

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
        new HuffCdic([HuffCdicFixture::buildHuff(), 'not a cdic record']);
    }

    public function testInvalidHuffCodeLength(): void
    {
        // dict1[0] with a zero code length is rejected
        $huff = \substr_replace(HuffCdicFixture::buildHuff(), \pack('N', 0), 24, 4);

        $this->expectException(ParserException::class);
        new HuffCdic([$huff, HuffCdicFixture::buildCdic()]);
    }

    public function testDictionaryIndexOutOfRange(): void
    {
        // make code 0x41 resolve to dictionary index 100, but only 4 entries exist
        $huff = \substr_replace(
            HuffCdicFixture::buildHuff(),
            \pack('N', 0x88 | ((100 + 0x41) << 8)),
            24 + 0x41 * 4,
            4
        );
        $huffCdic = new HuffCdic([$huff, HuffCdicFixture::buildCdic()]);

        $this->expectException(ParserException::class);
        $huffCdic->decompress(HuffCdicFixture::compressedText());
    }
}
