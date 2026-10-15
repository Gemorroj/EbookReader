<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Exception\ParserException;
use EbookReader\Model\Cover\Cover;
use EbookReader\Model\Format;
use EbookReader\Model\Meta\MarkdownMeta;
use EbookReader\Model\Meta\OpfMeta;
use EbookReader\Model\Resource\Resource as EbookResource;
use EbookReader\Model\Resource\ResourceType;
use EbookReader\Model\Section\MarkdownSection;
use EbookReader\Model\TableOfContents\TableOfContents;

/**
 * Markdown documents: a .md/.markdown file, or markdown entries inside a ZIP
 * with an optional metadata.opf (Calibre "txtz" semantics).
 *
 * The document is a single section whose content is the raw Markdown source
 * (media type text/markdown); rendering it to HTML is left to the consumer. The
 * table of contents is derived from the ATX (#..######) and Setext headings,
 * while fenced code blocks are skipped. Image links stay archive-relative, so
 * they already match the Resource::getPath() of the ZIP assets.
 *
 * Markdown has no magic bytes, so detection is heuristic: a document counts as
 * Markdown only when it carries unambiguous block structure (a heading, a
 * fenced code block, an inline link/image or a table); prose alone stays plain
 * text, and Setext underlines, list markers and emphasis are deliberately
 * ignored as they are too common in plain text.
 */
final class MarkdownDriver extends AbstractDriver
{
    /** @var list<string> */
    private const array MARKDOWN_EXTENSIONS = ['md', 'markdown', 'mdown', 'mkd', 'mkdn'];

    /** @var list<string> */
    private const array COVER_FILENAMES = [
        'cover',
        'img',
        'image',
        'cover_0',
        'img_0',
        'image_0',
    ];

    /**
     * Unambiguous block-level constructs that tell Markdown apart from prose.
     *
     * @var list<string>
     */
    private const array STRUCTURE_PATTERNS = [
        '/^ {0,3}#{1,6}[ \t]+\S/m',                  // ATX heading
        '/^ {0,3}(?:\x60{3,}|~{3,})/m',              // fenced code block
        '/!?\[[^\]]*\]\([^)\n]+\)/u',                // inline link or image
        '/^ {0,3}\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)+\|?[ \t]*$/m', // table delimiter row
    ];

    #[\Override]
    public function getFormat(): Format
    {
        return Format::MARKDOWN;
    }

    #[\Override]
    public function isValid(): bool
    {
        try {
            $sample = $this->getTextDecoder()->toUtf8($this->readSample(4096));
        } catch (\Throwable) {
            return false;
        }

        return self::hasMarkdownStructure($sample);
    }

