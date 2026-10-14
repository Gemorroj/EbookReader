<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\Azw3Driver;
use EbookReader\Driver\MobiDriver;
use EbookReader\Format;
use EbookReader\Resource\Resource as EbookResource;
use EbookReader\Section\Azw3Section;
use PHPUnit\Framework\TestCase;

final class Azw3DriverTest extends TestCase
{
    /**
     * Cover image referenced through an inlined SVG flow; CSS flows plus EXTH
     * metadata (publisher/date/subjects) and an NCX table of contents.
     */
    private const string SVG_COVER = __DIR__.'/../fixtures/azw3/svg-cover.azw3';

    /**
     * Direct raster <img src="kindle:embed:..."> references and embedded FONT
     * records referenced from @font-face rules.
     */
    private const string IMAGES_FONTS = __DIR__.'/../fixtures/azw3/images-fonts.azw3';

    /**
     * Joint MOBI6 + KF8 file: the KF8 content lives in the section appended
     * after the BOUNDARY record, and resources are addressed through the shared
     * MOBI6 + KF8 resource pool.
     */
    private const string JOINT = __DIR__.'/../fixtures/azw3/joint-mobi6-kf8.mobi';

    public function testGetFormat(): void
    {
        self::assertSame(Format::AZW3, new Azw3Driver(self::SVG_COVER)->getFormat());
    }

    public function testIsValid(): void
    {
        self::assertTrue(new Azw3Driver(self::SVG_COVER)->isValid());
    }

    public function testIsValidFake(): void
    {
        self::assertFalse(new Azw3Driver(__DIR__.'/../fixtures/fake.xml')->isValid());
        self::assertFalse(new Azw3Driver(__DIR__.'/../fixtures/fake.zip')->isValid());
    }

    public function testIsValidRejectsLegacyMobi(): void
    {
        self::assertFalse(new Azw3Driver(__DIR__.'/../fixtures/mobi/palmodoc-cyrillic.mobi')->isValid());
    }

    public function testMobiDriverDoesNotClaimKf8(): void
    {
        self::assertFalse(new MobiDriver(self::SVG_COVER)->isValid());
    }

    public function testGetMeta(): void
    {
        $meta = new Azw3Driver(self::SVG_COVER)->getMeta();

        self::assertSame('The Yellow Wallpaper', $meta->getTitle());
        self::assertSame(['Charlotte Perkins Gilman'], $meta->getAuthors());
        self::assertSame('en', $meta->getLanguage());
        self::assertSame(
            [
                'Psychological fiction',
                'Mentally ill women -- Fiction',
                'Feminist fiction',
                'Married women -- Psychology -- Fiction',
                'Sex role -- Fiction',
            ],
            $meta->getSubjects()
        );

        $publishDate = $meta->getPublishDate();
        self::assertSame(1999, $publishDate->getYear());
        self::assertSame(11, $publishDate->getMonth());
        self::assertSame(2, $publishDate->getDay());
    }

    public function testGetCover(): void
    {
        $cover = new Azw3Driver(self::SVG_COVER)->getCover();

        self::assertNotNull($cover);
        self::assertSame('image/jpeg', $cover->getMime());
        self::assertNotSame('', $cover->getData());
    }

    public function testGetTableOfContents(): void
    {
        $contents = new Azw3Driver(self::SVG_COVER)->getTableOfContents();

        self::assertCount(2, $contents);
        self::assertSame('The Yellow Wallpaper', $contents[0]->getTitle());
        self::assertSame(1, $contents[0]->getLevel());
        self::assertSame(3239, $contents[0]->getPosition());
        self::assertSame(1, $contents[0]->getSectionIndex());

        self::assertSame('THE FULL PROJECT GUTENBERG™ LICENSE', $contents[1]->getTitle());
        self::assertSame(4, $contents[1]->getSectionIndex());
    }

    public function testGetData(): void
    {
        $data = new Azw3Driver(self::SVG_COVER)->getSections();

        self::assertCount(6, $data);
        foreach ($data as $section) {
            self::assertInstanceOf(Azw3Section::class, $section);
        }

        self::assertSame('"Cover"', $data[0]->getTitle());
        self::assertSame('The Yellow Wallpaper', $data[1]->getTitle());

        // The core value of KF8 parsing: fragments merged back into the skeleton.
        self::assertStringContainsString('John is a physician', $data[2]->getContent());
    }

