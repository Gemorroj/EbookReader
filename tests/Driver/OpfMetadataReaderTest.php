<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\OpfMetadataReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpfMetadataReaderTest extends TestCase
{
    public function testParsesAllFields(): void
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            .'<package xmlns="http://www.idpf.org/2007/opf" version="1.0">'
            .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            .'<dc:title>Title</dc:title><dc:creator>Author One</dc:creator><dc:creator>Author Two</dc:creator>'
            .'<dc:subject>Subject A</dc:subject><dc:publisher>Publisher</dc:publisher>'
            .'<dc:description>Desc</dc:description><dc:rights>Public domain</dc:rights>'
            .'<dc:language>en</dc:language><dc:identifier>urn:isbn:9781234567890</dc:identifier>'
            .'<dc:date>2010-05-20</dc:date></metadata>'
            .'<manifest><item id="cov" href="images/cover.jpg" media-type="image/jpeg"/></manifest>'
            .'<spine/><meta name="cover" content="cov"/></package>';
        $meta = OpfMetadataReader::fromXml($xml);

        self::assertSame('Title', $meta->getTitle());
        self::assertSame(['Author One', 'Author Two'], $meta->getAuthors());
        self::assertSame('Author One, Author Two', $meta->getAuthor());
        self::assertSame(['Subject A'], $meta->getSubjects());
        self::assertSame('Publisher', $meta->getPublisher());
        self::assertSame('Desc', $meta->getDescription());
        self::assertSame('Public domain', $meta->getLicense());
        self::assertSame('en', $meta->getLanguage());
        self::assertSame('9781234567890', $meta->getIsbn());
        self::assertSame(2010, $meta->getPublishDate()->getYear());
        self::assertSame(5, $meta->getPublishDate()->getMonth());
        self::assertSame(20, $meta->getPublishDate()->getDay());
        self::assertSame('images/cover.jpg', OpfMetadataReader::coverHref($xml));
    }

    public function testMultipleTitlesAreJoined(): void
    {
        $meta = OpfMetadataReader::fromXml(
            '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf">'
            .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            .'<dc:title>Main</dc:title><dc:title>Subtitle</dc:title></metadata></package>'
        );

        self::assertSame('Main, Subtitle', $meta->getTitle());
    }

    public function testCreatorRolesFilterAuthors(): void
    {
        $meta = OpfMetadataReader::fromXml(
            '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" xmlns:opf="http://www.idpf.org/2007/opf">'
            .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>T</dc:title>'
            .'<dc:creator opf:role="aut">Author</dc:creator>'
            .'<dc:creator opf:role="edt">Editor</dc:creator></metadata></package>'
        );

        self::assertSame(['Author'], $meta->getAuthors());
    }

    public function testIsbnFromOpfScheme(): void
    {
        $meta = OpfMetadataReader::fromXml(
            '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" xmlns:opf="http://www.idpf.org/2007/opf">'
            .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>T</dc:title>'
            .'<dc:identifier opf:scheme="ISBN">9781234567890</dc:identifier></metadata></package>'
        );

        self::assertSame('9781234567890', $meta->getIsbn());
    }

    public function testMissingMetadataIsEmpty(): void
    {
        $meta = OpfMetadataReader::fromXml('<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf"><spine/></package>');

        self::assertSame('', $meta->getTitle());
        self::assertSame([], $meta->getAuthors());
        self::assertNull($meta->getPublisher());
        self::assertTrue($meta->getPublishDate()->isEmpty());
    }

    public function testCoverFromRelpath(): void
    {
        $xml = '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf">'
            .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>T</dc:title></metadata>'
            .'<cover-relpath-from-base>cover.jpg</cover-relpath-from-base></package>';

        self::assertSame('cover.jpg', OpfMetadataReader::coverHref($xml));
    }

    #[DataProvider('dateProvider')]
    public function testPublishDatePrecision(string $date, ?int $year, ?int $month, ?int $day): void
    {
        $meta = OpfMetadataReader::fromXml(
            '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf">'
            .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>T</dc:title>'
            .'<dc:date>'.$date.'</dc:date></metadata><spine/></package>'
        );

        self::assertSame($year, $meta->getPublishDate()->getYear());
        self::assertSame($month, $meta->getPublishDate()->getMonth());
        self::assertSame($day, $meta->getPublishDate()->getDay());
    }

    public static function dateProvider(): \Generator
    {
        yield 'year' => ['2008', 2008, null, null];
        yield 'year-month' => ['2008-05', 2008, 5, null];
        yield 'full' => ['2008-05-20', 2008, 5, 20];
        yield 'unparsable' => ['nonsense', null, null, null];
    }

    public function testMalformedXmlYieldsEmpty(): void
    {
        $meta = OpfMetadataReader::fromXml('<<<not xml>>>');

        self::assertSame('', $meta->getTitle());
        self::assertSame([], $meta->getAuthors());
        self::assertNull(OpfMetadataReader::coverHref('<<<not xml>>>'));
        self::assertTrue($meta->getPublishDate()->isEmpty());
    }
}
