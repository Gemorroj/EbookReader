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
     * @return list<Section>
     */
    #[\Override]
    public function getSections(): array
    {
        $markdown = $this->markdown();

        return [
            new Section(
                $this->documentId(),
                $markdown,
                self::firstHeading($markdown),
                'text/markdown',
                $this->assets(),
            ),
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
     * Every heading of the document, at its own level (anchors are not available
     * because raw Markdown carries no element ids).
     *
     * @return list<TableOfContentsEntry>
     */
    #[\Override]
    public function getTableOfContents(): array
    {
        $toc = [];
        foreach (self::headings($this->markdown()) as $heading) {
            $toc[] = new TableOfContentsEntry($heading['title'], $heading['level'], 0, null);
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

        $markdown = $this->markdown();

        return $meta->withTitle(self::firstHeading($markdown) ?? self::firstLine($markdown));
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
        $zip = ZipReader::open($this->getFile());
        if (null === $zip) {
            return [];
        }

        try {
            return new ZipAssets($this->getMimeDetector())->collect($zip, excludeExtensions: self::MARKDOWN_EXTENSIONS);
        } finally {
            $zip->close();
        }
    }

    private function documentId(): string
    {
        $zip = ZipReader::open($this->getFile());
        if (null === $zip) {
            return '0';
        }

        try {
            return $zip->entries(self::MARKDOWN_EXTENSIONS)[0] ?? '0';
        } finally {
            $zip->close();
        }
    }

    /**
     * The whole document: the file itself, or every markdown entry of a ZIP joined.
     */
    private function readAll(): string
    {
        $zip = ZipReader::open($this->getFile());
        if (null !== $zip) {
            try {
                $entries = $zip->entries(self::MARKDOWN_EXTENSIONS);
                if ([] === $entries) {
                    throw new ParserException();
                }

                $markdown = '';
                foreach ($entries as $entry) {
                    $markdown .= $zip->read($entry)."\n\n";
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
        $zip = ZipReader::open($this->getFile());
        if (null !== $zip) {
            try {
                $entries = $zip->entries(self::MARKDOWN_EXTENSIONS);
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
}
