<?php

declare(strict_types=1);

namespace EbookReader\Meta;

final readonly class MobiMeta implements EbookMetaInterface
{
    /**
     * @param string[] $authors
     * @param string[] $subjects
     */
    public function __construct(
        private string $title,
        private array $authors,
        private ?string $publisher,
        private ?string $isbn,
        private ?string $description,
        private ?string $language,
        private array $subjects,
        private ?string $license,
        private PublishDate $publishDate
    ) {
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return string[]
     */
    public function getAuthors(): array
    {
        return $this->authors;
    }

    public function getAuthor(): ?string
    {
        return $this->authors ? \implode(', ', $this->authors) : null;
    }

    /**
     * @return string[]
     */
    public function getSubjects(): array
    {
        return $this->subjects;
    }

    public function getPublisher(): ?string
    {
        return $this->publisher;
    }

    public function getIsbn(): ?string
    {
        return $this->isbn;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function getLicense(): ?string
    {
        return $this->license;
    }

    public function getPublishDate(): PublishDate
    {
        return $this->publishDate;
    }
}
