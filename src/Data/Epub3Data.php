<?php

declare(strict_types=1);

namespace EbookReader\Data;

use EbookReader\EbookDataInterface;
use EbookReader\Resource\Style;

final readonly class Epub3Data implements EbookDataInterface
{
    /**
     * @param Style[] $styles
     */
    public function __construct(
        private string $text,
        private ?string $title,
        private array $styles = [],
        private ?bool $navigation = null,
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

    public function isNavigation(): ?bool
    {
        return $this->navigation;
    }
}
