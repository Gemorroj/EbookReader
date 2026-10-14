<?php

declare(strict_types=1);

namespace EbookReader\Tests\Text;

use EbookReader\Exception\ParserException;
use EbookReader\Text\TextDecoder;
use PHPUnit\Framework\TestCase;

final class TextDecoderTest extends TestCase
{
    public function testEmptyString(): void
    {
        self::assertSame('', new TextDecoder()->toUtf8(''));
    }

    public function testKeepsValidUtf8(): void
    {
        self::assertSame('Привет мир', new TextDecoder()->toUtf8('Привет мир'));
    }

    public function testDeclaredCharset(): void
    {
        // "Café" in Windows-1252
        self::assertSame("Caf\xC3\xA9", new TextDecoder()->toUtf8("Caf\xE9", 'Windows-1252'));
    }

    public function testUtf8BomIsStripped(): void
    {
        self::assertSame('Hello', new TextDecoder()->toUtf8("\xEF\xBB\xBFHello"));
    }

    public function testUtf16BomIsDecoded(): void
    {
        $utf16 = \mb_convert_encoding('Hi', 'UTF-16LE', 'UTF-8');

        self::assertSame('Hi', new TextDecoder()->toUtf8("\xFF\xFE".$utf16));
    }

    public function testStrictThrowsOnUnknownEncoding(): void
    {
        $this->expectException(ParserException::class);

        new TextDecoder()->toUtf8("Caf\xE9");
    }

    public function testFallbackEncoding(): void
    {
        self::assertSame("Caf\xC3\xA9", TextDecoder::fallback('Windows-1252')->toUtf8("Caf\xE9"));
    }

    public function testCallback(): void
    {
        $decoder = TextDecoder::callback(static fn (string $bytes): string => 'decoded');

        self::assertSame('decoded', $decoder->toUtf8("\xE9"));
    }

    public function testDetectWithSingleCandidate(): void
    {
        $cp1251 = \mb_convert_encoding('Привет', 'Windows-1251', 'UTF-8');

        self::assertSame('Привет', TextDecoder::detect(['Windows-1251'])->toUtf8($cp1251));
    }

    public function testDetectDefaultProducesUtf8(): void
    {
        $cp1251 = \mb_convert_encoding('Привет мир', 'Windows-1251', 'UTF-8');
        $decoded = TextDecoder::detect()->toUtf8($cp1251);

        self::assertTrue(\mb_check_encoding($decoded, 'UTF-8'));
        self::assertSame(1, \preg_match('/\p{Cyrillic}/u', $decoded));
    }

    public function testDetectThrowsWhenImplausible(): void
    {
        $this->expectException(ParserException::class);

        TextDecoder::detect(['ISO-8859-1'])->toUtf8("\x80\x81\x82\x83\x84\x85");
    }

    public function testUnknownFallbackEncodingIsRejected(): void
    {
        $this->expectException(ParserException::class);

        TextDecoder::fallback('no-such-encoding');
    }

    public function testUnknownDetectCandidateIsRejected(): void
    {
        $this->expectException(ParserException::class);

        TextDecoder::detect(['no-such-encoding']);
    }
}
