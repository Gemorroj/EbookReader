<?php

declare(strict_types=1);

namespace EbookReader\Tests\ImageMimeDetector;

use EbookReader\ImageMimeDetector\ImageMimeDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageMimeDetectorTest extends TestCase
{
    #[DataProvider('imagesProvider')]
    public function testIsValid(string $data, ?string $expectedMime): void
    {
        $detector = new ImageMimeDetector();
        $mime = $detector->detect($data);
        self::assertSame($expectedMime, $mime);
    }

    public static function imagesProvider(): \Generator
    {
        yield [\file_get_contents(__DIR__.'/../fixtures/images/600-kb.jpg'), 'image/jpeg'];
        yield [\file_get_contents(__DIR__.'/../fixtures/images/dummy-500-kb-example-png-file.png'), 'image/png'];
        yield [\file_get_contents(__DIR__.'/../fixtures/images/sample_640×426.gif'), 'image/gif'];
        yield ['GIF87a'.\str_repeat("\x00", 8), 'image/gif'];
        yield ['GIF89a'.\str_repeat("\x00", 8), 'image/gif'];
        yield ['GI', null];
        yield ['GIF', null];
        yield [\file_get_contents(__DIR__.'/../fixtures/images/Svg_example1.svg'), 'image/svg+xml'];
        yield ['<?xml version="1.0" encoding="UTF-8"?>'."\n".'<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'image/svg+xml'];
        yield [\file_get_contents(__DIR__.'/../fixtures/fake.xml'), null];
        yield [\file_get_contents(__DIR__.'/../fixtures/fake.zip'), null];
        yield ['random string', null];
        yield ['', null];
        yield ['<?xml version="1.0"?><html></html>', null];
        yield ['RIFF'.\str_repeat("\x00", 4).'WEBPVP8 ', 'image/webp'];
        yield ['RIFF'.\str_repeat("\x00", 4).'WAVE', null];
        yield ['BM'.\str_repeat("\x00", 10), 'image/bmp'];
        yield ["II\x2A\x00".\str_repeat("\x00", 4), 'image/tiff'];
        yield ["MM\x00\x2A".\str_repeat("\x00", 4), 'image/tiff'];
        yield ["\x00\x00\x01\x00".\str_repeat("\x00", 4), 'image/x-icon'];
        yield ['II', null];
        yield ["\x00\x00\x01", null];
    }
}
