<?php

declare(strict_types=1);

namespace EbookReader\Tests\Model\Meta;

use EbookReader\Model\Meta\Metadata;
use EbookReader\Model\Meta\PublishDate;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    public function testDefaultsAreEmpty(): void
    {
        $meta = new Metadata();

        self::assertSame('', $meta->getTitle());
        self::assertSame([], $meta->getAuthors());
        self::assertSame([], $meta->getContributors());
        self::assertNull($meta->getAuthor());
        self::assertNull($meta->getSeries());
        self::assertNull($meta->getSeriesIndex());
        self::assertTrue($meta->getPublishDate()->isEmpty());
    }

    public function testWithTitlePreservesEveryField(): void
    {
        $meta = new Metadata(
            title: 'Old',
            authors: ['A'],
            contributors: ['E'],
            publisher: 'P',
            isbn: 'I',
            description: 'D',
            language: 'en',
            subjects: ['S'],
            license: 'L',
            publishDate: new PublishDate(2020, 5, 1),
            series: 'Serie',
            seriesIndex: 2.5,
        );

        $renamed = $meta->withTitle('New');

        self::assertSame('New', $renamed->getTitle());
        self::assertSame(['A'], $renamed->getAuthors());
        self::assertSame(['E'], $renamed->getContributors());
        self::assertSame('P', $renamed->getPublisher());
        self::assertSame('I', $renamed->getIsbn());
        self::assertSame('D', $renamed->getDescription());
        self::assertSame('en', $renamed->getLanguage());
        self::assertSame(['S'], $renamed->getSubjects());
        self::assertSame('L', $renamed->getLicense());
        self::assertSame(2020, $renamed->getPublishDate()->getYear());
        self::assertSame('Serie', $renamed->getSeries());
        self::assertSame(2.5, $renamed->getSeriesIndex());
    }
}
