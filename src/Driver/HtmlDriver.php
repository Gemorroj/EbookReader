<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Driver\Zip\Assets as ZipAssets;
use EbookReader\Driver\Zip\CoverFinder as ZipCoverFinder;
use EbookReader\Driver\Zip\Metadata as ZipMetadata;
use EbookReader\Driver\Zip\Reader as ZipReader;
use EbookReader\Exception\ParserException;
use EbookReader\Model\Cover\Cover;
use EbookReader\Model\Format;
use EbookReader\Model\Meta\Metadata;
use EbookReader\Model\Resource\Resource as EbookResource;
use EbookReader\Model\Section\Section;
use EbookReader\Model\TableOfContents\TableOfContentsEntry;

/**
 * Plain HTML documents: a single .html/.htm/.xhtml file, or an "htmlz" (a ZIP
 * with one top-level HTML document plus its assets, optionally with a
 * metadata.opf, as produced by Calibre).
 *
 * There is no specification for how a book is laid out in HTML, so this driver
 * does no heuristic structuring: the whole document is a single section (media
 * type text/html, charset from <meta>), the table of contents is derived from
 * the document headings (h1..h3), and the archive's images, fonts and
 * stylesheets are exposed as resources. A ZIP of several HTML files is read as
 * its "index" document (Calibre HTMLZ semantics).
 */
final class HtmlDriver extends AbstractDriver
{
    private const array HTML_EXTENSIONS = ['html', 'htm', 'xhtml'];
    private const array INDEX_NAMES = ['index.html', 'index.xhtml', 'index.htm'];
    private const array COVER_FILENAMES = ['cover', 'cover-image', 'cover-img', 'cover_0'];

    #[\Override]
    public function getFormat(): Format
    {
        return Format::HTML;
    }

    #[\Override]
    public function isValid(): bool
    {
        try {
            $head = $this->readHead(1024);
        } catch (\Throwable) {
            return false;
        }

        // The file itself must be the HTML document: skip a BOM, an optional XML
        // prolog, leading comments and whitespace, then require a doctype or
        // <html> tag. This rejects containers (e.g. PalmDOC) that only embed HTML.
        $head = \preg_replace('~^\xEF\xBB\xBF~', '', $head) ?? $head;
        $head = \preg_replace('~^(?:<\?xml.*?\?>|<!--.*?-->|\s)+~is', '', $head) ?? $head;

        return 1 === \preg_match('~^(?:<!doctype\s+html|<html[\s>])~i', $head);
    }

    /**
     * @return list<Section>
     */
    #[\Override]
    public function getSections(): array
    {
        $html = $this->readDocument();

        return [
            new Section($this->documentId(), $html, self::extractTitle($html), 'text/html', $this->assets()),
        ];
    }

    #[\Override]
    public function getCover(): ?Cover
    {
        $zip = ZipReader::open($this->getFile());
        if (null === $zip) {
            return null;
        }

        try {
            return new ZipCoverFinder($this->getMimeDetector())->find($zip, self::COVER_FILENAMES);
        } finally {
            $zip->close();
        }
    }

    /**
     * Derived from the document headings (h1..h3): level = tag level, anchor =
     * element id. There is no attempt to locate a "real" table of contents.
     *
     * @return list<TableOfContentsEntry>
     */
    #[\Override]
    public function getTableOfContents(): array
    {
        // Dom\HTMLDocument (PHP 8.4+) is a spec-compliant HTML5 parser; it also
        // handles the encoding itself, so no <meta> juggling is needed.
        $document = \Dom\HTMLDocument::createFromString($this->readDocument(), \LIBXML_NOERROR, 'UTF-8');

        $toc = [];
        foreach ($document->getElementsByTagName('*') as $node) {
            if (1 !== \preg_match('~^h[1-3]$~i', $node->tagName)) {
                continue;
            }

            $title = \mb_trim($node->textContent);
            if ('' === $title) {
                continue;
            }

            $anchor = $node->getAttribute('id');
            $toc[] = new TableOfContentsEntry($title, (int) \substr($node->tagName, 1), 0, '' === $anchor ? null : $anchor);
        }

        return $toc;
    }

