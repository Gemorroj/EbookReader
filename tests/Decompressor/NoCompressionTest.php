<?php

declare(strict_types=1);

namespace EbookReader\Tests\Decompressor;

use EbookReader\Decompressor\NoCompression;
use PHPUnit\Framework\TestCase;

final class NoCompressionTest extends TestCase
{
    public function testReturnsDataUnchanged(): void
    {
        self::assertSame('as-is', new NoCompression()->decompress('as-is'));
    }

    public function testEmpty(): void
    {
        self::assertSame('', new NoCompression()->decompress(''));
    }
}
