<?php

declare(strict_types=1);

namespace EbookReader\Source;

use EbookReader\Exception\SourceException;

/**
 * A book held in memory, materialised into a temporary file on first access.
 *
 * The temporary file is kept for the whole lifetime of the source and removed
 * by {@see close()} (called automatically when the source is destroyed).
 */
final class StringSource implements BookSource
{
    private ?string $path;

    public function __construct(private readonly string $contents)
    {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function getPath(): string
    {
        if (!isset($this->path)) {
            $this->path = $this->materialize();
        }

        return $this->path;
    }

    public function getHead(int $length): string
    {
        return $length <= 0 ? '' : \substr($this->contents, 0, $length);
    }

    public function close(): void
    {
        if (!isset($this->path)) {
            return;
        }

        @\unlink($this->path);
        $this->path = null;
    }

    private function materialize(): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'ebook');
        if (false === $path) {
            throw new SourceException('Unable to create a temporary file for the book contents');
        }

        if (\strlen($this->contents) !== @\file_put_contents($path, $this->contents)) {
            @\unlink($path);
            throw new SourceException('Unable to write the book contents to a temporary file');
        }

        return $path;
    }
}
