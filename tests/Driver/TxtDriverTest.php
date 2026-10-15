<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\TxtDriver;
use EbookReader\Encoding\TextDecoder;
use EbookReader\Exception\ParserException;
use EbookReader\Model\Format;
use EbookReader\Model\Section\Section;
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

    public function testTableOfContentsIsEmptyForUnstructuredText(): void
    {
        self::assertSame([], new TxtDriver(__DIR__.'/../fixtures/txt/txt-single-line.txt')->getTableOfContents());
    }

    public function testChaptersBecomeSectionsAndToc(): void
    {
        $driver = new TxtDriver(__DIR__.'/../fixtures/txt/txt-chapters.txt');

        $sections = $driver->getSections();
        self::assertCount(4, $sections);
        self::assertNull($sections[0]->getTitle());
        self::assertSame('Chapter 1', $sections[1]->getTitle());
        self::assertSame('Chapter 2', $sections[2]->getTitle());
        self::assertSame('Глава 3', $sections[3]->getTitle());

        $toc = $driver->getTableOfContents();
        self::assertCount(3, $toc);
        self::assertSame('Chapter 1', $toc[0]->getTitle());
        self::assertSame(1, $toc[0]->getSectionIndex());
        self::assertSame('Глава 3', $toc[2]->getTitle());
    }

    public function testZipTextFilesAreConcatenated(): void
    {
        $file = self::makeZip([
            'a.txt' => 'First part.',
            'b.txt' => 'Second part.',
        ]);

        try {
            $sections = new TxtDriver($file)->getSections();
            self::assertCount(1, $sections);
            self::assertStringContainsString('First part.', $sections[0]->getContent());
            self::assertStringContainsString('Second part.', $sections[0]->getContent());
        } finally {
            \unlink($file);
        }
    }

    public function testZipMetadataAndCover(): void
    {
        $file = self::makeZip([
            'book.txt' => "Chapter 1\n\nHello.",
            'cover.jpg' => (string) \file_get_contents(__DIR__.'/../fixtures/images/jpeg.jpg'),
            'metadata.opf' => '<?xml version="1.0" encoding="utf-8"?>'
                .'<package xmlns="http://www.idpf.org/2007/opf" version="1.0">'
                .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">'
                .'<dc:title>Zipped Book</dc:title><dc:creator>Jane Doe</dc:creator>'
                .'<dc:identifier opf:scheme="ISBN">urn:isbn:9781234567890</dc:identifier>'
                .'<dc:language>en</dc:language><dc:date>2010-05-20</dc:date></metadata>'
                .'<manifest><item id="cover-img" href="cover.jpg" media-type="image/jpeg"/></manifest>'
                .'<spine/><meta name="cover" content="cover-img"/></package>',
        ]);

        try {
            $driver = new TxtDriver($file);

            $meta = $driver->getMeta();
            self::assertSame('Zipped Book', $meta->getTitle());
            self::assertSame('Jane Doe', $meta->getAuthor());
            self::assertSame('9781234567890', $meta->getIsbn());
            self::assertSame('en', $meta->getLanguage());
            self::assertSame(2010, $meta->getPublishDate()->getYear());
            self::assertSame(5, $meta->getPublishDate()->getMonth());
            self::assertSame(20, $meta->getPublishDate()->getDay());

            $cover = $driver->getCover();
            self::assertNotNull($cover);
            self::assertSame('image/jpeg', $cover->getMime());
        } finally {
            \unlink($file);
        }
    }

    /**
     * @param array<string, string> $entries
     */
    private static function makeZip(array $entries): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'txtz');
        self::assertNotFalse($file);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $file;
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

    public function testInvalidUtf8TailIsRejectedByStrictDecoder(): void
    {
        // Valid UTF-8 in the first 4 KiB, an invalid byte after it.
        $file = \tempnam(\sys_get_temp_dir(), 'txt');
        self::assertNotFalse($file);
        \file_put_contents($file, \str_repeat('a', 4096)."\xE9");

        try {
            $driver = new TxtDriver($file, textDecoder: TextDecoder::strict());
            self::assertTrue($driver->isValid());

            $this->expectException(ParserException::class);
            $driver->getSections();
        } finally {
            \unlink($file);
        }
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

        /** @var Section $firstData */
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
            2,
            null,
            "Тестовая книга\n==============",
            'Автор: Тест Тестов',
        ];
        yield [
            __DIR__.'/../fixtures/txt/txt-cover.zip',
            2,
            null,
            "Тестовая книга\n==============",
            'Автор: Тест Тестов',
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
