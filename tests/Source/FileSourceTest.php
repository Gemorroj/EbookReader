<?php

declare(strict_types=1);

namespace EbookReader\Tests\Source;

use EbookReader\Source\FileSource;
use PHPUnit\Framework\TestCase;

final class FileSourceTest extends TestCase
{
    public function testGetPathReturnsTheGivenPath(): void
    {
        $source = new FileSource('book.epub');
        self::assertSame('book.epub', $source->getPath());
    }

    public function testGetHeadReturnsTheLeadingBytes(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'src');
        self::assertNotFalse($file);
        \file_put_contents($file, 'abcdef');

        try {
            $source = new FileSource($file);
            self::assertSame('abc', $source->getHead(3));
            self::assertSame('abcdef', $source->getHead(100));
            self::assertSame('', $source->getHead(0));
            self::assertSame('', $source->getHead(-1));
        } finally {
            \unlink($file);
        }
    }

    public function testGetHeadOfAnUnreadableFileIsEmpty(): void
    {
        $source = new FileSource(__DIR__.'/../fixtures/does-not-exist.epub');
        self::assertSame('', $source->getHead(68));
    }

    public function testCloseLeavesTheFileInPlace(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'src');
        self::assertNotFalse($file);
        \file_put_contents($file, 'abc');

        try {
            $source = new FileSource($file);
            $source->close();
            self::assertFileExists($file);
        } finally {
            \unlink($file);
        }
    }
}
