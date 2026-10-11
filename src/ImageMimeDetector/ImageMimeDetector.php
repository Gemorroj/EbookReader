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
        if ($this->isWebp($data)) {
            return 'image/webp';
        }
        if ($this->isBmp($data)) {
            return 'image/bmp';
        }
        if ($this->isTiff($data)) {
            return 'image/tiff';
        }
        if ($this->isIco($data)) {
            return 'image/x-icon';
        }
        if ($this->isSvg($data)) {
            return 'image/svg+xml';
        }

        return null;
    }

    private function isJpeg(string $data): bool
    {
        return \str_starts_with($data, \chr(0xFF).\chr(0xD8));
    }

    private function isPng(string $data): bool
    {
        return \str_starts_with($data, \chr(0x89).'PNG');
    }

    private function isGif(string $data): bool
    {
        return \str_starts_with($data, 'GIF87a') || \str_starts_with($data, 'GIF89a');
    }

    private function isWebp(string $data): bool
    {
        return \str_starts_with($data, 'RIFF') && 'WEBP' === \substr($data, 8, 4);
    }

    private function isBmp(string $data): bool
    {
        return \str_starts_with($data, 'BM');
    }

    private function isTiff(string $data): bool
    {
        return \str_starts_with($data, "II\x2A\x00") || \str_starts_with($data, "MM\x00\x2A");
    }

    private function isIco(string $data): bool
    {
        return \str_starts_with($data, "\x00\x00\x01\x00");
    }

    private function isSvg(string $data): bool
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
