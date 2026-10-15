<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver\Xml;

use EbookReader\Driver\Xml\Document;
use EbookReader\Driver\Xml\Element;
use PHPUnit\Framework\TestCase;

final class ElementTest extends TestCase
{
    public function testAttribute(): void
    {
        $root = Document::parse('<root id="1"/>')->documentElement;
        self::assertNotNull($root);

        self::assertSame('1', Element::attribute($root, 'id'));
        self::assertSame('', Element::attribute($root, 'missing'));
    }

    public function testFirstSearchesDescendantsFromADocument(): void
    {
        $dom = Document::parse('<root><a/><b><c id="x"/></b></root>');

        self::assertSame('b', Element::first($dom, 'b')?->localName);

        $c = Element::first($dom, 'c');
        self::assertNotNull($c);
        self::assertSame('x', Element::attribute($c, 'id'));
    }

    public function testFirstReturnsNullWhenAbsent(): void
    {
        $dom = Document::parse('<root><a/></root>');

        self::assertNull(Element::first($dom, 'missing'));
    }
}
