<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\Fb2Driver;
use EbookReader\Exception\ParserException;
use PHPUnit\Framework\TestCase;

class Fb2DriverTest extends TestCase
{
    /**
     * @dataProvider filesProvider
     */
    public function testIsValid(string $file): void
    {
        $driver = new Fb2Driver($file);
        $result = $driver->isValid();
        self::assertTrue($result);
    }

    /**
     * @dataProvider filesProviderFake
     */
    public function testIsValidFake(string $file): void
    {
        $driver = new Fb2Driver($file);
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
        $driver = new Fb2Driver($file);
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
        $driver = new Fb2Driver($file);
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
            [__DIR__.'/../fixtures/fb2/fb2.fb2',
                'The Geography of Bliss: One Grump\'s Search for the Happiest Places in the World', // title
                'Eric Weiner', // author
                'Twelve', // publisher
                '9780446511070', // isbn
                null, // description
                'en', // language
                null, // license
                2008, // publish year
                null, // publish month
                null, // publish day
            ],
            [__DIR__.'/../fixtures/fb2/fb2.zip',
                'The Geography of Bliss: One Grump\'s Search for the Happiest Places in the World', // title
                'Eric Weiner', // author
                'Twelve', // publisher
                '9780446511070', // isbn
                null, // description
                'en', // language
                null, // license
                2008, // publish year
                null, // publish month
                null, // publish day
            ],
            [__DIR__.'/../fixtures/fb2/mayakovskiy.fb2',
                'Во весь голос. Стихотворения и поэмы', // title
                'Владимир Владимирович Маяковский', // author
                'АСТ', // publisher
                '978-5-17-136765-7, 978-5-17-136763-3', // isbn
                "<p>Владимир Владимирович Маяковский (1893–1930)\u{a0}– один из крупнейших советских поэтов, новаторское творчество которого имело огромное значение для всей поэзии ХХ века.</p><p>На формирование мировоззрения поэта особенно повлияли демократическая атмосфера, царившая в семье, и первая русская революция. В. Маяковский решил посвятить своё творчество борьбе с существовавшим тогда строем.</p><p>Главной задачей поэт считал – с помощью своих стихов приближать будущее. Назначение поэта – нести свет людям: свет звёзд и свет солнца («Послушайте!», «Необычайное происшествие…»).</p><p>Маяковский – оратор и лирик. Его агитационные и лирические стихи обращены к широким массам и лично к каждому. Поэту необходим задушевный разговор с читателем. («А вы могли бы?», «Скрипка и немножко нервно»).</p><p>В сборник вошли стихотворения и поэмы, написанные в разные годы жизни поэта.</p>", // description
                'ru', // language
                null, // license
                2021, // publish year
                null, // publish month
                null, // publish day
            ],
        ];
    }
}
