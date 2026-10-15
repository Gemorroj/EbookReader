<?php

declare(strict_types=1);

namespace EbookReader\Driver\Html;

/**
 * Extracts a document title from an HTML string.
 */
final class Title
{
    private function __construct()
    {
    }

    /**
     * The text of the first `<title>` element, or null when there is none.
     */
    public static function fromHtml(string $html): ?string
    {
        if (!\preg_match('~<title\b[^>]*>(.*?)</title>~is', $html, $matches)) {
            return null;
        }

        $title = \mb_trim(\html_entity_decode(\strip_tags($matches[1]), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));

        return '' === $title ? null : $title;
    }
}
