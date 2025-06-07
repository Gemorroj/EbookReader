<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Data\Epub3Data;
use EbookReader\Driver\Epub3Driver;
use EbookReader\Exception\ParserException;
use EbookReader\Resource\Style;
use EbookReader\Resource\StyleType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Epub3DriverTest extends TestCase
{
    #[DataProvider('filesMetaProvider')]
    public function testIsValid(string $file): void
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
        self::assertSame($expectedPublishYear, $meta->getPublishYear());
        self::assertSame($expectedPublishMonth, $meta->getPublishMonth());
        self::assertSame($expectedPublishDay, $meta->getPublishDay());
    }

    #[DataProvider('filesProviderFake')]
    public function testGetMetaFake(string $file): void
    {
        $driver = new Epub3Driver($file);
        $this->expectException(ParserException::class);
        $driver->getMeta();
    }

    public static function filesProviderFake(): \Generator
    {
        yield [__DIR__.'/../fixtures/fake.xml'];
        yield [__DIR__.'/../fixtures/fake.zip'];
    }

    public static function filesDataProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/epub/epub3-opf2.epub',
            7,
            'Cover Image',
            [new Style('../stylesheet.css', StyleType::LINK), new Style('../page_styles.css', StyleType::LINK)],
            null,
            '<div class="calibre"><div class="body"><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" width="100%" height="100%" viewBox="0 0 500 656"><image width="500" height="656" xlink:href="images/GeographyofBli-cover.jpg" transform="translate(0 0)"></image></svg></div></div>',
        ];
        yield [
            __DIR__.'/../fixtures/epub/epub3-opf3.epub',
            3,
            'Children\'s Literature',
            [new Style('css/epub.css', StyleType::LINK)],
            false,
            '<div><img src="images/cover.png" alt="Cover Image" title="Cover Image"></img></div>',
        ];
        yield [
            __DIR__.'/../fixtures/epub/mayakovskiy-opf2.epub',
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
            __DIR__.'/../fixtures/epub/epub3-opf2.epub',
            'The Geography of Bliss: One Grump\'s Search for the Happiest Places in the World', // title
            'Eric Weiner', // author
            'Twelve', // publisher
            '9780446511070', // isbn
            'Part foreign affairs discourse, part humor, and part twisted self-help guide, The Geography of Bliss takes the reader from America to Iceland to India in search of happiness, or, in the crabby author\'s case, moments of \'un-unhappiness.\' The book uses a beguiling mixture of travel, psychology, science and humor to investigate not what happiness is, but where it is. Are people in Switzerland happier because it is the most democratic country in the world? Do citizens of Singapore benefit psychologically by having their options limited by the government? Is the King of Bhutan a visionary for his initiative to calculate Gross National Happiness? Why is Asheville, North Carolina so damn happy? With engaging wit and surprising insights, Eric Weiner answers those questions and many others, offering travelers of all moods some interesting new ideas for sunnier destinations and dispositions.', // description
            'en', // language
            'WORLD ALL LANGUAGES', // license
            2008, // publish year
            1, // publish month
            3, // publish day
        ];
        yield [
            __DIR__.'/../fixtures/epub/epub3-opf3.epub',
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
            __DIR__.'/../fixtures/epub/mayakovskiy-opf2.epub',
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
