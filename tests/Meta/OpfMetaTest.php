<?php

declare(strict_types=1);

namespace EbookReader\Tests\Meta;

use EbookReader\Meta\OpfMeta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpfMetaTest extends TestCase
{
    public function testParsesAllFields(): void
    {
        self::withArchive([
            'metadata.opf' => '<?xml version="1.0" encoding="utf-8"?>'
                .'<package xmlns="http://www.idpf.org/2007/opf" version="1.0">'
                .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
                .'<dc:title>Title</dc:title><dc:creator>Author One</dc:creator><dc:creator>Author Two</dc:creator>'
                .'<dc:subject>Subject A</dc:subject><dc:publisher>Publisher</dc:publisher>'
                .'<dc:description>Desc</dc:description><dc:rights>Public domain</dc:rights>'
                .'<dc:language>en</dc:language><dc:identifier>urn:isbn:9781234567890</dc:identifier>'
                .'<dc:date>2010-05-20</dc:date></metadata>'
                .'<manifest><item id="cov" href="images/cover.jpg" media-type="image/jpeg"/></manifest>'
                .'<spine/><meta name="cover" content="cov"/></package>',
            'images/cover.jpg' => 'JPEG',
        ], static function (\ZipArchive $zip): void {
            $meta = OpfMeta::fromArchive($zip);

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
            self::assertSame('images/cover.jpg', $meta->getCoverHref());
        });
    }

    public function testCoverFromRelpath(): void
    {
        self::withArchive([
            'metadata.opf' => '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf">'
                .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>T</dc:title></metadata>'
                .'<cover-relpath-from-base>cover.jpg</cover-relpath-from-base></package>',
            'cover.jpg' => 'JPEG',
        ], static function (\ZipArchive $zip): void {
            self::assertSame('cover.jpg', OpfMeta::fromArchive($zip)->getCoverHref());
        });
    }

    public function testCoverHrefMustExistInArchive(): void
    {
        self::withArchive([
            'metadata.opf' => '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf">'
                .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>T</dc:title></metadata>'
                .'<manifest><item id="cov" href="missing.jpg" media-type="image/jpeg"/></manifest>'
                .'<meta name="cover" content="cov"/></package>',
        ], static function (\ZipArchive $zip): void {
            self::assertNull(OpfMeta::fromArchive($zip)->getCoverHref());
        });
    }

    #[DataProvider('dateProvider')]
    public function testPublishDatePrecision(string $date, ?int $year, ?int $month, ?int $day): void
    {
        self::withArchive([
            'metadata.opf' => '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf">'
                .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>T</dc:title>'
                .'<dc:date>'.$date.'</dc:date></metadata><spine/></package>',
        ], static function (\ZipArchive $zip) use ($year, $month, $day): void {
            $publishDate = OpfMeta::fromArchive($zip)->getPublishDate();
            self::assertSame($year, $publishDate->getYear());
            self::assertSame($month, $publishDate->getMonth());
            self::assertSame($day, $publishDate->getDay());
        });
    }

    public static function dateProvider(): \Generator
    {
        yield 'year' => ['2008', 2008, null, null];
        yield 'year-month' => ['2008-05', 2008, 5, null];
        yield 'full' => ['2008-05-20', 2008, 5, 20];
        yield 'unparsable' => ['nonsense', null, null, null];
    }

    public function testMalformedMetadataYieldsEmpty(): void
    {
        self::withArchive(['metadata.opf' => '<<<not xml>>>'], static function (\ZipArchive $zip): void {
            $meta = OpfMeta::fromArchive($zip);
            self::assertSame('', $meta->getTitle());
            self::assertSame([], $meta->getAuthors());
            self::assertNull($meta->getCoverHref());
            self::assertTrue($meta->getPublishDate()->isEmpty());
        });
    }

    public function testMissingMetadataYieldsEmpty(): void
    {
        self::withArchive(['book.txt' => 'text'], static function (\ZipArchive $zip): void {
            $meta = OpfMeta::fromArchive($zip);
            self::assertSame('', $meta->getTitle());
            self::assertNull($meta->getAuthor());
            self::assertNull($meta->getCoverHref());
        });
    }

    /**
     * @param array<string, string>       $entries
     * @param callable(\ZipArchive): void $assert
     */
    private static function withArchive(array $entries, callable $assert): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'opf');
        self::assertNotFalse($file);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        try {
            $assert($zip);
        } finally {
            $zip->close();
            \unlink($file);
        }
    }
}
