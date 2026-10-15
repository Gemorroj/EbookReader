<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver\Zip;

use EbookReader\Driver\Zip\Reader as ZipReader;
use EbookReader\Exception\ParserException;
use PHPUnit\Framework\TestCase;

final class ReaderTest extends TestCase
{
    public function testOpenReturnsNullForNonZip(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'notzip');
        self::assertNotFalse($file);
        \file_put_contents($file, 'plain text, not a zip');

        try {
            self::assertNull(ZipReader::open($file));
        } finally {
            \unlink($file);
        }
    }

    public function testOpenReturnsNullForMissingFile(): void
    {
        self::assertNull(ZipReader::open(__DIR__.'/../../fixtures/does-not-exist.zip'));
    }

    public function testAllEntriesSkipsDirectoriesInArchiveOrder(): void
    {
        $file = self::makeZip([
            'b.txt' => 'B',
            'dir/' => '',
            'a.txt' => 'A',
        ]);

        try {
            $zip = ZipReader::open($file);
            self::assertNotNull($zip);

            try {
                self::assertSame(['b.txt', 'a.txt'], $zip->allEntries());
            } finally {
                $zip->close();
            }
        } finally {
            \unlink($file);
        }
    }

    public function testEntriesFilterByExtensionAndSortNaturally(): void
    {
        $file = self::makeZip([
            'chapter10.md' => '',
            'chapter2.md' => '',
            'cover.png' => '',
            'notes.txt' => '',
        ]);

        try {
            $zip = ZipReader::open($file);
            self::assertNotNull($zip);

            try {
                self::assertSame(['chapter2.md', 'chapter10.md'], $zip->entries(['md']));
                self::assertSame(['cover.png'], $zip->entries(['png']));
                self::assertSame([], $zip->entries(['html']));
            } finally {
                $zip->close();
            }
        } finally {
            \unlink($file);
        }
    }

    public function testReadAndTryRead(): void
    {
        $file = self::makeZip(['data.txt' => 'hello']);

        try {
            $zip = ZipReader::open($file);
            self::assertNotNull($zip);

            try {
                self::assertSame('hello', $zip->read('data.txt'));
                self::assertSame('hello', $zip->tryRead('data.txt'));
                self::assertNull($zip->tryRead('missing.txt'));
            } finally {
                $zip->close();
            }
        } finally {
            \unlink($file);
        }
    }

    public function testReadThrowsForMissingEntry(): void
    {
        $file = self::makeZip(['data.txt' => 'hello']);

        try {
            $zip = ZipReader::open($file);
            self::assertNotNull($zip);

            try {
                $this->expectException(ParserException::class);
                $zip->read('missing.txt');
            } finally {
                $zip->close();
            }
        } finally {
            \unlink($file);
        }
    }

    public function testHeadReadsLeadingBytes(): void
    {
        $file = self::makeZip(['data.txt' => 'abcdefghij']);

        try {
            $zip = ZipReader::open($file);
            self::assertNotNull($zip);

            try {
                self::assertSame('abc', $zip->head('data.txt', 3));
            } finally {
                $zip->close();
            }
        } finally {
            \unlink($file);
        }
    }

    public function testHeadThrowsForMissingEntry(): void
    {
        $file = self::makeZip(['data.txt' => 'hello']);

        try {
            $zip = ZipReader::open($file);
            self::assertNotNull($zip);

            try {
                $this->expectException(ParserException::class);
                $zip->head('missing.txt', 3);
            } finally {
                $zip->close();
            }
        } finally {
            \unlink($file);
        }
    }

    /**
     * @param array<string, string> $entries a name ending in "/" is a directory
     */
    private static function makeZip(array $entries): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'zipreader');
        self::assertNotFalse($file);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            if (\str_ends_with($name, '/')) {
                $zip->addEmptyDir(\rtrim($name, '/'));
                continue;
            }
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $file;
    }
}
