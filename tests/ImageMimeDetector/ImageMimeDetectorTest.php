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
        yield [\file_get_contents(__DIR__.'/../fixtures/images/Svg_example1.svg'), 'image/svg+xml'];
        yield [\file_get_contents(__DIR__.'/../fixtures/fake.xml'), null];
        yield [\file_get_contents(__DIR__.'/../fixtures/fake.zip'), null];
        yield ['random string', null];
    }
}
