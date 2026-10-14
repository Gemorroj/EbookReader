<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\MobiDriver;
use EbookReader\Exception\EncryptedBookException;
use EbookReader\Exception\ParserException;
use EbookReader\Exception\UnsupportedCompressionException;
use EbookReader\Format;
use EbookReader\Section\MobiSection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MobiDriverTest extends TestCase
{
    public function testGetFormat(): void
    {
        self::assertSame(Format::MOBI, new MobiDriver('file.mobi')->getFormat());
    }

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
            self::buildMobi($title, 1, 1252, \strlen($text), 1, [$text]),
            static fn (MobiDriver $driver): array => [$driver->getSections()[0]->getContent(), $driver->getMeta()->getTitle()]
        );

        self::assertSame(["R\xC3\xA9sum\xC3\xA9", "Caf\xC3\xA9"], $result);
    }

    public function testTextLengthTruncation(): void
    {
        $text = $this->withMobi(
            self::buildMobi('Title', 1, 65001, 5, 1, ['HELLOGARBAGE']),
            static fn (MobiDriver $driver): string => $driver->getSections()[0]->getContent()
        );

        self::assertSame('HELLO', $text);
    }

    public function testUnsupportedCompression(): void
    {
        $this->expectException(UnsupportedCompressionException::class);

        $this->withMobi(
            self::buildMobi('Title', 99, 65001, 1, 1, ['x']),
            static fn (MobiDriver $driver): array => $driver->getSections()
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

        $text = $driver->getSections()[0]->getContent();
        self::assertSame(111701, \strlen($text));
        self::assertStringContainsString('This is a sample for testing libmobi project.', $text);
    }

    public function testInlineImageResources(): void
    {
        $driver = new MobiDriver(__DIR__.'/../fixtures/mobi/huffcdic.mobi');
        $resources = $driver->getSections()[0]->getResources();

        self::assertNotEmpty($resources);
        self::assertSame('00001', $resources[0]->getPath());
        self::assertStringStartsWith('image/', $resources[0]->getMime());
        self::assertNotSame('', $resources[0]->getData());
    }

    public function testTableOfContents(): void
    {
        $contents = new MobiDriver(__DIR__.'/../fixtures/mobi/palmodoc-cyrillic.mobi')->getTableOfContents();

        self::assertCount(49, $contents);
        self::assertSame('Владимир Маяковский Во весь голос. Стихотворения и поэмы', $contents[0]->getTitle());
        self::assertSame(1, $contents[0]->getLevel());
        self::assertSame(0, $contents[0]->getSectionIndex());
        self::assertSame(7813, $contents[0]->getPosition());
    }

    public function testNcxSample(): void
    {
        $driver = new MobiDriver(__DIR__.'/../fixtures/mobi/ncx.mobi');
        self::assertTrue($driver->isValid());
        self::assertSame('libmobi ncx test', $driver->getMeta()->getTitle());

        $contents = $driver->getTableOfContents();
        self::assertCount(4, $contents);
        self::assertSame('Test chapter 1', $contents[0]->getTitle());
        self::assertSame(1, $contents[0]->getLevel());
        self::assertSame(40, $contents[0]->getPosition());
        self::assertSame(2, $contents[2]->getLevel());
    }

    public function testCp1252Sample(): void
    {
        $driver = new MobiDriver(__DIR__.'/../fixtures/mobi/cp1252.mobi');
        self::assertTrue($driver->isValid());

        $meta = $driver->getMeta();
        self::assertSame('Libmobi test sample', $meta->getTitle());
        self::assertSame(['Bartek Fabiszewski'], $meta->getAuthors());
        self::assertNotEmpty($driver->getSections()[0]->getContent());
    }

    public function testEncryptedSample(): void
    {
        $driver = new MobiDriver(__DIR__.'/../fixtures/mobi/encrypted.mobi');
        self::assertTrue($driver->isValid());

        $this->expectException(EncryptedBookException::class);
        $driver->getMeta();
    }

    public function testMetaAuthors(): void
    {
        $meta = new MobiDriver(__DIR__.'/../fixtures/mobi/palmodoc-cyrillic.mobi')->getMeta();

        self::assertSame(['Владимир Владимирович Маяковский'], $meta->getAuthors());
        self::assertSame('Владимир Владимирович Маяковский', $meta->getAuthor());
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
        yield ['palmodoc-cyrillic.mobi', 'image/jpeg'];
        yield ['huffcdic.mobi', 'image/jpeg'];
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
        ?string $expectedTitle,
        string $expectedStartText,
        string $expectedEndText,
    ): void {
        $driver = new MobiDriver($file);
        $data = $driver->getSections();

        self::assertCount($expectedCount, $data);

        /** @var MobiSection $firstData */
        $firstData = $data[0];

        self::assertSame($expectedTitle, $firstData->getTitle(), $file);
        self::assertStringStartsWith($expectedStartText, $firstData->getContent(), $file);
        self::assertStringEndsWith($expectedEndText, $firstData->getContent(), $file);
    }

    public static function filesDataProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/mobi/palmodoc-cyrillic.mobi',
            1,
            null,
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
            __DIR__.'/../fixtures/mobi/palmodoc-cyrillic.mobi',
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

    /**
     * Builds a minimal but spec-conformant MOBI (Palm Database) in memory.
     *
     * @param string[] $records records after record 0 (text/HUFF/CDIC/...)
     */
    private static function buildMobi(
        string $title,
        int $compression,
        int $encoding,
        int $textLength,
        int $textRecordCount,
        array $records,
        int $huffmanRecordOffset = 0,
        int $huffmanRecordCount = 0,
    ): string {
        $nameOffset = 260; // 16 (PalmDOC) + 232 (MOBI header) + 12 (EXTH)
        $record0 = self::palmDocHeader($compression, $textLength, $textRecordCount)
            .self::mobiHeader($nameOffset, \strlen($title), $encoding, $huffmanRecordOffset, $huffmanRecordCount)
            .'EXTH'.\pack('N', 12).\pack('N', 0)
            .$title;

        $records = \array_merge([$record0], $records);
        $count = \count($records);

        $pdb = \str_pad('TestBook', 32, "\x00");
        $pdb .= \pack('n', 0).\pack('n', 0); // attributes, version
        $pdb .= \str_repeat("\x00", 16);     // dates + modification number
        $pdb .= \pack('N', 0).\pack('N', 0); // appInfoId, sortInfoId
        $pdb .= 'BOOKMOBI';
        $pdb .= \pack('N', 0).\pack('N', 0); // uniqueIdSeed, nextRecordListId
        $pdb .= \pack('n', $count);

        $offset = \strlen($pdb) + $count * 8 + 2;
        $list = '';
        foreach ($records as $index => $record) {
            $list .= \pack('N', $offset)."\x00".\substr(\pack('N', $index), 1);
            $offset += \strlen($record);
        }

        return $pdb.$list."\x00\x00".\implode('', $records);
    }

    private static function palmDocHeader(int $compression, int $textLength, int $textRecordCount): string
    {
        return \pack('n', $compression)
            .\pack('n', 0)
            .\pack('N', $textLength)
            .\pack('n', $textRecordCount)
            .\pack('n', 4096)
            .\pack('N', 0);
    }

    private static function mobiHeader(
        int $nameOffset,
        int $nameLength,
        int $encoding,
        int $huffmanRecordOffset,
        int $huffmanRecordCount,
    ): string {
        $header = \str_repeat("\x00", 232);

        $put = static function (int $relOffset, string $bytes) use (&$header): void {
            $header = \substr_replace($header, $bytes, $relOffset - 16, \strlen($bytes));
        };

        $put(16, 'MOBI');
        $put(20, \pack('N', 232));    // header length
        $put(24, \pack('N', 2));      // mobi type
        $put(28, \pack('N', $encoding));
        $put(80, \pack('N', 4));      // first non-book index
        $put(84, \pack('N', $nameOffset));
        $put(88, \pack('N', $nameLength));
        $put(112, \pack('N', $huffmanRecordOffset));
        $put(116, \pack('N', $huffmanRecordCount));
        $put(128, \pack('N', 0x40));  // EXTH flags
        $put(242, \pack('n', 0));     // extra record data flags

        return $header;
    }
}
