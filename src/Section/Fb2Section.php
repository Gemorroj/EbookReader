<?php

declare(strict_types=1);

namespace EbookReader\Section;

use EbookReader\Resource\Resource as EbookResource;

final readonly class Fb2Section implements EbookSectionInterface
{
    /**
     * @param Fb2Epigraph[]   $epigraphs
     * @param EbookResource[] $resources
     */
    public function __construct(
        private string $id,
        private string $content,
        private ?string $title,
        private ?string $annotation,
        private array $epigraphs = [],
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
        return 'text/html';
    }

    /**
     * @return EbookResource[]
     */
    public function getResources(): array
    {
        return $this->resources;
    }

    /**
     * @return Fb2Epigraph[]
     */
    public function getEpigraphs(): array
    {
        return $this->epigraphs;
    }

    public function getAnnotation(): ?string
    {
        return $this->annotation;
    }
}
