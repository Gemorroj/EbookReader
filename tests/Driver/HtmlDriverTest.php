<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\HtmlDriver;
use EbookReader\Model\Format;
use EbookReader\Model\Resource\EbookResourceInterface;
use EbookReader\Model\Section\HtmlSection;
use PHPUnit\Framework\TestCase;

final class HtmlDriverTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../fixtures/html/simple.html';

    public function testGetFormat(): void
    {
        self::assertSame(Format::HTML, new HtmlDriver('file.html')->getFormat());
    }

    public function testIsValid(): void
    {
        self::assertTrue(new HtmlDriver(self::FIXTURE)->isValid());
        self::assertFalse(new HtmlDriver(__DIR__.'/../fixtures/fake.xml')->isValid());
        self::assertFalse(new HtmlDriver(__DIR__.'/../fixtures/fake.zip')->isValid());
    }

    public function testIsValidRejectsHtmlEmbeddedInAnotherContainer(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'html');
        self::assertNotFalse($file);
        // A binary container whose payload merely contains an HTML document.
        \file_put_contents($file, "\x00\x01PalmDoc\x00\x02".'<html><body>hi</body></html>');

        try {
            self::assertFalse(new HtmlDriver($file)->isValid());
        } finally {
            \unlink($file);
        }
    }

    public function testIsValidAcceptsBomPrologAndComment(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'html');
        self::assertNotFalse($file);
        \file_put_contents(
            $file,
            "\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<!-- a comment -->\n<!DOCTYPE html><html><body>x</body></html>"
        );

        try {
            self::assertTrue(new HtmlDriver($file)->isValid());
        } finally {
            \unlink($file);
        }
    }

    public function testGetMeta(): void
    {
        $meta = new HtmlDriver(self::FIXTURE)->getMeta();

        self::assertSame('Simple Book', $meta->getTitle());
        self::assertNull($meta->getAuthor());
    }

    public function testGetSections(): void
    {
        $data = new HtmlDriver(self::FIXTURE)->getSections();

        self::assertCount(1, $data);

        /** @var HtmlSection $first */
        $first = $data[0];
        self::assertSame('0', $first->getId());
        self::assertSame('Simple Book', $first->getTitle());
        self::assertSame('text/html', $first->getMediaType());
        self::assertStringContainsString('<h1 id="chapter-1">Chapter One</h1>', $first->getContent());
        self::assertSame([], $first->getResources());
    }

    public function testGetTableOfContents(): void
    {
        $toc = new HtmlDriver(self::FIXTURE)->getTableOfContents();

        self::assertCount(2, $toc);
        self::assertSame('Chapter One', $toc[0]->getTitle());
        self::assertSame(1, $toc[0]->getLevel());
        self::assertSame(0, $toc[0]->getSectionIndex());
        self::assertSame('chapter-1', $toc[0]->getAnchor());

        self::assertSame('A Section', $toc[1]->getTitle());
        self::assertSame(2, $toc[1]->getLevel());
        self::assertSame('section-1', $toc[1]->getAnchor());
    }

    public function testTableOfContentsIgnoresCommentsAndScripts(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'html');
        self::assertNotFalse($file);
        \file_put_contents(
            $file,
            '<html><head><title>x</title></head><body>'
            .'<!-- <h1>Commented</h1> -->'
            .'<script>var s = "<h1>InScript</h1>";</script>'
            .'<style>/* <h1>InStyle</h1> */</style>'
            .'<h1 id="real">Real <em>Heading</em></h1>'
            .'</body></html>'
        );

        try {
            $toc = new HtmlDriver($file)->getTableOfContents();
            self::assertCount(1, $toc);
            self::assertSame('Real Heading', $toc[0]->getTitle());
            self::assertSame('real', $toc[0]->getAnchor());
        } finally {
            \unlink($file);
        }
    }

    public function testHeadingWithoutIdHasNoAnchor(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'html');
        self::assertNotFalse($file);
        \file_put_contents($file, '<html><body><h1>No Anchor</h1></body></html>');

        try {
            $toc = new HtmlDriver($file)->getTableOfContents();
            self::assertCount(1, $toc);
            self::assertSame('No Anchor', $toc[0]->getTitle());
            self::assertNull($toc[0]->getAnchor());
        } finally {
            \unlink($file);
        }
    }

    public function testCoverAndResourcesAreEmpty(): void
    {
        $driver = new HtmlDriver(self::FIXTURE);

        self::assertSame([], $driver->getResources());
        self::assertNull($driver->getCover());
    }

    public function testDeclaredCharsetIsApplied(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'html');
        self::assertNotFalse($file);
        // "Café" / "Résumé" in Windows-1252, declared via <meta>
        \file_put_contents(
            $file,
            '<!DOCTYPE html><html><head><meta charset="windows-1252"><title>Caf'."\xE9".'</title></head>'
            .'<body><p>R'."\xE9".'sum'."\xE9".'</p></body></html>'
        );

        try {
            $driver = new HtmlDriver($file);
            self::assertTrue($driver->isValid());
            self::assertSame("Caf\xC3\xA9", $driver->getMeta()->getTitle());
            self::assertStringContainsString("R\xC3\xA9sum\xC3\xA9", $driver->getSections()[0]->getContent());
        } finally {
            \unlink($file);
        }
    }

    public function testHtmlzWithMetadataCoverAndAssets(): void
    {
        $file = self::makeZip([
            'index.html' => '<html><head><title>Doc</title></head><body><h1 id="start">Start</h1></body></html>',
            'style.css' => 'body{color:red}',
            'images/pic.png' => (string) \file_get_contents(__DIR__.'/../fixtures/images/png.png'),
            'cover.jpg' => (string) \file_get_contents(__DIR__.'/../fixtures/images/jpeg.jpg'),
            'metadata.opf' => '<?xml version="1.0" encoding="utf-8"?>'
                .'<package xmlns="http://www.idpf.org/2007/opf" version="1.0">'
                .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
                .'<dc:title>Zip Book</dc:title><dc:creator>John Roe</dc:creator></metadata>'
                .'<manifest><item id="cover" href="cover.jpg" media-type="image/jpeg"/></manifest>'
                .'<spine/><meta name="cover" content="cover"/></package>',
        ]);

        try {
            $driver = new HtmlDriver($file);
            self::assertTrue($driver->isValid());

            $sections = $driver->getSections();
            self::assertCount(1, $sections);
            self::assertSame('index.html', $sections[0]->getId());
            self::assertSame('Doc', $sections[0]->getTitle());
            self::assertStringContainsString('<h1 id="start">Start</h1>', $sections[0]->getContent());

            $meta = $driver->getMeta();
            self::assertSame('Zip Book', $meta->getTitle());
            self::assertSame('John Roe', $meta->getAuthor());

            $cover = $driver->getCover();
            self::assertNotNull($cover);
            self::assertSame('image/jpeg', $cover->getMime());

            $paths = \array_map(static fn (EbookResourceInterface $resource): string => $resource->getPath(), $driver->getResources());
            self::assertContains('style.css', $paths);
            self::assertContains('images/pic.png', $paths);

            $toc = $driver->getTableOfContents();
            self::assertCount(1, $toc);
            self::assertSame('Start', $toc[0]->getTitle());
            self::assertSame('start', $toc[0]->getAnchor());
        } finally {
            \unlink($file);
        }
    }

    public function testZipPrefersIndexDocument(): void
    {
        $file = self::makeZip([
            'b.html' => '<html><body>B</body></html>',
            'index.html' => '<html><body><h1>Index</h1></body></html>',
        ]);

        try {
            $sections = new HtmlDriver($file)->getSections();
            self::assertSame('index.html', $sections[0]->getId());
            self::assertStringContainsString('<h1>Index</h1>', $sections[0]->getContent());
        } finally {
            \unlink($file);
        }
    }

    public function testHtmlzWithoutMetadataUsesDocumentTitleAndAssets(): void
    {
        $file = self::makeZip([
            'index.html' => '<html><head><title>Plain</title></head><body>x</body></html>',
            'style.css' => 'body{}',
        ]);

        try {
            $driver = new HtmlDriver($file);
            self::assertSame('Plain', $driver->getMeta()->getTitle());
            self::assertNull($driver->getCover());

            $paths = \array_map(static fn (EbookResourceInterface $resource): string => $resource->getPath(), $driver->getResources());
            self::assertContains('style.css', $paths);
        } finally {
            \unlink($file);
        }
    }

    public function testZipCoverFallbackByFilename(): void
    {
        $file = self::makeZip([
            'index.html' => '<html><body>x</body></html>',
            'cover.png' => (string) \file_get_contents(__DIR__.'/../fixtures/images/png.png'),
        ]);

        try {
            $cover = new HtmlDriver($file)->getCover();
            self::assertNotNull($cover);
            self::assertSame('image/png', $cover->getMime());
        } finally {
            \unlink($file);
        }
    }

    public function testZipCoverHrefMissingFallsBackToNull(): void
    {
        $file = self::makeZip([
            'index.html' => '<html><body>x</body></html>',
            'metadata.opf' => '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf">'
                .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>T</dc:title></metadata>'
                .'<manifest><item id="cov" href="missing.jpg" media-type="image/jpeg"/></manifest>'
                .'<meta name="cover" content="cov"/></package>',
        ]);

        try {
            self::assertNull(new HtmlDriver($file)->getCover());
        } finally {
            \unlink($file);
        }
    }

    public function testZipWithoutIndexUsesFirstHtml(): void
    {
        $file = self::makeZip([
            'b.html' => '<html><body>B</body></html>',
            'a.html' => '<html><body>A</body></html>',
        ]);

        try {
            self::assertSame('a.html', new HtmlDriver($file)->getSections()[0]->getId());
        } finally {
            \unlink($file);
        }
    }

    public function testZipWithoutHtmlIsInvalid(): void
    {
        $file = self::makeZip(['notes.txt' => 'text']);

        try {
            self::assertFalse(new HtmlDriver($file)->isValid());
        } finally {
            \unlink($file);
        }
    }

    /**
     * @param array<string, string> $entries
     */
    private static function makeZip(array $entries): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'htmlz');
        self::assertNotFalse($file);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $file;
    }
}
