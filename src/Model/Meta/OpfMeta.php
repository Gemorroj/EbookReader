<?php

declare(strict_types=1);

namespace EbookReader\Model\Meta;

/**
 * Metadata of the `metadata.opf` file that ZIP containers such as Calibre's
 * HTMLZ and TXTZ carry alongside their content. Unknown values are empty
 * (`''`, `[]` or null); the cover is exposed as an archive-relative path.
 *
 * This is a plain value object: reading it from an archive is the job of the
 * drivers' OPF reader.
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
}
