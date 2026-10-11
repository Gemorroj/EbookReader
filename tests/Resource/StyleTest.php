<?php

declare(strict_types=1);

namespace EbookReader\Tests\Resource;

use EbookReader\Resource\Style;
use EbookReader\Resource\StyleType;
use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    public function testLink(): void
    {
        $style = new Style('style.css', StyleType::LINK);

        self::assertSame(StyleType::LINK, $style->getType());
        self::assertSame('style.css', $style->getData());
        self::assertTrue($style->isLink());
        self::assertFalse($style->isCss());
    }

    public function testCss(): void
    {
        $style = new Style('body{}', StyleType::CSS);

        self::assertSame(StyleType::CSS, $style->getType());
        self::assertSame('body{}', $style->getData());
        self::assertTrue($style->isCss());
        self::assertFalse($style->isLink());
    }
}
