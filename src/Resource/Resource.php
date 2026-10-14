<?php

declare(strict_types=1);

namespace EbookReader\Resource;

/**
 * Eager, in-memory implementation of {@see EbookResourceInterface}.
 */
final readonly class Resource implements EbookResourceInterface
{
    private ResourceType $type;

    public function __construct(
        private string $path,
        private string $data,
        private string $mime,
    ) {
        $this->type = ResourceType::fromMime($mime);
    }

    #[\Override]
    public function getPath(): string
    {
        return $this->path;
    }

    #[\Override]
    public function getData(): string
    {
        return $this->data;
    }

    #[\Override]
    public function getMime(): string
    {
        return $this->mime;
    }

    #[\Override]
    public function getType(): ResourceType
    {
        return $this->type;
    }
}
