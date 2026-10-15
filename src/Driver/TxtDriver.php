<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Exception\ParserException;
use EbookReader\Model\Cover\Cover;
use EbookReader\Model\Format;
use EbookReader\Model\Meta\OpfMeta;
use EbookReader\Model\Meta\TxtMeta;
use EbookReader\Model\Section\TxtSection;
use EbookReader\Model\TableOfContents\TableOfContents;

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
     * @return list<TxtSection>
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
            $content = $zip->getFromName('metadata.opf');
            $opf = false === $content ? new OpfMeta() : OpfReader::fromXml($content);

            $names = [];
            $coverHref = $opf->getCoverHref();
            if (null !== $coverHref) {
                $names[] = $coverHref;
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
     * @return list<TableOfContents>
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
            $title = $this->firstLine();
        }

        return new TxtMeta(
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
     * @return list<string> text entry names, naturally sorted
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
}
