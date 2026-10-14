<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\Epub3Driver;
use EbookReader\Exception\ParserException;
use EbookReader\Exception\UnsupportedFormatException;
use EbookReader\Format;
use EbookReader\Resource\EbookResourceInterface;
use EbookReader\Section\Epub3Section;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Epub3DriverTest extends TestCase
{
    public function testGetFormat(): void
    {
        self::assertSame(Format::EPUB, new Epub3Driver('file.epub')->getFormat());
    }

    #[DataProvider('filesMetaProvider')]
    public function testIsValid(string $file, mixed ...$args): void
    {
        $driver = new Epub3Driver($file);
        $result = $driver->isValid();
        self::assertTrue($result);
    }

    #[DataProvider('filesProviderFake')]
    public function testIsValidFake(string $file): void
    {
        $driver = new Epub3Driver($file);
        $result = $driver->isValid();
        self::assertFalse($result);
    }

    #[DataProvider('filesDataProvider')]
    public function testGetData(
        string $file,
        int $expectedCount,
        string $expectedId,
        string $expectedTitle,
        ?bool $expectedNavigation,
    ): void {
        $driver = new Epub3Driver($file);
        $data = $driver->getSections();

        self::assertCount($expectedCount, $data);

        /** @var Epub3Section $firstData */
        $firstData = $data[0];

        self::assertSame($expectedId, $firstData->getId(), $file);
        self::assertSame($expectedTitle, $firstData->getTitle(), $file);
        self::assertSame($expectedNavigation, $firstData->isNavigation(), $file);
        self::assertSame('application/xhtml+xml', $firstData->getMediaType(), $file);
        self::assertStringStartsWith('<html', $firstData->getContent(), $file);

        // Every referenced asset path must appear in the (normalized) markup.
        foreach ($firstData->getResources() as $resource) {
            self::assertStringContainsString($resource->getPath(), $firstData->getContent(), $file);
        }
    }

    #[DataProvider('filesMetaProvider')]
    public function testGetMeta(
        string $file,
        string $expectedTitle,
        ?string $expectedAuthor,
        ?string $expectedPublisher,
        ?string $expectedIsbn,
        ?string $expectedDescription,
        ?string $expectedLanguage,
        ?string $expectedLicense,
        ?int $expectedPublishYear,
        ?int $expectedPublishMonth,
        ?int $expectedPublishDay
    ): void {
        $driver = new Epub3Driver($file);
        $meta = $driver->getMeta();
        self::assertSame($expectedTitle, $meta->getTitle());
        self::assertSame($expectedAuthor, $meta->getAuthor());
        self::assertSame($expectedPublisher, $meta->getPublisher());
        self::assertSame($expectedIsbn, $meta->getIsbn());
        self::assertSame($expectedDescription, $meta->getDescription());
        self::assertSame($expectedLanguage, $meta->getLanguage());
        self::assertSame($expectedLicense, $meta->getLicense());
        $publishDate = $meta->getPublishDate();
        self::assertSame($expectedPublishYear, $publishDate->getYear());
        self::assertSame($expectedPublishMonth, $publishDate->getMonth());
        self::assertSame($expectedPublishDay, $publishDate->getDay());
    }

    #[DataProvider('filesCoverProvider')]
    public function testGetCover(
        string $file,
        ?string $expectedMime,
    ): void {
        $driver = new Epub3Driver($file);
        $cover = $driver->getCover();

        if ($expectedMime) {
            self::assertSame($expectedMime, $cover->getMime());
            self::assertNotEmpty($cover->getData());
        } else {
            self::assertNull($cover);
        }
    }

    public static function filesCoverProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/epub/epub3-nav.epub',
            'image/png',
        ];
        yield [
            __DIR__.'/../fixtures/epub/epub2-ncx-cyrillic.epub',
            'image/jpeg',
        ];
    }

    #[DataProvider('filesProviderFake')]
    public function testGetMetaFake(string $file): void
    {
        $driver = new Epub3Driver($file);
        $this->expectException(ParserException::class);
        $driver->getMeta();
    }

    public function testGetMetaEdgeCases(): void
    {
        $driver = new Epub3Driver(__DIR__.'/../fixtures/epub/epub3-missing-meta.epub');
        self::assertTrue($driver->isValid());

        $meta = $driver->getMeta();
        self::assertSame('Edge Cases', $meta->getTitle());
        self::assertNull($meta->getLanguage());
        self::assertTrue($meta->getPublishDate()->isEmpty());
    }

    public function testGetMetaMonthOnlyDate(): void
    {
        $driver = new Epub3Driver(__DIR__.'/../fixtures/epub/epub3-date-month.epub');
        self::assertTrue($driver->isValid());

        $publishDate = $driver->getMeta()->getPublishDate();
        self::assertSame(2008, $publishDate->getYear());
        self::assertSame(5, $publishDate->getMonth());
        self::assertNull($publishDate->getDay());
    }

    public function testGetMetaUnsupportedVersion(): void
    {
        $driver = new Epub3Driver(__DIR__.'/../fixtures/epub/epub4-unsupported.epub');
        self::assertTrue($driver->isValid());

        $this->expectException(UnsupportedFormatException::class);
        $driver->getMeta();
    }

    public function testGetCoverWithoutCover(): void
    {
        $driver = new Epub3Driver(__DIR__.'/../fixtures/epub/epub3-missing-meta.epub');
        self::assertNull($driver->getCover());
    }

    public function testTableOfContentsFromNcx(): void
    {
        $contents = new Epub3Driver(__DIR__.'/../fixtures/epub/epub2-ncx-cyrillic.epub')->getTableOfContents();

        self::assertCount(43, $contents);
        self::assertSame('Владимир Маяковский. Во весь голос. Стихотворения и поэмы', $contents[0]->getTitle());
        self::assertSame(1, $contents[0]->getLevel());
        self::assertSame(1, $contents[0]->getSectionIndex());
        self::assertSame('mh_toc_55', $contents[0]->getAnchor());
    }

    public function testTableOfContentsFromNav(): void
    {
        $contents = new Epub3Driver(__DIR__.'/../fixtures/epub/epub3-nav.epub')->getTableOfContents();

        self::assertNotEmpty($contents);
        self::assertSame('BIBLIOGRAPHY', $contents[1]->getTitle());
        self::assertSame(2, $contents[1]->getLevel());
        self::assertSame(2, $contents[0]->getSectionIndex());
        self::assertSame('pgepubid00492', $contents[0]->getAnchor());
    }

    public function testMetaAuthorsAndSubjects(): void
    {
        $meta = new Epub3Driver(__DIR__.'/../fixtures/epub/epub3-nav.epub')->getMeta();

        self::assertSame('Charles Madison Curry, Erle Elsworth Clippinger', $meta->getAuthor());
        self::assertCount(2, $meta->getAuthors());
        self::assertContains('Children -- Books and reading', $meta->getSubjects());
    }

    public function testEncodedAndRelativeHref(): void
    {
        $driver = new Epub3Driver(__DIR__.'/../fixtures/epub/epub3-encoded-href.epub');
        self::assertTrue($driver->isValid());

        $data = $driver->getSections();
        self::assertCount(1, $data);
        self::assertStringContainsString('Chapter body', $data[0]->getContent());

        $cover = $driver->getCover();
        self::assertNotNull($cover);
        self::assertSame('image/jpeg', $cover->getMime());
    }

    public function testInlineImageResources(): void
    {
        $resources = new Epub3Driver(__DIR__.'/../fixtures/epub/epub3-nav.epub')->getSections()[0]->getResources();

        self::assertNotEmpty($resources);

        $images = \array_values(\array_filter($resources, static fn (EbookResourceInterface $resource): bool => 'image' === $resource->getType()->value));
        self::assertNotEmpty($images);
        self::assertSame('EPUB/images/cover.png', $images[0]->getPath());
        self::assertSame('image/png', $images[0]->getMime());
        self::assertNotSame('', $images[0]->getData());

        $styles = \array_values(\array_filter($resources, static fn (EbookResourceInterface $resource): bool => 'style' === $resource->getType()->value));
        self::assertNotEmpty($styles);
        self::assertSame('EPUB/css/epub.css', $styles[0]->getPath());
        self::assertSame('text/css', $styles[0]->getMime());
    }

    public function testMalformedContainerIsRejected(): void
    {
        $file = self::makeEpub(['META-INF/container.xml' => '<<<not xml>>>']);

        try {
            $driver = new Epub3Driver($file);
            self::assertFalse($driver->isValid());

            try {
                $driver->getMeta();
                self::fail('Malformed container.xml must not be parsed');
            } catch (ParserException $exception) {
                self::assertInstanceOf(\DOMException::class, $exception->getPrevious());
            }
        } finally {
            \unlink($file);
        }
    }

    public function testManifestHrefFragmentIsStripped(): void
    {
        $file = self::makeEpub([
            'META-INF/container.xml' => '<?xml version="1.0"?>'
                .'<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
                .'<rootfiles><rootfile full-path="content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
            'content.opf' => '<?xml version="1.0"?>'
                .'<package xmlns="http://www.idpf.org/2007/opf" version="3.0">'
                .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>T</dc:title></metadata>'
                .'<manifest><item id="c1" href="chapter.xhtml#frag" media-type="application/xhtml+xml"/></manifest>'
                .'<spine><itemref idref="c1"/></spine></package>',
            'chapter.xhtml' => '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>Ch</title></head>'
                .'<body><p>Hello</p></body></html>',
        ]);

        try {
            $sections = new Epub3Driver($file)->getSections();
            self::assertCount(1, $sections);
            self::assertSame('chapter.xhtml', $sections[0]->getId());
            self::assertStringContainsString('Hello', $sections[0]->getContent());
        } finally {
            \unlink($file);
        }
    }

    public function testGetResourcesAggregatesSections(): void
    {
        $resources = new Epub3Driver(__DIR__.'/../fixtures/epub/epub3-nav.epub')->getResources();

        self::assertNotEmpty($resources);

        $paths = \array_map(static fn (EbookResourceInterface $resource): string => $resource->getPath(), $resources);
        self::assertSame($paths, \array_values(\array_unique($paths)));
    }

    /**
     * Builds a ZIP (an EPUB) from entry name => content in a temporary file.
     *
     * @param array<string, string> $entries
     */
    private static function makeEpub(array $entries): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'epub');
        self::assertNotFalse($file);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $file;
    }

    public function testParentDirectoryAndUnresolvedReferences(): void
    {
        $file = self::makeEpub([
            'META-INF/container.xml' => self::containerXml('EPUB/content.opf'),
            'EPUB/content.opf' => self::packageXml(
                '3.0',
                '<item id="ch" href="text/chapter.xhtml" media-type="application/xhtml+xml"/>'
                .'<item id="pic" href="images/pic.png" media-type="image/png"/>'
                .'<item id="css" href="css/style.css" media-type="text/css"/>',
                '<itemref idref="ch"/>'
            ),
            'EPUB/text/chapter.xhtml' => '<html xmlns="http://www.w3.org/1999/xhtml"><head>'
                .'<link rel="stylesheet" href="../css/style.css"/></head><body>'
                .'<img src="../images/pic.png"/><a href="../text/missing.xhtml">gone</a></body></html>',
            'EPUB/css/style.css' => '.a{background:url(../images/pic.png)}'
                .'.b{background:url(data:image/png;base64,AAAA)}'
                .'.c{background:url(../images/nope.png)}',
            'EPUB/images/pic.png' => 'PNG',
        ]);

        try {
            $sections = new Epub3Driver($file)->getSections();
            self::assertCount(1, $sections);

            $content = $sections[0]->getContent();
            self::assertStringContainsString('EPUB/images/pic.png', $content);    // ../ resolved
            self::assertStringContainsString('EPUB/css/style.css', $content);
            self::assertStringContainsString('../text/missing.xhtml', $content); // unknown kept

            $paths = \array_map(static fn (EbookResourceInterface $resource): string => $resource->getPath(), $sections[0]->getResources());
            self::assertContains('EPUB/images/pic.png', $paths);
            self::assertContains('EPUB/css/style.css', $paths);
        } finally {
            \unlink($file);
        }
    }

    public function testNavigationWithNamespacedTypeAndSpecialLinks(): void
    {
        $nav = '<?xml version="1.0"?>'
            .'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:ops="http://www.idpf.org/2007/ops">'
            .'<head><title>Nav</title></head><body>'
            .'<nav ops:type="toc"><ol>'
            .'<li><a href="text/chapter.xhtml">Chapter</a></li>'
            .'<li><a href="#top">Fragment only</a></li>'
            .'<li><a href="#">Empty</a></li>'
            .'<li><a href="https://example.com">External</a></li>'
            .'</ol></nav></body></html>';

        $file = self::makeEpub([
            'META-INF/container.xml' => self::containerXml('content.opf'),
            'content.opf' => self::packageXml(
                '3.0',
                '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
                .'<item id="ch" href="text/chapter.xhtml" media-type="application/xhtml+xml"/>',
                '<itemref idref="ch"/>'
            ),
            'nav.xhtml' => $nav,
            'text/chapter.xhtml' => '<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>',
        ]);

        try {
            $toc = new Epub3Driver($file)->getTableOfContents();
            self::assertCount(4, $toc);

            self::assertSame('Chapter', $toc[0]->getTitle());
            self::assertSame(0, $toc[0]->getSectionIndex());

            self::assertSame('Fragment only', $toc[1]->getTitle());
            self::assertSame('top', $toc[1]->getAnchor());
            self::assertNull($toc[1]->getSectionIndex());

            self::assertSame('Empty', $toc[2]->getTitle());
            self::assertNull($toc[2]->getAnchor());

            self::assertSame('External', $toc[3]->getTitle());
            self::assertNull($toc[3]->getSectionIndex());
        } finally {
            \unlink($file);
        }
    }

    public function testIsbnIdentifiers(): void
    {
        $v3 = self::makeEpub([
            'META-INF/container.xml' => self::containerXml('content.opf'),
            'content.opf' => self::packageXml(
                '3.0',
                '<item id="ch" href="ch.xhtml" media-type="application/xhtml+xml"/>',
                '<itemref idref="ch"/>',
                '<dc:title>T</dc:title><dc:identifier>urn:isbn:9781234567890</dc:identifier>'
            ),
            'ch.xhtml' => '<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>',
        ]);
        $v2 = self::makeEpub([
            'META-INF/container.xml' => self::containerXml('content.opf'),
            'content.opf' => self::packageXml(
                '2.0',
                '<item id="ch" href="ch.xhtml" media-type="application/xhtml+xml"/>',
                '<itemref idref="ch"/>',
                '<dc:title>T</dc:title><dc:identifier opf:scheme="ISBN">9781234567890</dc:identifier>'
            ),
            'ch.xhtml' => '<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>',
        ]);

        try {
            self::assertSame('9781234567890', new Epub3Driver($v3)->getMeta()->getIsbn());
            self::assertSame('9781234567890', new Epub3Driver($v2)->getMeta()->getIsbn());
        } finally {
            \unlink($v3);
            \unlink($v2);
        }
    }

    public function testUnparsablePublishDate(): void
    {
        $file = self::makeEpub([
            'META-INF/container.xml' => self::containerXml('content.opf'),
            'content.opf' => self::packageXml(
                '3.0',
                '<item id="ch" href="ch.xhtml" media-type="application/xhtml+xml"/>',
                '<itemref idref="ch"/>',
                '<dc:title>T</dc:title><dc:date>not-a-date</dc:date>'
            ),
            'ch.xhtml' => '<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>',
        ]);

        try {
            self::assertTrue(new Epub3Driver($file)->getMeta()->getPublishDate()->isEmpty());
        } finally {
            \unlink($file);
        }
    }

    public function testTableOfContentsFallsBackFromBrokenNavToNcx(): void
    {
        $file = self::makeEpub([
            'META-INF/container.xml' => self::containerXml('content.opf'),
            'content.opf' => self::packageXml(
                '3.0',
                '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
                .'<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'
                .'<item id="ch" href="text/chapter.xhtml" media-type="application/xhtml+xml"/>',
                '<itemref idref="ch"/>'
            ),
            'nav.xhtml' => '<<<broken xml',
            'toc.ncx' => self::ncx('Chapter', 'text/chapter.xhtml'),
            'text/chapter.xhtml' => '<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>',
        ]);

        try {
            $toc = new Epub3Driver($file)->getTableOfContents();
            self::assertCount(1, $toc);
            self::assertSame('Chapter', $toc[0]->getTitle());
            self::assertSame(0, $toc[0]->getSectionIndex());
        } finally {
            \unlink($file);
        }
    }

    public function testTableOfContentsSkipsMissingNavToNcx(): void
    {
        $file = self::makeEpub([
            'META-INF/container.xml' => self::containerXml('content.opf'),
            'content.opf' => self::packageXml(
                '3.0',
                '<item id="nav" href="absent-nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
                .'<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'
                .'<item id="ch" href="text/chapter.xhtml" media-type="application/xhtml+xml"/>',
                '<itemref idref="ch"/>'
            ),
            'toc.ncx' => self::ncx('Chapter', 'text/chapter.xhtml'),
            'text/chapter.xhtml' => '<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>',
        ]);

        try {
            $toc = new Epub3Driver($file)->getTableOfContents();
            self::assertCount(1, $toc);
            self::assertSame('Chapter', $toc[0]->getTitle());
        } finally {
            \unlink($file);
        }
    }

    public function testTableOfContentsWithoutNavigation(): void
    {
        $file = self::makeEpub([
            'META-INF/container.xml' => self::containerXml('content.opf'),
            'content.opf' => self::packageXml(
                '3.0',
                '<item id="ch" href="ch.xhtml" media-type="application/xhtml+xml"/>',
                '<itemref idref="ch"/>'
            ),
            'ch.xhtml' => '<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>',
        ]);

        try {
            self::assertSame([], new Epub3Driver($file)->getTableOfContents());
        } finally {
            \unlink($file);
        }
    }

    private static function containerXml(string $opfPath): string
    {
        return '<?xml version="1.0"?>'
            .'<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
            .'<rootfiles><rootfile full-path="'.$opfPath.'" media-type="application/oebps-package+xml"/></rootfiles>'
            .'</container>';
    }

    private static function packageXml(string $version, string $manifest, string $spine, string $metadata = '<dc:title>T</dc:title>'): string
    {
        return '<?xml version="1.0"?>'
            .'<package xmlns="http://www.idpf.org/2007/opf" xmlns:opf="http://www.idpf.org/2007/opf" version="'.$version.'">'
            .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'.$metadata.'</metadata>'
            .'<manifest>'.$manifest.'</manifest>'
            .'<spine>'.$spine.'</spine></package>';
    }

    private static function ncx(string $title, string $src): string
    {
        return '<?xml version="1.0"?>'
            .'<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><navMap>'
            .'<navPoint id="n1"><navLabel><text>'.$title.'</text></navLabel>'
            .'<content src="'.$src.'"/></navPoint></navMap></ncx>';
    }

    public static function filesProviderFake(): \Generator
    {
        yield [__DIR__.'/../fixtures/fake.xml'];
        yield [__DIR__.'/../fixtures/fake.zip'];
    }

    public static function filesDataProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/epub/epub3-nav.epub',
            3,
            'EPUB/cover.xhtml',
            'Children\'s Literature',
            false,
        ];
        yield [
            __DIR__.'/../fixtures/epub/epub2-ncx-cyrillic.epub',
            14,
            'cover.xhtml',
            'Cover of Во весь голос. Стихотворения и поэмы',
            null,
        ];
    }

    public static function filesMetaProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/epub/epub3-nav.epub',
            'Children\'s Literature, A Textbook of Sources for Teachers and Teacher-Training Classes', // title
            'Charles Madison Curry, Erle Elsworth Clippinger', // author
            null, // publisher
            null, // isbn
            null, // description
            'en', // language
            'Public domain in the USA.', // license
            2008, // publish year
            5, // publish month
            20, // publish day
        ];
        yield [
            __DIR__.'/../fixtures/epub/epub2-ncx-cyrillic.epub',
            'Во весь голос. Стихотворения и поэмы', // title
            'Владимир Владимирович Маяковский', // author
            'ООО «ЛитРес», www.litres.ru', // publisher
            null, // isbn
            "Владимир Владимирович Маяковский (1893–1930)\u{a0}– один из крупнейших советских поэтов, новаторское творчество которого имело огромное значение для всей поэзии ХХ века.\n".
"На формирование мировоззрения поэта особенно повлияли демократическая атмосфера, царившая в семье, и первая русская революция. В. Маяковский решил посвятить своё творчество борьбе с существовавшим тогда строем.\n".
"Главной задачей поэт считал – с помощью своих стихов приближать будущее. Назначение поэта – нести свет людям: свет звёзд и свет солнца («Послушайте!», «Необычайное происшествие…»).\n".
"Маяковский – оратор и лирик. Его агитационные и лирические стихи обращены к широким массам и лично к каждому. Поэту необходим задушевный разговор с читателем. («А вы могли бы?», «Скрипка и немножко нервно»).\n".
'В сборник вошли стихотворения и поэмы, написанные в разные годы жизни поэта.', // description
            'ru', // language
            null, // license
            1912, // publish year
            null, // publish month
            null, // publish day
        ];
    }
}
