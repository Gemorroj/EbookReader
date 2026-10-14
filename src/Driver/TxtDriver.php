<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Cover\Cover;
use EbookReader\Exception\ParserException;
use EbookReader\Format;
use EbookReader\Meta\PublishDate;
use EbookReader\Meta\TxtMeta;
use EbookReader\Section\TxtSection;
use EbookReader\TableOfContents\TableOfContents;

/**
 * Plain text (a .txt file, or a "txtz": text files inside a ZIP, optionally with
 * a metadata.opf, as produced by Calibre).
 *
 * There is no layout specification for plain text, so structure comes from a
 * conservative heuristic: a short, standalone line starting with a chapter-like
 * keyword begins a new section and becomes a table-of-contents entry. Anything
 * else is a single section.
 */
final class TxtDriver extends AbstractDriver
{
    private const array TEXT_EXTENSIONS = ['txt', 'text', 'md', 'markdown', 'textile'];

    /**
     * Chapter-like keywords at the start of a standalone line (English/Russian).
     */
    private const string CHAPTER_PATTERN = '~^(?:chapters?|part|book|section|prologue|epilogue|foreword|preface|afterword|introduction|conclusion|глава|часть|книга|раздел|пролог|эпилог|предисловие|послесловие|введение|заключение)\b~iu';

    /** @var string[] */
    protected array $coverFilenames = [
        'cover',
        'img',
        'image',
        'cover_0',
        'img_0',
        'image_0',
    ];

    #[\Override]
    public function getFormat(): Format
    {
        return Format::TXT;
    }

