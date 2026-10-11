<?php

declare(strict_types=1);

namespace EbookReader\Resource;

final readonly class Resource
{
    public function __construct(
        private string $path,
        private string $data,
        private string $mime,
    ) {
    }

    /**
     * Reference exactly as it appears in the content markup.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getMime(): string
    {
        return $this->mime;
    }
}
