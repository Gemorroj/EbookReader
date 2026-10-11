<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Data\MobiData;
use EbookReader\Driver\MobiDriver;
use EbookReader\Exception\ParserException;
use EbookReader\Tests\Support\HuffCdicFixture;
use EbookReader\Tests\Support\MobiFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MobiDriverTest extends TestCase
{
    #[DataProvider('filesProvider')]
    public function testIsValid(string $file, mixed ...$args): void
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
        $publishDate = $meta->getPublishDate();
        self::assertSame($expectedPublishYear, $publishDate->getYear());
        self::assertSame($expectedPublishMonth, $publishDate->getMonth());
        self::assertSame($expectedPublishDay, $publishDate->getDay());
    }

    #[DataProvider('filesProviderFake')]
    public function testReadFake(string $file): void
    {
        $driver = new MobiDriver($file);
        $this->expectException(ParserException::class);
        $driver->getMeta();
    }

    public function testWindows1252Encoding(): void
    {
        $title = "Caf\xE9";        // "Café" in CP1252
        $text = "R\xE9sum\xE9";    // "Résumé" in CP1252

        $result = $this->withMobi(
            MobiFixture::build($title, 1, 1252, \strlen($text), 1, [$text]),
            static function (MobiDriver $driver): array {
                return [$driver->getData()[0]->getText(), $driver->getMeta()->getTitle()];
            }
        );

        self::assertSame(["R\xC3\xA9sum\xC3\xA9", "Caf\xC3\xA9"], $result);
    }

    public function testTextLengthTruncation(): void
    {
        $text = $this->withMobi(
            MobiFixture::build('Title', 1, 65001, 5, 1, ['HELLOGARBAGE']),
            static fn (MobiDriver $driver): string => $driver->getData()[0]->getText()
        );

        self::assertSame('HELLO', $text);
    }

    public function testUnsupportedCompression(): void
    {
        $this->expectException(ParserException::class);

        $this->withMobi(
            MobiFixture::build('Title', 99, 65001, 1, 1, ['x']),
            static fn (MobiDriver $driver): array => $driver->getData()
        );
    }

    /**
     * Real HUFF/CDIC sample from libmobi (tests/samples/sample-unicode-huffdic.mobi);
     * a generated test document, not a book. Its decompressed text is byte-identical
     * to the uncompressed sibling sample.
     */
    public function testRealHuffCdicFile(): void
    {
        $driver = new MobiDriver(__DIR__.'/../fixtures/mobi/huffcdic.mobi');
        self::assertTrue($driver->isValid());
        self::assertSame('Libmobi', $driver->getMeta()->getTitle());

        $text = $driver->getData()[0]->getText();
        self::assertSame(111701, \strlen($text));
        self::assertStringContainsString('This is a sample for testing libmobi project.', $text);
    }

    public function testInlineImageResources(): void
    {
        $driver = new MobiDriver(__DIR__.'/../fixtures/mobi/huffcdic.mobi');
        $resources = $driver->getData()[0]->getResources();

        self::assertNotEmpty($resources);
        self::assertSame('00001', $resources[0]->getPath());
        self::assertStringStartsWith('image/', $resources[0]->getMime());
        self::assertNotSame('', $resources[0]->getData());
    }

    #[DataProvider('coverProvider')]
    public function testGetCover(string $file, string $expectedMime): void
    {
        $cover = new MobiDriver(__DIR__.'/../fixtures/mobi/'.$file)->getCover();

        self::assertNotNull($cover);
        self::assertSame($expectedMime, $cover->getMime());
        self::assertNotSame('', $cover->getData());
    }

    public static function coverProvider(): \Generator
    {
        yield ['mobi.mobi', 'image/jpeg'];
        yield ['mayakovskiy.mobi', 'image/jpeg'];
        yield ['huffcdic.mobi', 'image/jpeg'];
    }

    public function testHuffCdic(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'mobi');
        self::assertNotFalse($file);
        \file_put_contents($file, HuffCdicFixture::buildMobi());

        try {
            $driver = new MobiDriver($file);
            self::assertTrue($driver->isValid());
            self::assertSame(HuffCdicFixture::TITLE, $driver->getMeta()->getTitle());

            $data = $driver->getData();
            self::assertCount(1, $data);
            self::assertSame(HuffCdicFixture::TEXT, $data[0]->getText());
        } finally {
            \unlink($file);
        }
    }

    /**
     * @template T
     *
     * @param callable(MobiDriver): T $callback
     *
     * @return T
     */
    private function withMobi(string $bytes, callable $callback): mixed
    {
        $file = \tempnam(\sys_get_temp_dir(), 'mobi');
        self::assertNotFalse($file);
        \file_put_contents($file, $bytes);

        try {
            return $callback(new MobiDriver($file));
        } finally {
            \unlink($file);
        }
    }

    /**
     * @param non-empty-string $expectedStartText
     * @param non-empty-string $expectedEndText
     */
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
