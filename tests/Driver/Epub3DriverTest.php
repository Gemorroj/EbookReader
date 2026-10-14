<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\Epub3Driver;
use EbookReader\Exception\ParserException;
use EbookReader\Exception\UnsupportedFormatException;
use EbookReader\Format;
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

        $images = \array_values(\array_filter($resources, static fn ($resource): bool => 'image' === $resource->getType()->value));
        self::assertNotEmpty($images);
        self::assertSame('EPUB/images/cover.png', $images[0]->getPath());
        self::assertSame('image/png', $images[0]->getMime());
        self::assertNotSame('', $images[0]->getData());

        $styles = \array_values(\array_filter($resources, static fn ($resource): bool => 'style' === $resource->getType()->value));
        self::assertNotEmpty($styles);
        self::assertSame('EPUB/css/epub.css', $styles[0]->getPath());
        self::assertSame('text/css', $styles[0]->getMime());
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
