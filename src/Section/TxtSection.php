<?php

declare(strict_types=1);

namespace EbookReader\Section;

use EbookReader\Resource\Resource as EbookResource;

final readonly class TxtSection implements EbookSectionInterface
{
    /**
     * @param EbookResource[] $resources
     */
    public function __construct(
        private string $id,
        private string $content,
        private ?string $title,
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
        return 'text/plain';
    }

    /**
     * @return EbookResource[]
     */
    #[\Override]
    public function getResources(): array
    {
        return $this->resources;
    }
}
