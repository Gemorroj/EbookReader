<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Data\TxtData;
use EbookReader\Driver\TxtDriver;
use EbookReader\Exception\ParserException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TxtDriverTest extends TestCase
{
    #[DataProvider('filesProvider')]
    public function testIsValid(string $file, ...$args): void
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
        self::assertNull($meta->getPublishYear());
        self::assertNull($meta->getPublishMonth());
        self::assertNull($meta->getPublishDay());
    }

    #[DataProvider('filesProviderFake')]
    public function testGetMetaFake(string $file): void
    {
        $driver = new TxtDriver($file);
        $this->expectException(ParserException::class);
        $driver->getMeta();
    }

    public static function filesProviderFake(): \Generator
    {
        yield [__DIR__.'/../fixtures/fake.xml-not-found'];
        yield [__DIR__.'/../fixtures/fake.zip'];
    }

    #[DataProvider('filesDataProvider')]
    public function testGetData(
        string $file,
        int $expectedCount,
        string $expectedTitle,
        string $expectedStartText,
        string $expectedEndText,
    ): void {
        $driver = new TxtDriver($file);
        $data = $driver->getData();

        self::assertCount($expectedCount, $data);

        /** @var TxtData $firstData */
        $firstData = $data[0];

        self::assertSame($expectedTitle, $firstData->getTitle(), $file);
        self::assertStringStartsWith($expectedStartText, $firstData->getText(), $file);
        self::assertStringEndsWith($expectedEndText, $firstData->getText(), $file);

        self::assertEmpty($firstData->getStyles(), $file);
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
            __DIR__.'/../fixtures/txt/Sukonkin_Pleyada.txt',
            null,
        ];
        yield [
            __DIR__.'/../fixtures/txt/Sukonkin_Pleyada.txt.zip',
            'image/jpeg',
        ];
    }

    public static function filesDataProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/txt/Sukonkin_Pleyada.txt',
            1,
            'Алексей Суконкин',
            "Алексей Суконкин\n".
            "   \u{a0}\n".
            "   ПЛЕЯДА\n".
            "\n".
            "   \u{a0}\n".
            "   Все события в книге вымышлены,\n".
            '   а совпадения совершенно случайны.',
            "   Даже такой страшной ценой, как собственная жизнь.\n".
            "   \u{a0}\n".
            '   2024-2025г.г.',
        ];
        yield [
            __DIR__.'/../fixtures/txt/Sukonkin_Pleyada.txt.zip',
            1,
            'Алексей Суконкин',
            "Алексей Суконкин\n".
            "   \u{a0}\n".
            "   ПЛЕЯДА\n".
            "\n".
            "   \u{a0}\n".
            "   Все события в книге вымышлены,\n".
            '   а совпадения совершенно случайны.',
            "   Даже такой страшной ценой, как собственная жизнь.\n".
            "   \u{a0}\n".
            '   2024-2025г.г.',
        ];
    }

    public static function filesProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/txt/Sukonkin_Pleyada.txt',
            'Алексей Суконкин', // title
        ];
        yield [
            __DIR__.'/../fixtures/txt/Sukonkin_Pleyada.txt.zip',
            'Алексей Суконкин', // title
        ];
    }
}
