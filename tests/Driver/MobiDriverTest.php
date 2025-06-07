<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Data\MobiData;
use EbookReader\Driver\MobiDriver;
use EbookReader\Exception\ParserException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MobiDriverTest extends TestCase
{
    #[DataProvider('filesProvider')]
    public function testIsValid(string $file): void
    {
        $driver = new MobiDriver($file);
        $result = $driver->isValid();
        self::assertTrue($result);
    }

    #[DataProvider('filesProviderFake')]
    public function testIsValidFake(string $file): void
    {
        $driver = new MobiDriver($file);
        $result = $driver->isValid();
        self::assertFalse($result);
    }

    #[DataProvider('filesProvider')]
    public function testRead(
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
        $driver = new MobiDriver($file);
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
    public function testReadFake(string $file): void
    {
        $driver = new MobiDriver($file);
        $this->expectException(ParserException::class);
        $driver->getMeta();
    }

    #[DataProvider('filesDataProvider')]
    public function testGetData(
        string $file,
        int $expectedCount,
        string $expectedTitle,
        string $expectedStartText,
        string $expectedEndText,
    ): void {
        $driver = new MobiDriver($file);
        $data = $driver->getData();

        self::assertCount($expectedCount, $data);

        /** @var MobiData $firstData */
        $firstData = $data[0];

        self::assertSame($expectedTitle, $firstData->getTitle(), $file);
        self::assertStringStartsWith($expectedStartText, $firstData->getText(), $file);
        self::assertStringEndsWith($expectedEndText, $firstData->getText(), $file);

        self::assertEmpty($firstData->getStyles(), $file);
    }

    public static function filesDataProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/mobi/mobi.mobi',
            1,
            'The Geography of Bliss: One Grump\'s Search for the Happiest Places in the World',
            '<html><head><guide><reference type="toc" title="Table of Contents" filepos=0000001279 /></guide></head><body><p height="19em" width="0pt" align="left">Copyright © 2008 by Eric Weiner</p>',
            '<p height="0pt" width="1em" align="justify">No, the Dutch way is not for me. Perhaps my next destination is the one. I’m heading to a country where the trains run on time, the streets are clean, and tolerance, like everything else, is doled out carefully, in moderation. I am heading to Switzerland.</p><mbp:pagebreak/><a ></a> <a ></a> <a ></a></body></html>',
        ];
        yield [
            __DIR__.'/../fixtures/mobi/mayakovskiy.mobi',
            1,
            'Во весь голос. Стихотворения и поэмы',
            "<html><head><guide><reference type=\"toc\" title=\"Table of Contents\" filepos=0000231502 /></guide></head><body><p height=\"1em\" width=\"0pt\" align=\"left\"><font size=\"4\"><b><span bgcolor=\"#f1f1f1\">Annotation</span></b></font></p><p height=\"1em\" width=\"2em\" align=\"justify\">Владимир Владимирович Маяковский (1893–1930)\u{a0}– один из крупнейших советских поэтов, новаторское творчество которого имело огромное значение для всей поэзии ХХ века.</p>",
            '<p height="0pt" width="-14pt"><a filepos=0000229558>Примечания</a></p><p height="0pt" width="-14pt"><a filepos=0000229785>1</a></p><p height="0pt" width="-14pt"><a filepos=0000230081>2</a></p><p height="0pt" width="-14pt"><a filepos=0000230367>3</a></p><p height="0pt" width="-14pt"><a filepos=0000230677>4</a></p><p height="0pt" width="-14pt"><a filepos=0000230961>5</a></p><p height="0pt" width="-14pt"><a filepos=0000231242>6</a></p><mbp:pagebreak/><a ></a> <a ></a> <a ></a></body></html>',
        ];
    }

    public static function filesProviderFake(): \Generator
    {
        yield [__DIR__.'/../fixtures/fake.xml'];
        yield [__DIR__.'/../fixtures/fake.zip'];
    }

    public static function filesProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/mobi/mobi.mobi',
            'The Geography of Bliss: One Grump\'s Search for the Happiest Places in the World', // title
            'Eric Weiner', // author
            'Twelve', // publisher
            '9780446511070', // isbn
            'Part foreign affairs discourse, part humor, and part twisted self-help guide, The Geography of Bliss takes the reader from America to Iceland to India in search of happiness, or, in the crabby author\'s case, moments of \'un-unhappiness.\' The book uses a beguiling mixture of travel, psychology, science and humor to investigate not what happiness is, but where it is. Are people in Switzerland happier because it is the most democratic country in the world? Do citizens of Singapore benefit psychologically by having their options limited by the government? Is the King of Bhutan a visionary for his initiative to calculate Gross National Happiness? Why is Asheville, North Carolina so damn happy? With engaging wit and surprising insights, Eric Weiner answers those questions and many others, offering travelers of all moods some interesting new ideas for sunnier destinations and dispositions.', // description
            'en', // language
            null, // license
            2008, // publish year
            1, // publish month
            3, // publish day
        ];
        yield [
            __DIR__.'/../fixtures/mobi/mayakovskiy.mobi',
            'Во весь голос. Стихотворения и поэмы', // title
            'Владимир Владимирович Маяковский', // author
            'АСТ', // publisher
            '978-5-17-136765-7', // isbn
            "Владимир Владимирович Маяковский (1893–1930)\u{a0}– один из крупнейших советских поэтов, новаторское творчество которого имело огромное значение для всей поэзии ХХ века.\n".
            "На формирование мировоззрения поэта особенно повлияли демократическая атмосфера, царившая в семье, и первая русская революция. В. Маяковский решил посвятить своё творчество борьбе с существовавшим тогда строем.\n".
            "Главной задачей поэт считал – с помощью своих стихов приближать будущее. Назначение поэта – нести свет людям: свет звёзд и свет солнца («Послушайте!», «Необычайное происшествие…»).\n".
            "Маяковский – оратор и лирик. Его агитационные и лирические стихи обращены к широким массам и лично к каждому. Поэту необходим задушевный разговор с читателем. («А вы могли бы?», «Скрипка и немножко нервно»).\n".
            'В сборник вошли стихотворения и поэмы, написанные в разные годы жизни поэта.', // description
            'ru', // language
            null, // license
            2021, // publish year
            5, // publish month
            15, // publish day
        ];
    }
}
