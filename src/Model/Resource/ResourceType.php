<?php

declare(strict_types=1);

namespace EbookReader\Model\Resource;

enum ResourceType: string
{
    case IMAGE = 'image';
    case FONT = 'font';
    case STYLE = 'style';
    case AUDIO = 'audio';
    case VIDEO = 'video';
    case OTHER = 'other';

    public static function fromMime(string $mime): self
    {
        if (\str_starts_with($mime, 'image/')) {
            return self::IMAGE;
        }
        if (\str_starts_with($mime, 'font/') || \str_contains($mime, 'font') || \str_contains($mime, 'opentype')) {
            return self::FONT;
        }
        if ('text/css' === $mime) {
            return self::STYLE;
        }
        if (\str_starts_with($mime, 'audio/')) {
            return self::AUDIO;
        }
        if (\str_starts_with($mime, 'video/')) {
            return self::VIDEO;
        }

        return self::OTHER;
    }
}
