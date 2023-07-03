<?php

declare(strict_types=1);

namespace EbookReader\Data;

use EbookReader\EbookDataInterface;
use EbookReader\Resource\Style;

class Epub3Data implements EbookDataInterface
{
    private ?bool $navigation;
    private string $text;
    private ?string $title;
    /**
     * @var Style[]
     */
    private array $styles;

    /**
     * @param Style[] $styles
     */
    public function __construct(string $text, ?string $title, array $styles = [], bool $navigation = null)
    {
        $this->text = $text;
        $this->title = $title;
        $this->styles = $styles;
        $this->navigation = $navigation;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getStyles(): array
    {
        return $this->styles;
    }

    public function isNavigation(): ?bool
    {
        return $this->navigation;
    }
}
