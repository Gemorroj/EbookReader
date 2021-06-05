<?php

declare(strict_types=1);

namespace EbookReader\Resource;

class Style
{
    public const TYPE_LINK = 'link';
    public const TYPE_CSS = 'css';

    private string $type;
    private string $data;

    public function __construct(string $data, string $type)
    {
        $this->data = $data;
        $this->type = $type;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function isLink(): bool
    {
        return self::TYPE_LINK === $this->getType();
    }

    public function isCss(): bool
    {
        return self::TYPE_CSS === $this->getType();
    }
}
