<?php

declare(strict_types=1);

namespace EbookReader\Meta;

use EbookReader\EbookMetaInterface;

class Epub3Meta implements EbookMetaInterface
{
    public function __construct(
        private string $title,
        private ?string $author,
        private ?string $publisher,
        private ?string $isbn,
        private ?string $description,
        private ?string $language,
        private ?string $license,
        private ?int $publishYear,
        private ?int $publishMonth,
        private ?int $publishDay
    ) {
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
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

    public function getPublishYear(): ?int
    {
        return $this->publishYear;
    }

    public function getPublishMonth(): ?int
    {
        return $this->publishMonth;
    }

    public function getPublishDay(): ?int
    {
        return $this->publishDay;
    }
}
