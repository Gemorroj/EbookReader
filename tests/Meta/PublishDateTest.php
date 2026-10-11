<?php

declare(strict_types=1);

namespace EbookReader\Tests\Meta;

use EbookReader\Meta\PublishDate;
use PHPUnit\Framework\TestCase;

final class PublishDateTest extends TestCase
{
    public function testEmpty(): void
    {
        $date = new PublishDate();

        self::assertTrue($date->isEmpty());
        self::assertNull($date->getYear());
        self::assertNull($date->getMonth());
        self::assertNull($date->getDay());
        self::assertNull($date->toDateTimeImmutable());
    }

    public function testYearOnly(): void
    {
        $date = new PublishDate(1912);

        self::assertFalse($date->isEmpty());
        self::assertSame(1912, $date->getYear());
        self::assertNull($date->getMonth());
        self::assertNull($date->getDay());
        self::assertSame('1912-01-01', $date->toDateTimeImmutable()?->format('Y-m-d'));
    }

    public function testFullDate(): void
    {
        $date = new PublishDate(2008, 5, 20);

        self::assertSame('2008-05-20', $date->toDateTimeImmutable()?->format('Y-m-d'));
    }

    public function testInvalidDateDoesNotThrow(): void
    {
        $date = new PublishDate(2008, 13, 40);

        self::assertNull($date->toDateTimeImmutable());
    }
}
