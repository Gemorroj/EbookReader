<?php

namespace EbookReader\Tests;

use EbookReader\Driver\Epub3Driver;
use EbookReader\Driver\Fb2Driver;
use EbookReader\EbookReaderFactory;
use EbookReader\Exception\UnsupportedFormatException;
use PHPUnit\Framework\TestCase;

class EbookReaderFactoryTest extends TestCase
{
    public function testCreateFb2(): void
    {
        $fb2 = EbookReaderFactory::create(__DIR__.'/fixtures/fb2/fb2.fb2');
        self::assertInstanceOf(Fb2Driver::class, $fb2);
    }

    public function testCreateEpub3(): void
    {
        $epub3 = EbookReaderFactory::create(__DIR__.'/fixtures/epub/epub3.epub');
        self::assertInstanceOf(Epub3Driver::class, $epub3);
    }

    public function testCreateFake(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        EbookReaderFactory::create(__DIR__.'/fixtures/fake.zip');
    }
}