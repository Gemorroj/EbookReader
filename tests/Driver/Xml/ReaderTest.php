<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver\Xml;

use EbookReader\Driver\Xml\Reader;
use EbookReader\Exception\ParserException;
use PHPUnit\Framework\TestCase;

final class ReaderTest extends TestCase
{
    public function testOpensAndExpandsElement(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'xml');
        self::assertNotFalse($file);
        \file_put_contents($file, '<?xml version="1.0"?><root><child id="a">x</child></root>');

        try {
            $reader = Reader::open($file);

            $names = [];
            try {
                while ($reader->read()) {
                    if (\XMLReader::ELEMENT !== $reader->nodeType) {
                        continue;
                    }
                    $names[] = $reader->name;
                    if ('child' === $reader->name) {
                        self::assertSame('a', Reader::expand($reader)->getAttribute('id'));
                    }
                }
            } finally {
                $reader->close();
            }

            self::assertSame(['root', 'child'], $names);
        } finally {
            \unlink($file);
        }
    }

    public function testOpenRejectsUnreadableFile(): void
    {
        $this->expectException(ParserException::class);

        Reader::open(\sys_get_temp_dir().'/missing-'.\uniqid('', true));
    }
}
