<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\TxtDriver;
use EbookReader\Exception\ParserException;
use EbookReader\Format;
use EbookReader\Section\TxtSection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TxtDriverTest extends TestCase
{
    public function testGetFormat(): void
    {
        self::assertSame(Format::TXT, new TxtDriver('file.txt')->getFormat());
    }

    #[DataProvider('filesProvider')]
    public function testIsValid(string $file, mixed ...$args): void
    {
        $driver = new TxtDriver($file);
        $result = $driver->isValid();
        self::assertTrue($result);
    }

    #[DataProvider('filesProviderFake')]
    public function testIsValidFake(string $file): void
    {
        $driver = new TxtDriver($file);
        $result = $driver->isValid();
        self::assertFalse($result);
    }

    #[DataProvider('filesProvider')]
    public function testGetMeta(
        string $file,
        string $expectedTitle,
    ): void {
        $driver = new TxtDriver($file);
        $meta = $driver->getMeta();
        self::assertSame($expectedTitle, $meta->getTitle());
        self::assertNull($meta->getAuthor());
        self::assertNull($meta->getPublisher());
        self::assertNull($meta->getIsbn());
        self::assertNull($meta->getDescription());
        self::assertNull($meta->getLanguage());
        self::assertNull($meta->getLicense());
        self::assertTrue($meta->getPublishDate()->isEmpty());
    }

    #[DataProvider('filesProviderFake')]
    public function testGetMetaFake(string $file): void
    {
        $driver = new TxtDriver($file);
        $this->expectException(ParserException::class);
        $driver->getMeta();
    }

    public function testTableOfContentsIsEmpty(): void
    {
        self::assertSame([], new TxtDriver(__DIR__.'/../fixtures/txt/txt-utf8.txt')->getTableOfContents());
    }

    public function testSingleLineWithoutTitle(): void
    {
        $driver = new TxtDriver(__DIR__.'/../fixtures/txt/txt-single-line.txt');
        self::assertTrue($driver->isValid());

        $data = $driver->getSections();
        self::assertCount(1, $data);
        self::assertNull($data[0]->getTitle());
        self::assertSame('Single line without any newline', $data[0]->getContent());

        self::assertSame('Single line without any newline', $driver->getMeta()->getTitle());
    }

    public static function filesProviderFake(): \Generator
    {
        yield [__DIR__.'/../fixtures/fake.xml-not-found'];
        yield [__DIR__.'/../fixtures/fake.zip'];
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
        $driver = new TxtDriver($file);
        $data = $driver->getSections();

        self::assertCount($expectedCount, $data);

        /** @var TxtSection $firstData */
        $firstData = $data[0];

        self::assertSame($expectedTitle, $firstData->getTitle(), $file);
        self::assertSame('text/plain', $firstData->getMediaType(), $file);
        self::assertStringStartsWith($expectedStartText, $firstData->getContent(), $file);
        self::assertStringEndsWith($expectedEndText, $firstData->getContent(), $file);
    }

    #[DataProvider('filesCoverProvider')]
    public function testGetCover(
        string $file,
        ?string $expectedMime,
    ): void {
        $driver = new TxtDriver($file);
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
            __DIR__.'/../fixtures/txt/txt-utf8.txt',
            null,
        ];
        yield [
            __DIR__.'/../fixtures/txt/txt-cover.zip',
            'image/jpeg',
        ];
    }

    public static function filesDataProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/txt/txt-utf8.txt',
            1,
            null,
            "Тестовая книга\n==============",
            'Последняя строка без перевода строки.',
        ];
        yield [
            __DIR__.'/../fixtures/txt/txt-cover.zip',
            1,
            null,
            "Тестовая книга\n==============",
            'Последняя строка без перевода строки.',
        ];
    }

    public static function filesProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/txt/txt-utf8.txt',
            'Тестовая книга', // title
        ];
        yield [
            __DIR__.'/../fixtures/txt/txt-cover.zip',
            'Тестовая книга', // title
        ];
    }
}
