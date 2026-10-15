<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Driver\Zip\CoverFinder as ZipCoverFinder;
use EbookReader\Driver\Zip\Metadata as ZipMetadata;
use EbookReader\Driver\Zip\Reader as ZipReader;
use EbookReader\Exception\ParserException;
use EbookReader\Model\Cover\Cover;
use EbookReader\Model\Format;
use EbookReader\Model\Meta\Metadata;
use EbookReader\Model\Section\Section;
use EbookReader\Model\TableOfContents\TableOfContentsEntry;

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
    /**
     * @var list<string>
     */
    private const array TEXT_EXTENSIONS = ['txt', 'text', 'md', 'markdown', 'textile'];

    /**
     * Chapter-like keywords at the start of a standalone line (English/Russian).
     */
    private const string CHAPTER_PATTERN = '~^(?:chapters?|part|book|section|prologue|epilogue|foreword|preface|afterword|introduction|conclusion|глава|часть|книга|раздел|пролог|эпилог|предисловие|послесловие|введение|заключение)\b~iu';

    /** @var list<string> */
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
     * @return list<Section>
     */
    #[\Override]
    public function getSections(): array
    {
        $text = \mb_trim($this->getTextDecoder()->toUtf8($this->readAll()));

        $sections = [];
        foreach ($this->split($text) as $index => [$title, $body]) {
            $sections[] = new Section((string) $index, $body, $title, 'text/plain');
        }

        return $sections;
    }

    #[\Override]
    public function getCover(): ?Cover
    {
        $zip = ZipReader::open($this->getFile());
        if (null === $zip) {
            return null;
        }

        try {
            return new ZipCoverFinder($this->getMimeDetector())->find($zip, $this->coverFilenames);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<TableOfContentsEntry>
     */
    #[\Override]
    public function getTableOfContents(): array
    {
        $contents = [];
        foreach ($this->getSections() as $index => $section) {
            $title = $section->getTitle();
            if (null !== $title) {
                $contents[] = new TableOfContentsEntry($title, 1, $index, null);
            }
        }

        return $contents;
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

        return '' === $meta->getTitle() ? $meta->withTitle($this->firstLine()) : $meta;
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

    /**
     * The whole text: the file itself, or every text entry of a ZIP joined.
     */
    private function readAll(): string
    {
        $zip = ZipReader::open($this->getFile());
        if (null !== $zip) {
            try {
                $entries = $zip->entries(self::TEXT_EXTENSIONS);
                if ([] === $entries) {
                    throw new ParserException();
                }

                $text = '';
                foreach ($entries as $entry) {
                    $text .= $zip->read($entry)."\n\n";
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
        $zip = ZipReader::open($this->getFile());
        if (null !== $zip) {
            try {
                $entries = $zip->entries(self::TEXT_EXTENSIONS);
                if ([] === $entries) {
                    throw new ParserException();
                }
                $head = $zip->head($entries[0], $size);
            } finally {
                $zip->close();
            }
        } else {
            $head = @\file_get_contents($this->getFile(), false, null, 0, $size);
            if (false === $head) {
                throw new ParserException();
            }
        }

        $position = \strrpos($head, "\n");

        return false === $position ? $head : \substr($head, 0, $position);
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
}
