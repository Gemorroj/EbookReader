<?php

declare(strict_types=1);

namespace EbookReader\Meta;

use EbookReader\EbookMetaInterface;

class MobiMeta implements EbookMetaInterface
{
    private string $title;
    private ?string $author;
    private ?string $publisher;
    private ?string $isbn;
    private ?string $description;
    private ?string $language;
    private ?string $license;
    private ?int $publishYear;
    private ?int $publishMonth;
    private ?int $publishDay;

    public function __construct(
        string $title,
        ?string $author,
        ?string $publisher,
        ?string $isbn,
        ?string $description,
        ?string $language,
        ?string $license,
        ?int $publishYear,
        ?int $publishMonth,
        ?int $publishDay
    ) {
        $this->title = $title;
        $this->author = $author;
        $this->publisher = $publisher;
        $this->isbn = $isbn;
        $this->description = $description;
        $this->language = $language;
        $this->license = $license;
        $this->publishYear = $publishYear;
        $this->publishMonth = $publishMonth;
        $this->publishDay = $publishDay;
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
