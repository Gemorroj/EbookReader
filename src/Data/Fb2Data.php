<?php

declare(strict_types=1);

namespace EbookReader\Data;

use EbookReader\EbookDataInterface;
use EbookReader\Resource\Style;

final readonly class Fb2Data implements EbookDataInterface
{
    /**
     * @param Fb2DataEpigraph[] $epigraphs
     * @param Style[]           $styles
     */
    public function __construct(
        private string $text,
        private ?string $title,
        private ?string $annotation,
        private array $epigraphs = [],
        private array $styles = [],
        // todo: images
    ) {
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
