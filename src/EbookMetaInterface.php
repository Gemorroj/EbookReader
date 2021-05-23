<?php

declare(strict_types=1);

namespace EbookReader;

interface EbookMetaInterface
{
    public function getTitle(): string;

    public function getAuthor(): ?string;

    public function getPublisher(): ?string;

    public function getIsbn(): ?string;

    /**
     * can be a HTML.
     */
    public function getDescription(): ?string;

    public function getPublishYear(): ?int;

    public function getPublishMonth(): ?int;

    public function getPublishDay(): ?int;

    public function getLanguage(): ?string;

    public function getLicense(): ?string;
}