    /**
     * @return list<MarkdownSection>
     */
    #[\Override]
    public function getSections(): array
    {
        $markdown = $this->markdown();

        return [
            new MarkdownSection(
                $this->documentId(),
                $markdown,
                self::firstHeading($markdown),
                $this->assets(),
            ),
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
     * Every heading of the document, at its own level (anchors are not available
     * because raw Markdown carries no element ids).
     *
     * @return list<TableOfContents>
     */
    #[\Override]
    public function getTableOfContents(): array
    {
        $toc = [];
        foreach (self::headings($this->markdown()) as $heading) {
            $toc[] = new TableOfContents($heading['title'], $heading['level'], 0, null);
        }

        return $toc;
    }

    #[\Override]
    public function getMeta(): MarkdownMeta
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
            $markdown = $this->markdown();
            $title = self::firstHeading($markdown) ?? self::firstLine($markdown);
        }

        return new MarkdownMeta(
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

    private function markdown(): string
    {
        return \mb_trim($this->getTextDecoder()->toUtf8($this->readAll()));
    }

    private static function hasMarkdownStructure(string $sample): bool
    {
        foreach (self::STRUCTURE_PATTERNS as $pattern) {
            if (1 === \preg_match($pattern, $sample)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ATX and Setext headings in document order, ignoring fenced code blocks.
     *
     * @return list<array{level: int, title: string}>
     */
    private static function headings(string $markdown): array
    {
        $headings = [];
        $fenceChar = null;
        $fenceLength = 0;
        $previous = null;

        foreach (\preg_split('/\R/', $markdown) ?: [] as $line) {
            if (null !== $fenceChar) {
                if (1 === \preg_match('/^ {0,3}'.\preg_quote($fenceChar, '/').'{'.$fenceLength.',}[ \t]*$/', $line)) {
                    $fenceChar = null;
                }
                $previous = null;
                continue;
            }

            if (1 === \preg_match('/^ {0,3}(\x60{3,}|~{3,})/', $line, $matches)) {
                $fenceChar = $matches[1][0];
                $fenceLength = \strlen($matches[1]);
                $previous = null;
                continue;
            }

            if (1 === \preg_match('~^ {0,3}(#{1,6})(?:[ \t]+(.*?))?[ \t]*$~', $line, $matches)) {
                $title = \mb_trim(\preg_replace('~[ \t]+#+[ \t]*$~', '', $matches[2] ?? '') ?? '');
                if ('' !== $title) {
                    $headings[] = ['level' => \strlen($matches[1]), 'title' => self::inlineText($title)];
                }
                $previous = null;
                continue;
            }

            // Setext heading: a text line directly followed by === or ---.
            if (null !== $previous && 1 === \preg_match('~^ {0,3}(=+|-+)[ \t]*$~', $line, $matches)) {
                $headings[] = ['level' => '=' === $matches[1][0] ? 1 : 2, 'title' => $previous];
                $previous = null;
                continue;
            }

            $trimmed = \mb_trim($line);
            $previous = '' === $trimmed ? null : self::inlineText($trimmed);
        }

        return $headings;
    }

    private static function firstHeading(string $markdown): ?string
    {
        foreach (self::headings($markdown) as $heading) {
            return $heading['title'];
        }

        return null;
    }

    private static function firstLine(string $markdown): string
    {
        foreach (\preg_split('/\R/', $markdown) ?: [] as $line) {
            $line = \mb_trim($line);
            if ('' !== $line) {
                return self::inlineText($line);
            }
        }

        return '';
    }

    /**
     * Reduces inline Markdown (links, code, emphasis) to its text.
     */
    private static function inlineText(string $text): string
    {
        $text = \preg_replace('~!?\[([^\]]*)\]\([^)]*\)~', '$1', $text) ?? $text;
        $text = \preg_replace('~\x60+([^\x60]*)\x60+~', '$1', $text) ?? $text;
        $text = \preg_replace('~(\*\*|__|\*|_)(.+?)\1~', '$2', $text) ?? $text;

        return \mb_trim($text);
    }

    /**
     * The archive's assets (images, fonts, stylesheets) as resources; their
     * paths match the archive-relative references used inside the Markdown.
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
            $resources = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (false === $name || \str_ends_with($name, '/')) {
                    continue;
                }
                if (\in_array(\strtolower(\pathinfo($name, \PATHINFO_EXTENSION)), self::MARKDOWN_EXTENSIONS, true)) {
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
     * @return list<string> markdown entry names, naturally sorted
     */
    private function markdownEntries(\ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (false === $name) {
                continue;
            }
            if (\in_array(\strtolower(\pathinfo($name, \PATHINFO_EXTENSION)), self::MARKDOWN_EXTENSIONS, true)) {
                $names[] = $name;
            }
        }
        \usort($names, static fn (string $a, string $b): int => \strnatcasecmp($a, $b));

        return $names;
    }

    private function documentId(): string
    {
        $zip = $this->openZip();
        if (null === $zip) {
            return '0';
        }

        try {
            return $this->markdownEntries($zip)[0] ?? '0';
        } finally {
            $zip->close();
        }
    }

    /**
     * The whole document: the file itself, or every markdown entry of a ZIP joined.
     */
    private function readAll(): string
    {
        $zip = $this->openZip();
        if (null !== $zip) {
            try {
                $entries = $this->markdownEntries($zip);
                if ([] === $entries) {
                    throw new ParserException();
                }

                $markdown = '';
                foreach ($entries as $entry) {
                    $data = $zip->getFromName($entry);
                    if (false === $data) {
                        throw new ParserException();
                    }
                    $markdown .= $data."\n\n";
                }

                return $markdown;
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
     * A leading slice of the primary document, cut at a line break so it never
     * ends inside a multi-byte character.
     */
    private function readSample(int $size): string
    {
        $size = \max(0, $size);
        $zip = $this->openZip();
        if (null !== $zip) {
            try {
                $entries = $this->markdownEntries($zip);
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
}
