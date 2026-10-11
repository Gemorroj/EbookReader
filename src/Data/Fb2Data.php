<?php

declare(strict_types=1);

namespace EbookReader\Data;

use EbookReader\Resource\Resource;
use EbookReader\Resource\Style;

final readonly class Fb2Data implements EbookDataInterface
{
    /**
     * @param Fb2DataEpigraph[] $epigraphs
     * @param Style[]           $styles
     * @param resource[]        $resources
     */
    public function __construct(
        private string $text,
        private ?string $title,
        private ?string $annotation,
        private array $epigraphs = [],
        private array $styles = [],
        private array $resources = [],
    ) {
    }

    /**
     * @return resource[]
     */
    public function getResources(): array
    {
        return $this->resources;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * @return Style[]
     */
    public function getStyles(): array
    {
        return $this->styles;
    }

    /**
     * @return Fb2DataEpigraph[]
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
