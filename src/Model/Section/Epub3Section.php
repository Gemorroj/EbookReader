<?php

declare(strict_types=1);

namespace EbookReader\Model\Section;

use EbookReader\Model\Resource\Resource as EbookResource;

final readonly class Epub3Section implements EbookSectionInterface
{
    /**
     * @param list<EbookResource> $resources
     */
    public function __construct(
        private string $id,
        private string $content,
        private ?string $title,
        private ?bool $navigation = null,
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
        return 'application/xhtml+xml';
    }

    /**
     * @return list<EbookResource>
     */
    #[\Override]
    public function getResources(): array
    {
        return $this->resources;
    }

    public function isNavigation(): ?bool
    {
        return $this->navigation;
    }
}
