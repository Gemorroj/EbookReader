<?php

declare(strict_types=1);

namespace EbookReader\ImageMimeDetector;

final class ImageMimeDetector implements ImageMimeDetectorInterface
{
    public function detect(string $data): ?string
    {
        if ($this->isJpeg($data)) {
            return 'image/jpeg';
        }
        if ($this->isPng($data)) {
            return 'image/png';
        }
        if ($this->isGif($data)) {
            return 'image/gif';
        }
        if ($this->isSvg($data)) {
            return 'image/svg+xml';
        }

        return null;
    }

    protected function isJpeg(string $data): bool
    {
        return \str_starts_with($data, \chr(0xFF).\chr(0xD8));
    }

    protected function isPng(string $data): bool
    {
        return \str_starts_with($data, \chr(0x89).'PNG');
    }

    protected function isGif(string $data): bool
    {
        return \str_starts_with($data, 'GI');
    }

    protected function isSvg(string $data): bool
    {
        $data = \trim($data);
        $bytes = \strtolower(\substr($data, 0, 4));
        if ('<svg' === $bytes) {
            return true;
        }

        $bytes = \strtolower(\substr($data, 0, 255));
        if (\str_starts_with($bytes, '<?xml') && \str_contains($bytes, '<svg')) {
            return true;
        }

        return false;
    }
}