    #[\Override]
    public function isValid(): bool
    {
        try {
            $this->getTextDecoder()->toUtf8($this->readSample(4096));
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @return TxtSection[]
     */
    #[\Override]
    public function getSections(): array
    {
        $text = \mb_trim($this->getTextDecoder()->toUtf8($this->readAll()));

        $sections = [];
        foreach ($this->split($text) as $index => [$title, $body]) {
            $sections[] = new TxtSection((string) $index, $body, $title);
        }

        return $sections;
    }

    #[\Override]
    public function getCover(): ?Cover
    {
        $zip = $this->openZip();
        if (null === $zip) {
            return null;
        }

        try {
            $meta = self::readOpfMeta($zip);

            $names = [];
            if (isset($meta['cover'])) {
                $names[] = $meta['cover'];
            }
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (false !== $name && \in_array(\pathinfo($name, \PATHINFO_FILENAME), $this->coverFilenames, true)) {
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
     * @return TableOfContents[]
     */
    #[\Override]
    public function getTableOfContents(): array
    {
        $contents = [];
        foreach ($this->getSections() as $index => $section) {
            $title = $section->getTitle();
            if (null !== $title) {
                $contents[] = new TableOfContents($title, 1, $index, null);
            }
        }

        return $contents;
    }

    #[\Override]
    public function getMeta(): TxtMeta
    {
        $meta = [];
        $zip = $this->openZip();
        if (null !== $zip) {
            try {
                $meta = self::readOpfMeta($zip);
            } finally {
                $zip->close();
            }
        }

        return new TxtMeta(
            title: $meta['title'] ?? $this->firstLine(),
            authors: $meta['authors'] ?? [],
            publisher: $meta['publisher'] ?? null,
            isbn: $meta['isbn'] ?? null,
            description: $meta['description'] ?? null,
            language: $meta['language'] ?? null,
            subjects: $meta['subjects'] ?? [],
            license: $meta['license'] ?? null,
            publishDate: $meta['publishDate'] ?? new PublishDate(),
        );
    }

    /**
     * Splits the text into sections at chapter-like headings.
     *
     * @return list<array{0: ?string, 1: string}>
     */
    private function split(string $text): array
    {
        if ('' === $text) {
            return [[null, '']];
        }

        $blocks = [];
        $lines = [];
        $title = null;
        $blank = true;

        foreach (\explode("\n", $text) as $line) {
            $trimmed = \mb_trim($line);
            $heading = $blank && $this->isChapterHeading($trimmed);

            if ($heading && [] !== $lines) {
                $blocks[] = [$title, \mb_trim(\implode("\n", $lines))];
                $lines = [];
                $title = null;
            }
            if ($heading) {
                $title = $trimmed;
            }

            $lines[] = $line;
            $blank = '' === $trimmed;
        }
        $blocks[] = [$title, \mb_trim(\implode("\n", $lines))];

        return $blocks;
    }

    private function isChapterHeading(string $trimmed): bool
    {
        return '' !== $trimmed
            && \mb_strlen($trimmed) <= 80
            && 1 === \preg_match(self::CHAPTER_PATTERN, $trimmed);
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
     * @return string[] text entry names, naturally sorted
     */
    private function textEntries(\ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (false === $name) {
                continue;
            }
            if (\in_array(\strtolower(\pathinfo($name, \PATHINFO_EXTENSION)), self::TEXT_EXTENSIONS, true)) {
                $names[] = $name;
            }
        }
        \usort($names, static fn (string $a, string $b): int => \strnatcasecmp($a, $b));

        return $names;
    }

    /**
     * The whole text: the file itself, or every text entry of a ZIP joined.
     */
    private function readAll(): string
    {
        $zip = $this->openZip();
        if (null !== $zip) {
            try {
                $entries = $this->textEntries($zip);
                if ([] === $entries) {
                    throw new ParserException();
                }

                $text = '';
                foreach ($entries as $entry) {
                    $data = $zip->getFromName($entry);
                    if (false === $data) {
                        throw new ParserException();
                    }
                    $text .= $data."\n\n";
                }

                return $text;
            } finally {
                $zip->close();
            }
        }

        $data = @\file_get_contents($this->getFile());
        if (false === $data) {
            throw new ParserException();
        }

        return $data;
    }

    /**
     * A leading slice of the primary text, cut at a line break so it never ends
     * inside a multi-byte character.
     */
    private function readSample(int $size): string
    {
        $size = \max(0, $size);
        $zip = $this->openZip();
        $head = false;
        if (null !== $zip) {
            try {
                $entries = $this->textEntries($zip);
                if ([] === $entries) {
                    throw new ParserException();
                }
                $head = $this->readEntryHead($entries[0], $size);
            } finally {
                $zip->close();
            }
        } else {
            $head = @\file_get_contents($this->getFile(), false, null, 0, $size);
        }

        if (false === $head) {
            throw new ParserException();
        }

        $position = \strrpos($head, "\n");

        return false === $position ? $head : \substr($head, 0, $position);
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

    private function firstLine(): string
    {
        $head = $this->getTextDecoder()->toUtf8($this->readSample(8192));

        foreach (\explode("\n", $head) as $line) {
            $line = \mb_trim($line);
            if ('' !== $line) {
                return $line;
            }
        }

        return '';
    }

    /**
     * @return array{title?: string, authors?: string[], publisher?: string, isbn?: string, description?: string, language?: string, subjects?: string[], license?: string, cover?: string, publishDate?: PublishDate}
     */
    private static function readOpfMeta(\ZipArchive $zip): array
    {
        $content = $zip->getFromName('metadata.opf');
        if (false === $content) {
            return [];
        }

        try {
            $dom = \Dom\XMLDocument::createFromString($content, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
        } catch (\DOMException) {
            return [];
        }

        $meta = [];
        foreach (['title', 'publisher', 'language', 'description'] as $name) {
            $value = self::firstText($dom, $name);
            if (null !== $value) {
                $meta[$name] = $value;
            }
        }

        $authors = self::texts($dom, 'creator');
        if ([] !== $authors) {
            $meta['authors'] = $authors;
        }
        $subjects = self::texts($dom, 'subject');
        if ([] !== $subjects) {
            $meta['subjects'] = $subjects;
        }

        $license = self::firstText($dom, 'rights');
        if (null !== $license) {
            $meta['license'] = $license;
        }

        foreach ($dom->getElementsByTagNameNS('*', 'identifier') as $node) {
            $value = \mb_trim($node->textContent);
            if (\str_starts_with($value, 'urn:isbn:')) {
                $meta['isbn'] = \substr($value, 9);
                break;
            }
        }

        $cover = self::coverFromOpf($dom, $zip);
        if (null !== $cover) {
            $meta['cover'] = $cover;
        }

        $meta['publishDate'] = self::parseDate($dom);

        return $meta;
    }

    /**
     * Parses dc:date with per-format precision (year, year+month or a full date).
     */
    private static function parseDate(\Dom\XMLDocument $dom): PublishDate
    {
        $value = self::firstText($dom, 'date');
        if (null === $value) {
            return new PublishDate();
        }

        if (1 === \preg_match('/^(\d{4})$/', $value, $matches)) {
            return new PublishDate((int) $matches[1]);
        }
        if (1 === \preg_match('/^(\d{4})-(\d{1,2})$/', $value, $matches)) {
            return new PublishDate((int) $matches[1], (int) $matches[2]);
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return new PublishDate();
        }

        return new PublishDate((int) $date->format('Y'), (int) $date->format('m'), (int) $date->format('d'));
    }

    private static function firstText(\Dom\XMLDocument $dom, string $localName): ?string
    {
        $text = $dom->getElementsByTagNameNS('*', $localName)->item(0)?->textContent;
        $text = null === $text ? null : \mb_trim($text);

        return null === $text || '' === $text ? null : $text;
    }

    /**
     * @return string[]
     */
    private static function texts(\Dom\XMLDocument $dom, string $localName): array
    {
        $values = [];
        foreach ($dom->getElementsByTagNameNS('*', $localName) as $node) {
            $text = \mb_trim($node->textContent);
            if ('' !== $text) {
                $values[] = $text;
            }
        }

        return $values;
    }

    private static function coverFromOpf(\Dom\XMLDocument $dom, \ZipArchive $zip): ?string
    {
        $coverId = null;
        foreach ($dom->getElementsByTagNameNS('*', 'meta') as $node) {
            if ('cover' === self::attr($node, 'name')) {
                $coverId = self::attr($node, 'content');
                break;
            }
        }
        if (null !== $coverId && '' !== $coverId) {
            foreach ($dom->getElementsByTagNameNS('*', 'item') as $node) {
                $href = self::attr($node, 'href');
                if ($coverId === self::attr($node, 'id') && '' !== $href && false !== $zip->getFromName($href)) {
                    return $href;
                }
            }
        }

        $node = $dom->getElementsByTagNameNS('*', 'cover-relpath-from-base')->item(0);
        $href = null === $node ? '' : \mb_trim($node->textContent);
        if ('' !== $href && false !== $zip->getFromName($href)) {
            return $href;
        }

        return null;
    }

    private static function attr(\Dom\Element $element, string $name): string
    {
        return $element->getAttribute($name) ?? '';
    }
}
