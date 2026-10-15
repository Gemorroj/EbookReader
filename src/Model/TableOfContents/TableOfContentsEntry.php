<?php

declare(strict_types=1);

namespace EbookReader\Model\TableOfContents;

final readonly class TableOfContentsEntry
{
    public function __construct(
        private string $title,
        private int $level = 1,
        private ?int $sectionIndex = null,
        private ?string $anchor = null,
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
     * Index into the array returned by getSections(), when the entry maps to a section.
     */
    public function getSectionIndex(): ?int
    {
        return $this->sectionIndex;
    }

    /**
     * Fragment identifier (element id) inside the target section, when known.
     */
    public function getAnchor(): ?string
    {
        return $this->anchor;
    }

    /**
     * Byte offset within the section content, when the format provides one
     * (MOBI/KF8 file position).
     */
    public function getPosition(): ?int
    {
        return $this->position;
    }
}
