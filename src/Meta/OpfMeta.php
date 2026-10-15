<?php

declare(strict_types=1);

namespace EbookReader\Meta;

/**
 * Metadata of the `metadata.opf` file that ZIP containers such as Calibre's
 * HTMLZ and TXTZ carry alongside their content. Unknown values are empty
 * (`''`, `[]` or null); the cover is exposed as an archive-relative path.
 */
final readonly class OpfMeta implements EbookMetaInterface
{
    /**
     * @param string[] $authors
     * @param string[] $subjects
     */
    public function __construct(
        private string $title = '',
        private array $authors = [],
        private ?string $publisher = null,
        private ?string $isbn = null,
        private ?string $description = null,
        private ?string $language = null,
        private array $subjects = [],
        private ?string $license = null,
        private PublishDate $publishDate = new PublishDate(),
        private ?string $coverHref = null,
    ) {
    }

    public static function fromArchive(\ZipArchive $zip, string $name = 'metadata.opf'): self
    {
        $content = $zip->getFromName($name);
        if (false === $content) {
            return new self();
        }

        try {
            $dom = \Dom\XMLDocument::createFromString($content, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
        } catch (\DOMException) {
            return new self();
        }

        return new self(
            title: self::firstText($dom, 'title') ?? '',
            authors: self::texts($dom, 'creator'),
            publisher: self::firstText($dom, 'publisher'),
            isbn: self::isbn($dom),
            description: self::firstText($dom, 'description'),
            language: self::firstText($dom, 'language'),
            subjects: self::texts($dom, 'subject'),
            license: self::firstText($dom, 'rights'),
            publishDate: self::date($dom),
            coverHref: self::cover($dom, $zip),
        );
    }

    #[\Override]
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return string[]
     */
    #[\Override]
    public function getAuthors(): array
    {
        return $this->authors;
    }

    #[\Override]
    public function getAuthor(): ?string
    {
        return $this->authors ? \implode(', ', $this->authors) : null;
    }

    /**
     * @return string[]
     */
    #[\Override]
    public function getSubjects(): array
    {
        return $this->subjects;
    }

    #[\Override]
    public function getPublisher(): ?string
    {
        return $this->publisher;
    }

    #[\Override]
    public function getIsbn(): ?string
    {
        return $this->isbn;
    }

    #[\Override]
    public function getDescription(): ?string
    {
        return $this->description;
    }

    #[\Override]
    public function getLanguage(): ?string
    {
        return $this->language;
    }

    #[\Override]
    public function getLicense(): ?string
    {
        return $this->license;
    }

    #[\Override]
    public function getPublishDate(): PublishDate
    {
        return $this->publishDate;
    }

    /**
     * The archive-relative path of the cover image, or null when not declared.
     */
    public function getCoverHref(): ?string
    {
        return $this->coverHref;
    }

    private static function isbn(\Dom\XMLDocument $dom): ?string
    {
        foreach ($dom->getElementsByTagNameNS('*', 'identifier') as $node) {
            $value = \mb_trim($node->textContent);
            if (\str_starts_with($value, 'urn:isbn:')) {
                return \substr($value, 9);
            }
        }

        return null;
    }

    /**
     * Resolves the cover entry name: the `<meta name="cover">` referenced
     * manifest item, or a `<cover-relpath-from-base>` element.
     */
    private static function cover(\Dom\XMLDocument $dom, \ZipArchive $zip): ?string
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

    /**
     * Parses dc:date with per-format precision (year, year+month or a full date).
     */
    private static function date(\Dom\XMLDocument $dom): PublishDate
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

    private static function attr(\Dom\Element $element, string $name): string
    {
        return $element->getAttribute($name) ?? '';
    }
}
