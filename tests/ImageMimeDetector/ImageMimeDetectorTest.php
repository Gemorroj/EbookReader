<?php

declare(strict_types=1);

namespace EbookReader\Tests\ImageMimeDetector;

use EbookReader\ImageMimeDetector\ImageMimeDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageMimeDetectorTest extends TestCase
{
    #[DataProvider('imagesProvider')]
    public function testDetect(string $data, ?string $expectedMime): void
    {
        $detector = new ImageMimeDetector();
        self::assertSame($expectedMime, $detector->detect($data));
    }

    public static function imagesProvider(): \Generator
    {
        // Real images (generated, one per supported format)
        yield [\file_get_contents(__DIR__.'/../fixtures/images/jpeg.jpg'), 'image/jpeg'];
        yield [\file_get_contents(__DIR__.'/../fixtures/images/png.png'), 'image/png'];
        yield [\file_get_contents(__DIR__.'/../fixtures/images/gif.gif'), 'image/gif'];
        yield [\file_get_contents(__DIR__.'/../fixtures/images/webp.webp'), 'image/webp'];
        yield [\file_get_contents(__DIR__.'/../fixtures/images/bmp.bmp'), 'image/bmp'];
        yield [\file_get_contents(__DIR__.'/../fixtures/images/tiff.tiff'), 'image/tiff'];
        yield [\file_get_contents(__DIR__.'/../fixtures/images/ico.ico'), 'image/x-icon'];
        yield [\file_get_contents(__DIR__.'/../fixtures/images/svg.svg'), 'image/svg+xml'];

        // Edge cases and negatives
        yield ['<?xml version="1.0" encoding="UTF-8"?>'."\n".'<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'image/svg+xml'];
        yield ["MM\x00\x2A".\str_repeat("\x00", 6), 'image/tiff'];
        yield ['GIF87a'.\str_repeat("\x00", 8), 'image/gif'];
        yield ['GIF89a'.\str_repeat("\x00", 8), 'image/gif'];
        yield ['GI', null];
        yield ['GIF', null];
        yield ['RIFF'.\str_repeat("\x00", 4).'WAVE', null];
        yield ['<?xml version="1.0"?><html></html>', null];
        yield ['random string', null];
        yield ['', null];
        yield ['II', null];
        yield ["\x00\x00\x01", null];
        yield [\file_get_contents(__DIR__.'/../fixtures/fake.xml'), null];
        yield [\file_get_contents(__DIR__.'/../fixtures/fake.zip'), null];
    }
}
