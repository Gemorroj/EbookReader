<?php

declare(strict_types=1);

namespace EbookReader\MimeDetector;

/**
 * Detects image and font MIME types by magic bytes.
 */
final class MimeDetector implements MimeDetectorInterface
{
    #[\Override]
    public function detect(string $data): ?string
    {
        return $this->detectImage($data) ?? $this->detectFont($data);
    }

    private function detectImage(string $data): ?string
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

    private function detectFont(string $data): ?string
    {
        if (\str_starts_with($data, 'OTTO')) {
            return 'font/otf';
        }
        if (\str_starts_with($data, "\x00\x01\x00\x00") || \str_starts_with($data, 'true')) {
            return 'font/ttf';
        }
        if (\str_starts_with($data, 'ttcf')) {
            return 'font/collection';
        }
        if (\str_starts_with($data, 'wOFF')) {
            return 'font/woff';
        }
        if (\str_starts_with($data, 'wOF2')) {
            return 'font/woff2';
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
        if (0 === \stripos($data, '<svg')) {
            return true;
        }

        $head = \strtolower(\substr($data, 0, 255));

        return \str_starts_with($head, '<?xml') && \str_contains($head, '<svg');
    }
}
