<?php

declare(strict_types=1);

namespace EbookReader\Tests\Source;

use EbookReader\Source\StringSource;
use PHPUnit\Framework\TestCase;

final class StringSourceTest extends TestCase
{
    public function testGetHeadReturnsTheLeadingBytes(): void
    {
        $source = new StringSource('abcdef');
        self::assertSame('abc', $source->getHead(3));
        self::assertSame('abcdef', $source->getHead(100));
        self::assertSame('', $source->getHead(0));
        self::assertSame('', $source->getHead(-1));
    }

    public function testGetPathMaterializesTheContents(): void
    {
        $source = new StringSource('book contents');
        $path = $source->getPath();

        try {
            self::assertFileExists($path);
            self::assertSame('book contents', \file_get_contents($path));
            self::assertSame($path, $source->getPath());
        } finally {
            $source->close();
        }
    }

    public function testCloseRemovesTheTemporaryFile(): void
    {
        $source = new StringSource('book contents');
        $path = $source->getPath();

        $source->close();

        self::assertFileDoesNotExist($path);
    }

    public function testCloseBeforeMaterializingIsSafe(): void
    {
        $source = new StringSource('book contents');

        $source->close();

        self::assertSame('bo', $source->getHead(2));
    }

    public function testDestructorRemovesTheTemporaryFile(): void
    {
        $source = new StringSource('book contents');
        $path = $source->getPath();

        unset($source);

        self::assertFileDoesNotExist($path);
    }

    public function testGetPathAfterCloseMaterializesAgain(): void
    {
        $source = new StringSource('book contents');
        $first = $source->getPath();

        $source->close();
        $second = $source->getPath();

        try {
            self::assertNotSame($first, $second);
            self::assertSame('book contents', \file_get_contents($second));
        } finally {
            $source->close();
        }
    }
}
