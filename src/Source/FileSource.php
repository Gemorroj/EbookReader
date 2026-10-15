<?php

declare(strict_types=1);

namespace EbookReader\Source;

/**
 * A book stored in an existing file on the local filesystem.
 */
final class FileSource implements BookSource
{
    public function __construct(private readonly string $path)
    {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHead(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $head = @\file_get_contents($this->path, false, null, 0, $length);

        return false === $head ? '' : $head;
    }

    public function close(): void
    {
    }
}
