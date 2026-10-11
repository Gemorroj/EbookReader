<?php

declare(strict_types=1);

namespace EbookReader\Tests\Decompressor;

use EbookReader\Decompressor\PalmDocLz77;
use PHPUnit\Framework\TestCase;

final class PalmDocLz77Test extends TestCase
{
    public function testLiteralRun(): void
    {
        // 0x03 => copy the next 3 bytes literally
        self::assertSame('abc', new PalmDocLz77()->decompress("\x03abc"));
    }

    public function testLiteralBytes(): void
    {
        // bytes in 0x09..0x7F are literals
        self::assertSame('AZ', new PalmDocLz77()->decompress('AZ'));
    }

    public function testSpaceAndXor(): void
    {
        // 0xC1 => space + (0xC1 ^ 0x80) = "A"
        self::assertSame(' A', new PalmDocLz77()->decompress("\xC1"));
    }

    public function testBackReference(): void
    {
        // "ab" then a back-reference: distance 2, length 3 -> "ababa"
        // word = 0x8000 | (distance << 3) | (length - 3) = 0x8010
        self::assertSame('ababa', new PalmDocLz77()->decompress("ab\x80\x10"));
    }

    public function testBackReferenceBeyondBufferIsIgnored(): void
    {
        // 0x81 0x00 => back-reference with distance 32 into an empty buffer, then literal "A"
        self::assertSame('A', new PalmDocLz77()->decompress("\x81\x00A"));
    }
}