    #[\Override]
    public function getMeta(): Metadata
    {
        $zip = ZipReader::open($this->getFile());
        if (null === $zip) {
            $meta = new Metadata();
        } else {
            try {
                $meta = new ZipMetadata($zip)->metadata();
            } finally {
                $zip->close();
            }
        }
        if ('' !== $meta->getTitle()) {
            return $meta;
        }

        return $meta->withTitle(self::extractTitle($this->readDocument()) ?? '');
    }

    private function readDocument(): string
    {
        $zip = ZipReader::open($this->getFile());
        if (null !== $zip) {
            try {
                $entry = $this->htmlEntry($zip);
                if (null === $entry) {
                    throw new ParserException();
                }
                $bytes = $zip->read($entry);
            } finally {
                $zip->close();
            }
        } else {
            $bytes = @\file_get_contents($this->getFile());
            if (false === $bytes) {
                throw new ParserException();
            }
        }

        return $this->getTextDecoder()->toUtf8($bytes, self::declaredCharset($bytes));
    }

    private function readHead(int $size): string
    {
        $zip = ZipReader::open($this->getFile());
        if (null !== $zip) {
            try {
                $entry = $this->htmlEntry($zip);
                if (null === $entry) {
                    throw new ParserException();
                }
                $head = $zip->head($entry, $size);
            } finally {
                $zip->close();
            }
        } else {
            $head = @\file_get_contents($this->getFile(), false, null, 0, \max(0, $size));
            if (false === $head) {
                throw new ParserException();
            }
        }

        return $head;
    }

    private function documentId(): string
    {
        $zip = ZipReader::open($this->getFile());
        if (null === $zip) {
            return '0';
        }

        try {
            return $this->htmlEntry($zip) ?? '0';
        } finally {
            $zip->close();
        }
    }

    /**
     * The archive's assets (images, fonts, stylesheets) as resources.
     *
     * @return list<EbookResource>
     */
    private function assets(): array
    {
        $zip = ZipReader::open($this->getFile());
        if (null === $zip) {
            return [];
        }

        try {
            $document = $this->htmlEntry($zip);

            return new ZipAssets($this->getMimeDetector())->collect($zip, excludeNames: null === $document ? [] : [$document]);
        } finally {
            $zip->close();
        }
    }

    /**
     * The entry holding the document: index.* when present (Calibre HTMLZ), else
     * the naturally-first HTML entry.
     */
    private function htmlEntry(ZipReader $zip): ?string
    {
        $candidates = [];
        foreach ($zip->allEntries() as $name) {
            if (!\in_array(\strtolower(\pathinfo($name, \PATHINFO_EXTENSION)), self::HTML_EXTENSIONS, true)) {
                continue;
            }

            if (\in_array(\strtolower(\basename($name)), self::INDEX_NAMES, true)) {
                return $name;
            }
            $candidates[] = $name;
        }

        if ([] === $candidates) {
            return null;
        }
        \usort($candidates, static fn (string $a, string $b): int => \strnatcasecmp($a, $b));

        return $candidates[0];
    }

    private static function declaredCharset(string $bytes): ?string
    {
        if (1 === \preg_match('~<meta[^>]*charset\s*=\s*["\']?\s*([a-z0-9_-]+)~i', \substr($bytes, 0, 1024), $matches)) {
            return $matches[1];
        }

        return null;
    }

    private static function extractTitle(string $html): ?string
    {
        if (1 !== \preg_match('~<title[^>]*>(.*?)</title>~is', $html, $matches)) {
            return null;
        }

        $title = \mb_trim(\html_entity_decode(\strip_tags($matches[1]), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));

        return '' === $title ? null : $title;
    }
}
