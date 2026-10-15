<?php

declare(strict_types=1);

namespace EbookReader\Model\Section;

use EbookReader\Model\Resource\EbookResourceInterface;

/**
 * Generic content section: the shared implementation for formats whose content
 * model is only id, title, content, media type and resources. Formats carrying
 * extra data (EPUB navigation, FB2 annotation/epigraphs) extend it.
 */
readonly class Section implements EbookSectionInterface
{
    /**
     * @param list<EbookResourceInterface> $resources
     */
    public function __construct(
        private string $id,
        private string $content,
        private ?string $title,
        private string $mediaType,
        private array $resources = [],
    ) {
    }

    #[\Override]
    public function getId(): string
    {
        return $this->id;
    }

    #[\Override]
    public function getContent(): string
    {
        return $this->content;
    }

    #[\Override]
    public function getTitle(): ?string
    {
        return $this->title;
    }

    #[\Override]
    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    /**
     * @return list<EbookResourceInterface>
     */
    #[\Override]
    public function getResources(): array
    {
        return $this->resources;
    }
}
