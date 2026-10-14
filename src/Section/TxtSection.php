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

    public function getId(): string
    {
        return $this->id;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getMediaType(): string
    {
        return 'text/plain';
    }

    /**
     * @return EbookResource[]
     */
    public function getResources(): array
    {
        return $this->resources;
    }
}
