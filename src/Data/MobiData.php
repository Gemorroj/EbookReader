<?php

declare(strict_types=1);

namespace EbookReader\Data;

use EbookReader\EbookDataInterface;
use EbookReader\Resource\Resource;
use EbookReader\Resource\Style;

final readonly class MobiData implements EbookDataInterface
{
    /**
     * @param Style[]    $styles
     * @param resource[] $resources
     */
    public function __construct(
        private string $text,
        private ?string $title,
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
}
