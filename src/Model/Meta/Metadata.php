<?php

declare(strict_types=1);

namespace EbookReader\Model\Meta;

/**
 * Book metadata: the single {@see EbookMetaInterface} implementation shared by
 * every format. Unknown values are empty (`''`, `[]` or null).
 */
final readonly class Metadata implements EbookMetaInterface
{
    /**
     * @param list<string> $authors
     * @param list<string> $contributors
     * @param list<string> $subjects
     */
    public function __construct(
        private string $title = '',
        private array $authors = [],
        private array $contributors = [],
        private ?string $publisher = null,
        private ?string $isbn = null,
        private ?string $description = null,
        private ?string $language = null,
        private array $subjects = [],
        private ?string $license = null,
        private PublishDate $publishDate = new PublishDate(),
        private ?string $series = null,
        private ?float $seriesIndex = null,
    ) {
    }

    #[\Override]
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return list<string>
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
     * @return list<string>
     */
    #[\Override]
    public function getContributors(): array
    {
        return $this->contributors;
    }

    /**
     * @return list<string>
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

    #[\Override]
    public function getSeries(): ?string
    {
        return $this->series;
    }

    #[\Override]
    public function getSeriesIndex(): ?float
    {
        return $this->seriesIndex;
    }

    /**
     * A copy with the title replaced; used when a format derives the title from
     * its content because the metadata does not declare one.
     */
    public function withTitle(string $title): self
    {
        return new self(
            $title,
            $this->authors,
            $this->contributors,
            $this->publisher,
            $this->isbn,
            $this->description,
            $this->language,
            $this->subjects,
            $this->license,
            $this->publishDate,
            $this->series,
            $this->seriesIndex,
        );
    }
}
