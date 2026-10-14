<?php

declare(strict_types=1);

namespace EbookReader\Tests;

use EbookReader\Driver\Azw3Driver;
use EbookReader\Driver\Epub3Driver;
use EbookReader\Driver\Fb2Driver;
use EbookReader\Driver\MobiDriver;
use EbookReader\Driver\TxtDriver;
use EbookReader\EbookReaderFactory;
use EbookReader\Exception\FileNotReadableException;
use EbookReader\Exception\UnsupportedFormatException;
use EbookReader\Format;
use EbookReader\Text\TextDecoder;
use PHPUnit\Framework\TestCase;

final class EbookReaderFactoryTest extends TestCase
{
    public function testCreateFb2(): void
    {
        $fb2 = EbookReaderFactory::create(__DIR__.'/fixtures/fb2/fb2-cyrillic.fb2');
        self::assertInstanceOf(Fb2Driver::class, $fb2);
    }

    public function testCreateFb2Zip(): void
    {
        $fb2 = EbookReaderFactory::create(__DIR__.'/fixtures/fb2/fb2-epigraph.zip');
        self::assertInstanceOf(Fb2Driver::class, $fb2);
    }

    public function testCreateEpub3(): void
    {
        $epub3 = EbookReaderFactory::create(__DIR__.'/fixtures/epub/epub3-nav.epub');
        self::assertInstanceOf(Epub3Driver::class, $epub3);
        $epub2 = EbookReaderFactory::create(__DIR__.'/fixtures/epub/epub2-ncx-cyrillic.epub');
        self::assertInstanceOf(Epub3Driver::class, $epub2);
    }

    public function testCreateMobi(): void
    {
        $mobi = EbookReaderFactory::create(__DIR__.'/fixtures/mobi/palmodoc-cyrillic.mobi');
        self::assertInstanceOf(MobiDriver::class, $mobi);
    }

    public function testCreateAzw3(): void
    {
        $azw3 = EbookReaderFactory::create(__DIR__.'/fixtures/azw3/svg-cover.azw3');
        self::assertInstanceOf(Azw3Driver::class, $azw3);
        self::assertSame(Format::AZW3, $azw3->getFormat());
    }

    public function testCreateAzw3Joint(): void
    {
        $joint = EbookReaderFactory::create(__DIR__.'/fixtures/azw3/joint-mobi6-kf8.mobi');
        self::assertInstanceOf(Azw3Driver::class, $joint);
    }

    public function testCreatePrc(): void
    {
        // Mobipocket ".prc" is a plain MOBI container. Detection is by content,
        // so the extension does not matter: the same bytes under ".prc" are read.
        $file = \tempnam(\sys_get_temp_dir(), 'prc');
        self::assertNotFalse($file);
        $prc = $file.'.prc';
        \rename($file, $prc);
        \copy(__DIR__.'/fixtures/mobi/palmodoc-cyrillic.mobi', $prc);

        try {
            $reader = EbookReaderFactory::create($prc);
            self::assertInstanceOf(MobiDriver::class, $reader);
            self::assertSame(Format::MOBI, $reader->getFormat());
            self::assertSame('Во весь голос. Стихотворения и поэмы', $reader->getMeta()->getTitle());
        } finally {
            \unlink($prc);
        }
    }

    public function testCreateTxt(): void
    {
        $txt = EbookReaderFactory::create(__DIR__.'/fixtures/txt/txt-utf8.txt');
        self::assertInstanceOf(TxtDriver::class, $txt);
    }

    public function testTextDecoderIsConfigurable(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'txt');
        self::assertNotFalse($file);
        \file_put_contents($file, "Caf\xE9"); // "Café" in Windows-1252

        try {
            try {
                EbookReaderFactory::create($file);
                self::fail('Non-UTF-8 text must not be detected by default');
            } catch (UnsupportedFormatException) {
            }

            $reader = EbookReaderFactory::create($file, textDecoder: TextDecoder::fallback('Windows-1252'));
            self::assertInstanceOf(TxtDriver::class, $reader);
            self::assertSame("Caf\xC3\xA9", $reader->getSections()[0]->getContent());
        } finally {
            \unlink($file);
        }
    }

    public function testCreateFake(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        EbookReaderFactory::create(__DIR__.'/fixtures/fake.zip');
    }

    public function testCreateNotReadable(): void
    {
        $this->expectException(FileNotReadableException::class);
        EbookReaderFactory::create(__DIR__.'/fixtures/does-not-exist.epub');
    }
}
