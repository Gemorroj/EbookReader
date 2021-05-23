<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\Epub3Driver;
use EbookReader\Exception\ParserException;
use PHPUnit\Framework\TestCase;

class Epub3DriverTest extends TestCase
{
    /**
     * @dataProvider filesProvider
     */
    public function testIsValid(string $file): void
    {
        $driver = new Epub3Driver($file);
        $result = $driver->isValid();
        self::assertTrue($result);
    }

    /**
     * @dataProvider filesProviderFake
     */
    public function testIsValidFake(string $file): void
    {
        $driver = new Epub3Driver($file);
        $result = $driver->isValid();
        self::assertFalse($result);
    }

    /**
     * @dataProvider filesProvider
     */
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

    /**
     * @dataProvider filesProviderFake
     */
    public function testGetMetaFake(string $file): void
    {
        $driver = new Epub3Driver($file);
        $this->expectException(ParserException::class);
        $driver->getMeta();
    }

    /**
     * @return string[][]
     */
    public function filesProviderFake(): array
    {
        return [
            [__DIR__.'/../fixtures/fake.xml'],
            [__DIR__.'/../fixtures/fake.zip'],
        ];
    }

    /**
     * @return string[][]
     */
    public function filesProvider(): array
    {
        return [
            [__DIR__.'/../fixtures/epub/epub3-opf2.epub',
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
            ],
            [__DIR__.'/../fixtures/epub/epub3-opf3.epub',
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
            ],
            [__DIR__.'/../fixtures/epub/mayakovskiy-opf2.epub',
                'Во весь голос. Стихотворения и поэмы', // title
                'Владимир Владимирович Маяковский', // author
                'ООО «ЛитРес», www.litres.ru', // publisher
                null, // isbn
                "Владимир Владимирович Маяковский (1893–1930)\u{a0}– один из крупнейших советских поэтов, новаторское творчество которого имело огромное значение для всей поэзии ХХ века.
На формирование мировоззрения поэта особенно повлияли демократическая атмосфера, царившая в семье, и первая русская революция. В. Маяковский решил посвятить своё творчество борьбе с существовавшим тогда строем.
Главной задачей поэт считал – с помощью своих стихов приближать будущее. Назначение поэта – нести свет людям: свет звёзд и свет солнца («Послушайте!», «Необычайное происшествие…»).
Маяковский – оратор и лирик. Его агитационные и лирические стихи обращены к широким массам и лично к каждому. Поэту необходим задушевный разговор с читателем. («А вы могли бы?», «Скрипка и немножко нервно»).
В сборник вошли стихотворения и поэмы, написанные в разные годы жизни поэта.", // description
                'ru', // language
                null, // license
                1912, // publish year
                null, // publish month
                null, // publish day
            ],
        ];
    }
}
