<?php

declare(strict_types=1);

namespace EbookReader\Model\Meta;

interface EbookMetaInterface
{
    public function getTitle(): string;

    /**
     * All authors as a list.
     *
     * @return list<string>
     */
    public function getAuthors(): array;

    /**
     * Convenience: authors joined by ", ".
     */
    public function getAuthor(): ?string;

    /**
     * Subjects / genres as a list.
     *
     * @return list<string>
     */
    public function getSubjects(): array;

    public function getPublisher(): ?string;

    public function getIsbn(): ?string;

    /**
     * can be a HTML.
     */
    public function getDescription(): ?string;

    public function getPublishDate(): PublishDate;

    public function getLanguage(): ?string;

    public function getLicense(): ?string;

    /**
     * Book series name, or null.
     */
    public function getSeries(): ?string;

    /**
     * Position within the series (may be fractional, e.g. 1.5), or null.
     */
    public function getSeriesIndex(): ?float;
}
