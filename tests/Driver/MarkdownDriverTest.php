<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\MarkdownDriver;
use EbookReader\Exception\ParserException;
use EbookReader\Model\Format;
use EbookReader\Model\Resource\EbookResourceInterface;
use EbookReader\Model\Section\Section;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownDriverTest extends TestCase
{
    public function testGetFormat(): void
    {
        self::assertSame(Format::MARKDOWN, new MarkdownDriver('file.md')->getFormat());
    }

    #[DataProvider('validFilesProvider')]
    public function testIsValid(string $file, mixed ...$args): void
    {
        self::assertTrue(new MarkdownDriver($file)->isValid());
    }

    #[DataProvider('invalidFilesProvider')]
    public function testIsValidRejectsNonMarkdown(string $file): void
    {
        self::assertFalse(new MarkdownDriver($file)->isValid());
    }

    #[DataProvider('validFilesProvider')]
    public function testGetMeta(string $file, string $expectedTitle): void
    {
        $meta = new MarkdownDriver($file)->getMeta();
        self::assertSame($expectedTitle, $meta->getTitle());
        self::assertNull($meta->getAuthor());
        self::assertNull($meta->getPublisher());
        self::assertNull($meta->getIsbn());
        self::assertNull($meta->getDescription());
        self::assertNull($meta->getLanguage());
        self::assertNull($meta->getLicense());
        self::assertTrue($meta->getPublishDate()->isEmpty());
    }

    /**
     * @param non-empty-string $expectedStartText
     * @param non-empty-string $expectedEndText
     */
    #[DataProvider('dataProvider')]
    public function testGetData(
        string $file,
        string $expectedTitle,
        string $expectedStartText,
        string $expectedEndText,
    ): void {
        $sections = new MarkdownDriver($file)->getSections();

        self::assertCount(1, $sections);

        /** @var Section $section */
        $section = $sections[0];
        self::assertSame('0', $section->getId());
        self::assertSame($expectedTitle, $section->getTitle());
        self::assertSame('text/markdown', $section->getMediaType());
        self::assertStringStartsWith($expectedStartText, $section->getContent(), $file);
        self::assertStringEndsWith($expectedEndText, $section->getContent(), $file);
        self::assertSame([], $section->getResources());
    }

    #[DataProvider('coverProvider')]
    public function testGetCover(string $file, ?string $expectedMime): void
    {
        $cover = new MarkdownDriver($file)->getCover();

        if (null === $expectedMime) {
            self::assertNull($cover);

            return;
        }

        self::assertNotNull($cover);
        self::assertSame($expectedMime, $cover->getMime());
        self::assertNotEmpty($cover->getData());
    }

    public function testGetMetaOnUnreadableFileThrows(): void
    {
        $this->expectException(ParserException::class);
        new MarkdownDriver(__DIR__.'/../fixtures/fake.xml-not-found')->getMeta();
    }

    public function testGetSections(): void
    {
        $sections = new MarkdownDriver(__DIR__.'/../fixtures/markdown/markdown-book.md')->getSections();

        self::assertCount(1, $sections);

        /** @var Section $section */
        $section = $sections[0];
        self::assertSame('The Book of Markdown', $section->getTitle());
        // A fenced code block is part of the source, not a heading.
        self::assertStringContainsString("```php\n# not a heading", $section->getContent());
    }

    public function testGetTableOfContents(): void
    {
        $toc = new MarkdownDriver(__DIR__.'/../fixtures/markdown/markdown-book.md')->getTableOfContents();

        self::assertCount(5, $toc);

        self::assertSame('The Book of Markdown', $toc[0]->getTitle());
        self::assertSame(1, $toc[0]->getLevel());
        self::assertSame(0, $toc[0]->getSectionIndex());
        self::assertNull($toc[0]->getAnchor());

        self::assertSame('Chapter One', $toc[1]->getTitle());
        self::assertSame(2, $toc[1]->getLevel());

        self::assertSame('Section A', $toc[2]->getTitle());
        self::assertSame(3, $toc[2]->getLevel());

        self::assertSame('Chapter Two', $toc[3]->getTitle());

        // Setext heading, level 1.
        self::assertSame('Title Setext', $toc[4]->getTitle());
        self::assertSame(1, $toc[4]->getLevel());
    }

    public function testCyrillicTableOfContents(): void
    {
        $toc = new MarkdownDriver(__DIR__.'/../fixtures/markdown/markdown-cyrillic.md')->getTableOfContents();

        self::assertCount(3, $toc);
        self::assertSame('Тестовая книга', $toc[0]->getTitle());
        self::assertSame('Глава 1', $toc[1]->getTitle());
        self::assertSame(2, $toc[1]->getLevel());
        self::assertSame('Раздел первый', $toc[2]->getTitle());
        self::assertSame(3, $toc[2]->getLevel());
    }

    public function testHeadingTitlesAreReducedToText(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'md');
        self::assertNotFalse($file);
        \file_put_contents($file, "# The **Great** Book\n\n## A [link](https://example.com)\n");

        try {
            $toc = new MarkdownDriver($file)->getTableOfContents();
            self::assertSame('The Great Book', $toc[0]->getTitle());
            self::assertSame('A link', $toc[1]->getTitle());
        } finally {
            \unlink($file);
        }
    }

    public function testIsValidRecognisesBlockConstructs(): void
    {
        $documents = [
            "# Heading\n",
            "```\ncode\n```\n",
            "A [link](https://example.com).\n",
            "| a | b |\n| --- | --- |\n| 1 | 2 |\n",
        ];

        foreach ($documents as $markdown) {
            $file = \tempnam(\sys_get_temp_dir(), 'md');
            self::assertNotFalse($file);
            \file_put_contents($file, $markdown);

            try {
                self::assertTrue(new MarkdownDriver($file)->isValid(), $markdown);
            } finally {
                \unlink($file);
            }
        }
    }

    public function testGetMetaFallsBackToFirstLineWhenThereIsNoHeading(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'md');
        self::assertNotFalse($file);
        \file_put_contents($file, "Just a line\n\n```\ncode\n```\n");

        try {
            $driver = new MarkdownDriver($file);
            self::assertTrue($driver->isValid());
            self::assertSame([], $driver->getTableOfContents());
            self::assertSame('Just a line', $driver->getMeta()->getTitle());
        } finally {
            \unlink($file);
        }
    }

    public function testZipMetadataCoverAndAssets(): void
    {
        $driver = new MarkdownDriver(__DIR__.'/../fixtures/markdown/markdown-cover.zip');
        self::assertTrue($driver->isValid());

        $sections = $driver->getSections();
        self::assertSame('book.md', $sections[0]->getId());

        $meta = $driver->getMeta();
        self::assertSame('Zipped Markdown', $meta->getTitle());
        self::assertSame('Jane Doe', $meta->getAuthor());
        self::assertSame('9781234567890', $meta->getIsbn());
        self::assertSame('en', $meta->getLanguage());
        self::assertSame(2011, $meta->getPublishDate()->getYear());
        self::assertSame(6, $meta->getPublishDate()->getMonth());
        self::assertSame(15, $meta->getPublishDate()->getDay());

        $cover = $driver->getCover();
        self::assertNotNull($cover);
        self::assertSame('image/jpeg', $cover->getMime());

        $paths = \array_map(static fn (EbookResourceInterface $resource): string => $resource->getPath(), $driver->getResources());
        self::assertContains('cover.jpg', $paths);
        self::assertNotContains('book.md', $paths);

        self::assertSame('The Book of Markdown', $driver->getTableOfContents()[0]->getTitle());
    }

    public function testZipMarkdownEntriesAreConcatenated(): void
    {
        $file = self::makeZip([
            'a.md' => "# First\n\nAa.\n",
            'b.md' => "# Second\n\nBb.\n",
        ]);

        try {
            $driver = new MarkdownDriver($file);
            self::assertSame('a.md', $driver->getSections()[0]->getId());

            $content = $driver->getSections()[0]->getContent();
            self::assertStringContainsString('# First', $content);
            self::assertStringContainsString('# Second', $content);

            $titles = \array_map(static fn ($entry): string => $entry->getTitle(), $driver->getTableOfContents());
            self::assertSame(['First', 'Second'], $titles);
        } finally {
            \unlink($file);
        }
    }

    public function testZipWithAssetsKeepsArchiveRelativePaths(): void
    {
        $file = self::makeZip([
            'book.md' => "# Book\n\n![pic](images/pic.png)\n",
            'images/pic.png' => (string) \file_get_contents(__DIR__.'/../fixtures/images/png.png'),
        ]);

        try {
            $driver = new MarkdownDriver($file);
            // The Markdown reference and the exposed resource share the path.
            self::assertStringContainsString('![pic](images/pic.png)', $driver->getSections()[0]->getContent());

            $paths = \array_map(static fn (EbookResourceInterface $resource): string => $resource->getPath(), $driver->getResources());
            self::assertContains('images/pic.png', $paths);
        } finally {
            \unlink($file);
        }
    }

    /**
     * @param array<string, string> $entries
     */
    private static function makeZip(array $entries): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'mdz');
        self::assertNotFalse($file);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $file;
    }

    public static function validFilesProvider(): \Generator
    {
        yield [__DIR__.'/../fixtures/markdown/markdown-book.md', 'The Book of Markdown'];
        yield [__DIR__.'/../fixtures/markdown/markdown-cyrillic.md', 'Тестовая книга'];
        yield [__DIR__.'/../fixtures/markdown/markdown-tables.md', 'Tables'];
    }

    public static function invalidFilesProvider(): \Generator
    {
        yield [__DIR__.'/../fixtures/markdown/markdown-plain.md'];
        // Setext underlines alone are too common in plain text to be Markdown.
        yield [__DIR__.'/../fixtures/markdown/markdown-setext-only.md'];
        yield [__DIR__.'/../fixtures/fake.xml'];
        yield [__DIR__.'/../fixtures/txt/txt-utf8.txt'];
        yield [__DIR__.'/../fixtures/txt/txt-chapters.txt'];
        yield [__DIR__.'/../fixtures/txt/txt-single-line.txt'];
    }

    public static function coverProvider(): \Generator
    {
        yield [__DIR__.'/../fixtures/markdown/markdown-book.md', null];
        yield [__DIR__.'/../fixtures/markdown/markdown-cover.zip', 'image/jpeg'];
    }

    public static function dataProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/markdown/markdown-book.md',
            'The Book of Markdown',
            "# The Book of Markdown\n\nIntro paragraph.",
            'Body.',
        ];
        yield [
            __DIR__.'/../fixtures/markdown/markdown-cyrillic.md',
            'Тестовая книга',
            "# Тестовая книга\n\nАвтор: Тест Тестов",
            '- пункт два',
        ];
        yield [
            __DIR__.'/../fixtures/markdown/markdown-tables.md',
            'Tables',
            "# Tables\n\n| Name  | Value |",
            '[link](https://example.com).',
        ];
    }
}
