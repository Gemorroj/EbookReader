<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver\Html;

use EbookReader\Driver\Html\Title;
use PHPUnit\Framework\TestCase;

final class TitleTest extends TestCase
{
    public function testExtractsAndDecodesTitle(): void
    {
        self::assertSame('A & B', Title::fromHtml('<html><head><title> A &amp; B </title></head></html>'));
        self::assertSame('X', Title::fromHtml('<title id="t">X</title>'));
    }

    public function testWithoutTitle(): void
    {
        self::assertNull(Title::fromHtml('<html><body>x</body></html>'));
        self::assertNull(Title::fromHtml('<title>   </title>'));
    }
}
