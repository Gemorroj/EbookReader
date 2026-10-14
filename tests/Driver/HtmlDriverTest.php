<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\HtmlDriver;
use EbookReader\Format;
use EbookReader\Section\HtmlSection;
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
}
