<?php

declare(strict_types=1);

namespace EbookReader\Model\Meta;

final readonly class MobiMeta implements EbookMetaInterface
{
    /**
     * @param list<string> $authors
     * @param list<string> $subjects
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
}
