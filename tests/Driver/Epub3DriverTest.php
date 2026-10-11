<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Data\Epub3Data;
use EbookReader\Driver\Epub3Driver;
use EbookReader\Exception\ParserException;
use EbookReader\Exception\UnsupportedFormatException;
use EbookReader\Format;
use EbookReader\Resource\Style;
use EbookReader\Resource\StyleType;
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

    /**
     * @param Style[] $expectedStyles
     */
    #[DataProvider('filesDataProvider')]
    public function testGetData(
        string $file,
        int $expectedCount,
        string $expectedTitle,
        array $expectedStyles,
        ?bool $expectedNavigation,
        string $expectedText
    ): void {
        $driver = new Epub3Driver($file);
        $data = $driver->getData();

        self::assertCount($expectedCount, $data);

        /** @var Epub3Data $firstData */
        $firstData = $data[0];

        self::assertSame($expectedTitle, $firstData->getTitle(), $file);
        self::assertSame($expectedNavigation, $firstData->isNavigation(), $file);
        self::assertSame($expectedText, $firstData->getText(), $file);

        self::assertCount(\count($expectedStyles), $firstData->getStyles(), $file);
        foreach ($expectedStyles as $expectedStyle) {
            $styleExists = false;
            foreach ($firstData->getStyles() as $style) {
                if ($style->getType() === $expectedStyle->getType() && $style->getData() === $expectedStyle->getData()) {
                    $styleExists = true;
                    break;
                }
            }

            self::assertTrue($styleExists, 'Not found expected style');
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
    }

    public function testTableOfContentsFromNav(): void
    {
        $contents = new Epub3Driver(__DIR__.'/../fixtures/epub/epub3-nav.epub')->getTableOfContents();

        self::assertNotEmpty($contents);
        self::assertSame('BIBLIOGRAPHY', $contents[1]->getTitle());
        self::assertSame(2, $contents[1]->getLevel());
        self::assertSame(2, $contents[0]->getSectionIndex());
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

        $data = $driver->getData();
        self::assertCount(1, $data);
        self::assertStringContainsString('Chapter body', $data[0]->getText());

        $cover = $driver->getCover();
        self::assertNotNull($cover);
        self::assertSame('image/jpeg', $cover->getMime());
    }

    public function testInlineImageResources(): void
    {
        $driver = new Epub3Driver(__DIR__.'/../fixtures/epub/epub3-nav.epub');
        $resources = $driver->getData()[0]->getResources();

        self::assertNotEmpty($resources);
        self::assertSame('images/cover.png', $resources[0]->getPath());
        self::assertSame('image/png', $resources[0]->getMime());
        self::assertNotSame('', $resources[0]->getData());
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
            'Children\'s Literature',
            [new Style('css/epub.css', StyleType::LINK)],
            false,
            '<div><img src="images/cover.png" alt="Cover Image" title="Cover Image"></img></div>',
        ];
        yield [
            __DIR__.'/../fixtures/epub/epub2-ncx-cyrillic.epub',
            14,
            'Cover of Во весь голос. Стихотворения и поэмы',
            [new Style('body {padding:0;} img {height: 100%; max-width: 100%;} div {text-align: center; page-break-after: always;}', StyleType::CSS)],
            null,
            '<div><div><img alt="cover" src="cover.jpg"></img></div></div>',
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
