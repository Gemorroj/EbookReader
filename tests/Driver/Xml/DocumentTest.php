<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver\Xml;

use EbookReader\Driver\Xml\Document;
use EbookReader\Exception\ParserException;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    public function testParsesDocument(): void
    {
        $dom = Document::parse('<?xml version="1.0"?><root><child>x</child></root>');

        self::assertSame('root', $dom->documentElement?->localName);
        self::assertSame('x', $dom->getElementsByTagName('child')->item(0)?->textContent);
    }

    public function testRejectsMalformedXml(): void
    {
        $this->expectException(ParserException::class);

        Document::parse('<<<not xml>>>');
    }
}
