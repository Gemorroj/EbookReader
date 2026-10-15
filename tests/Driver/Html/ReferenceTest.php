<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver\Html;

use EbookReader\Driver\Html\Reference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReferenceTest extends TestCase
{
    #[DataProvider('referenceProvider')]
    public function testIsExternal(string $reference, bool $expected): void
    {
        self::assertSame($expected, Reference::isExternal($reference));
    }

    public static function referenceProvider(): \Generator
    {
        yield 'http' => ['http://example.com/x', true];
        yield 'https' => ['https://example.com/x', true];
        yield 'protocol-relative' => ['//cdn.example.com/x', true];
        yield 'data' => ['data:image/png;base64,AAAA', true];
        yield 'javascript' => ['javascript:alert(1)', true];
        yield 'relative' => ['EPUB/images/x.png', false];
        yield 'fragment' => ['#anchor', false];
        yield 'empty' => ['', false];
    }
}
