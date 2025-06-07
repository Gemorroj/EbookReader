<?php

declare(strict_types=1);

namespace EbookReader\Meta;

use EbookReader\EbookMetaInterface;

final readonly class TxtMeta implements EbookMetaInterface
{
    public function __construct(
        private string $title,
        private ?string $author = null,
        private ?string $publisher = null,
        private ?string $isbn = null,
        private ?string $description = null,
        private ?string $language = null,
        private ?string $license = null,
        private ?int $publishYear = null,
        private ?int $publishMonth = null,
        private ?int $publishDay = null,
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
