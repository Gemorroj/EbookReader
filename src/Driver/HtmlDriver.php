<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Cover\Cover;
use EbookReader\Exception\ParserException;
use EbookReader\Format;
use EbookReader\Meta\HtmlMeta;
use EbookReader\Section\HtmlSection;
use EbookReader\TableOfContents\TableOfContents;

/**
 * Plain HTML documents (a single .html/.htm file).
 *
 * There is no specification for how a book is laid out in HTML, so this driver
 * does no heuristic structuring: the whole document is returned as a single
 * section (media type text/html), and the charset is taken from <meta> when
 * present. No cover, table of contents or resources (a ZIP of HTML files is not
 * supported: their order would be a guess).
 */
final class HtmlDriver extends AbstractDriver
{
    public function getFormat(): Format
    {
        return Format::HTML;
    }

    public function isValid(): bool
    {
        $head = @\file_get_contents($this->getFile(), false, null, 0, 1024);
        if (false === $head) {
            return false;
        }

        // The file itself must be the HTML document: skip a BOM, an optional XML
        // prolog, leading comments and whitespace, then require a doctype or
        // <html> tag. This rejects containers (e.g. PalmDOC) that only embed HTML
        // further in.
        $head = \preg_replace('~^\xEF\xBB\xBF~', '', $head) ?? $head;
        $head = \preg_replace('~^(?:<\?xml.*?\?>|<!--.*?-->|\s)+~is', '', $head) ?? $head;

        return 1 === \preg_match('~^(?:<!doctype\s+html|<html[\s>])~i', $head);
    }

    /**
     * @return HtmlSection[]
     */
    public function getSections(): array
    {
        $html = $this->read();

        return [
            new HtmlSection('0', $html, self::extractTitle($html)),
        ];
    }

    public function getCover(): ?Cover
    {
        return null;
    }

    /**
     * Derived from the document headings (h1..h3): level = tag level, anchor =
     * element id. There is no attempt to locate a "real" table of contents.
     *
     * @return TableOfContents[]
     */
    public function getTableOfContents(): array
    {
        // Dom\HTMLDocument (PHP 8.4+) is a spec-compliant HTML5 parser; it also
        // handles the encoding itself, so no <meta> juggling is needed.
        $document = \Dom\HTMLDocument::createFromString($this->read(), \LIBXML_NOERROR, 'UTF-8');

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
            $toc[] = new TableOfContents($title, (int) \substr($node->tagName, 1), 0, '' === $anchor ? null : $anchor);
        }

        return $toc;
    }

    public function getMeta(): HtmlMeta
    {
        return new HtmlMeta(self::extractTitle($this->read()) ?? '');
    }

    private function read(): string
    {
        $bytes = @\file_get_contents($this->getFile());
        if (false === $bytes) {
            throw new ParserException();
        }

        return $this->getTextDecoder()->toUtf8($bytes, self::declaredCharset($bytes));
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
