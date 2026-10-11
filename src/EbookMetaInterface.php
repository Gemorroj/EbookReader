<?php

declare(strict_types=1);

namespace EbookReader;

use EbookReader\Meta\PublishDate;

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

    public function getPublishDate(): PublishDate;

    public function getLanguage(): ?string;

    public function getLicense(): ?string;
}
