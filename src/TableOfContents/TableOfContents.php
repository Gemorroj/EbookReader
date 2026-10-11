<?php

declare(strict_types=1);

namespace EbookReader\TableOfContents;

final readonly class TableOfContents
{
    public function __construct(
        private string $title,
        private int $level = 1,
        private ?int $sectionIndex = null,
        private ?int $position = null,
    ) {
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Nesting depth, starting at 1.
     */
    public function getLevel(): int
    {
        return $this->level;
    }

    /**
     * Index into the array returned by getData(), when the entry maps to a section.
     */
    public function getSectionIndex(): ?int
    {
        return $this->sectionIndex;
    }

    /**
     * Byte offset within the section content, when the format provides one (MOBI filepos).
     */
    public function getPosition(): ?int
    {
        return $this->position;
    }
}
