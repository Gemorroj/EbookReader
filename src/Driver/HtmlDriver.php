<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Exception\ParserException;
use EbookReader\Model\Cover\Cover;
use EbookReader\Model\Format;
use EbookReader\Model\Meta\HtmlMeta;
use EbookReader\Model\Meta\OpfMeta;
use EbookReader\Model\Resource\Resource as EbookResource;
use EbookReader\Model\Resource\ResourceType;
use EbookReader\Model\Section\HtmlSection;
use EbookReader\Model\TableOfContents\TableOfContents;

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
     * @return list<HtmlSection>
     */
    #[\Override]
    public function getSections(): array
    {
        $html = $this->readDocument();

        return [
            new HtmlSection($this->documentId(), $html, self::extractTitle($html), $this->assets()),
        ];
    }

    #[\Override]
    public function getCover(): ?Cover
    {
        $zip = $this->openZip();
        if (null === $zip) {
            return null;
        }

        try {
            $content = $zip->getFromName('metadata.opf');
            $opf = false === $content ? new OpfMeta() : OpfReader::fromXml($content);

            $names = [];
            $coverHref = $opf->getCoverHref();
            if (null !== $coverHref) {
                $names[] = $coverHref;
            }
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (false !== $name && \in_array(\pathinfo($name, \PATHINFO_FILENAME), self::COVER_FILENAMES, true)) {
                    $names[] = $name;
                }
            }

            foreach ($names as $name) {
                $data = $zip->getFromName($name);
                if (false === $data) {
                    continue;
                }
                $mime = $this->getMimeDetector()->detect($data);
                if ($mime) {
                    return new Cover($data, $mime);
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    /**
     * Derived from the document headings (h1..h3): level = tag level, anchor =
     * element id. There is no attempt to locate a "real" table of contents.
     *
     * @return list<TableOfContents>
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
            $toc[] = new TableOfContents($title, (int) \substr($node->tagName, 1), 0, '' === $anchor ? null : $anchor);
        }

        return $toc;
    }

    #[\Override]
    public function getMeta(): HtmlMeta
    {
        $content = false;
        $zip = $this->openZip();
        if (null !== $zip) {
            try {
                $content = $zip->getFromName('metadata.opf');
            } finally {
                $zip->close();
            }
        }
        $opf = false === $content ? new OpfMeta() : OpfReader::fromXml($content);

        $title = $opf->getTitle();
        if ('' === $title) {
            $title = self::extractTitle($this->readDocument()) ?? '';
        }

        return new HtmlMeta(
            title: $title,
            authors: $opf->getAuthors(),
            publisher: $opf->getPublisher(),
            isbn: $opf->getIsbn(),
            description: $opf->getDescription(),
            language: $opf->getLanguage(),
            subjects: $opf->getSubjects(),
            license: $opf->getLicense(),
            publishDate: $opf->getPublishDate(),
        );
    }

    private function readDocument(): string
    {
        $zip = $this->openZip();
        if (null !== $zip) {
            try {
                $entry = $this->htmlEntry($zip);
                if (null === $entry) {
                    throw new ParserException();
                }
                $bytes = $zip->getFromName($entry);
                if (false === $bytes) {
                    throw new ParserException();
                }
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
        $zip = $this->openZip();
        if (null !== $zip) {
            try {
                $entry = $this->htmlEntry($zip);
                if (null === $entry) {
                    throw new ParserException();
                }
                $head = $this->readEntryHead($entry, $size);
            } finally {
                $zip->close();
            }
        } else {
            $head = @\file_get_contents($this->getFile(), false, null, 0, \max(0, $size));
        }

        if (false === $head) {
            throw new ParserException();
        }

        return $head;
    }

    /**
     * @return string|false
     */
    private function readEntryHead(string $entry, int $size)
    {
        $stream = @\fopen('zip://'.$this->getFile().'#'.$entry, 'r');
        if (false === $stream) {
            return false;
        }

        try {
            return \fread($stream, \max(1, $size));
        } finally {
            \fclose($stream);
        }
    }

    private function documentId(): string
    {
        $zip = $this->openZip();
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
        $zip = $this->openZip();
        if (null === $zip) {
            return [];
        }

        try {
            $document = $this->htmlEntry($zip);

            $resources = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (false === $name || $name === $document) {
                    continue;
                }
                $data = $zip->getFromIndex($i);
                if (false === $data) {
                    continue;
                }
                $mime = $this->getMimeDetector()->detect($data) ?? self::extensionMime(\pathinfo($name, \PATHINFO_EXTENSION));
                if (null === $mime || ResourceType::OTHER === ResourceType::fromMime($mime)) {
                    continue;
                }
                $resources[] = new EbookResource($name, $data, $mime);
            }

            return $resources;
        } finally {
            $zip->close();
        }
    }

    private function openZip(): ?\ZipArchive
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($this->getFile(), \ZipArchive::RDONLY)) {
            return null;
        }

        return $zip;
    }

    /**
     * The entry holding the document: index.* when present (Calibre HTMLZ), else
     * the naturally-first HTML entry.
     */
    private function htmlEntry(\ZipArchive $zip): ?string
    {
        $candidates = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (false === $name) {
                continue;
            }

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

    /**
     * MIME types the content detector cannot recognise (text/CSS), guessed from
     * the entry extension.
     */
    private static function extensionMime(string $extension): ?string
    {
        return match (\strtolower($extension)) {
            'css' => 'text/css',
            'svg' => 'image/svg+xml',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            default => null,
        };
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
