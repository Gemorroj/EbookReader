<?php

declare(strict_types=1);

namespace EbookReader\Resource;

final readonly class Style
{
    public function __construct(private string $data, private StyleType $type)
    {
    }

    public function getType(): StyleType
    {
        return $this->type;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function isLink(): bool
    {
        return StyleType::LINK === $this->getType();
    }

    public function isCss(): bool
    {
        return StyleType::CSS === $this->getType();
    }
}