    public function testInlineImagesAreExtracted(): void
    {
        $data = new Azw3Driver(self::SVG_COVER)->getSections();
        $resources = $data[0]->getResources();

        self::assertCount(1, $resources);
        self::assertSame('0001', $resources[0]->getPath());
        self::assertSame('image/jpeg', $resources[0]->getMime());
        self::assertNotSame('', $resources[0]->getData());

        // The reference in the markup was rewritten to the resource path.
        self::assertStringContainsString($resources[0]->getPath(), $data[0]->getContent());
    }

    public function testFlowsAreResolved(): void
    {
        $data = new Azw3Driver(self::SVG_COVER)->getSections();
        $cover = $data[0]->getContent();

        // CSS flow inlined as a style element, SVG flow inlined as markup.
        self::assertStringContainsString('<style type="text/css">', $cover);
        self::assertStringContainsString('<svg', $cover);

        foreach ($data as $section) {
            $text = $section->getContent();
            self::assertStringNotContainsString('kindle:embed', $text);
            self::assertStringNotContainsString('kindle:flow', $text);
        }
    }

    public function testImagesFontsMeta(): void
    {
        $meta = new Azw3Driver(self::IMAGES_FONTS)->getMeta();

        self::assertSame("Alice's Adventures in Wonderland", $meta->getTitle());
        self::assertSame(['Lewis Carroll'], $meta->getAuthors());
        self::assertSame('en', $meta->getLanguage());
    }

    public function testImagesFontsTableOfContents(): void
    {
        $contents = new Azw3Driver(self::IMAGES_FONTS)->getTableOfContents();

        self::assertCount(12, $contents);
        self::assertSame('I. Down the Rabbit-Hole', $contents[0]->getTitle());
        self::assertSame(1, $contents[0]->getSectionIndex());
    }

    public function testDirectRasterImagesAreExtracted(): void
    {
        $data = new Azw3Driver(self::IMAGES_FONTS)->getSections();

        $images = 0;
        $fonts = 0;
        foreach ($data as $section) {
            foreach ($section->getResources() as $resource) {
                self::assertNotSame('', $resource->getData());
                self::assertStringContainsString($resource->getPath(), $section->getContent());
                if ('image' === $resource->getType()->value) {
                    ++$images;
                }
                if ('font' === $resource->getType()->value) {
                    ++$fonts;
                }
            }
        }

        self::assertSame(24, $images);
        self::assertGreaterThan(0, $fonts);
    }

    public function testEmbeddedFontsAreExtracted(): void
    {
        $data = new Azw3Driver(self::IMAGES_FONTS)->getSections();

        $fonts = [];
        foreach ($data as $section) {
            foreach ($section->getResources() as $resource) {
                if ('font' === $resource->getType()->value) {
                    $fonts[$resource->getPath()] = $resource;
                }
            }
            // FONT records are decoded now, so no dangling kindle:embed references remain.
            self::assertStringNotContainsString('kindle:embed', $section->getContent());
        }

        self::assertNotEmpty($fonts);
        $font = \reset($fonts);
        self::assertInstanceOf(EbookResource::class, $font);
        self::assertStringStartsWith('font/', $font->getMime());
        self::assertNotSame('', $font->getData());
    }

    public function testJointIsValid(): void
    {
        self::assertTrue(new Azw3Driver(self::JOINT)->isValid());
    }

    public function testJointMeta(): void
    {
        $meta = new Azw3Driver(self::JOINT)->getMeta();

        self::assertSame('犬夜叉 第特别卷', $meta->getTitle());
        self::assertSame('ja', $meta->getLanguage());
    }

    public function testJointCover(): void
    {
        $cover = new Azw3Driver(self::JOINT)->getCover();

        self::assertNotNull($cover);
        self::assertSame('image/jpeg', $cover->getMime());
        self::assertNotSame('', $cover->getData());
    }

    public function testJointData(): void
    {
        $data = new Azw3Driver(self::JOINT)->getSections();

        self::assertCount(64, $data);

        $resources = 0;
        foreach ($data as $section) {
            foreach ($section->getResources() as $resource) {
                ++$resources;
                self::assertStringStartsWith('image/', $resource->getMime());
                self::assertStringContainsString($resource->getPath(), $section->getContent());
            }
        }

        self::assertSame(64, $resources);
    }
}
