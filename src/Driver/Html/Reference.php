<?php

declare(strict_types=1);

namespace EbookReader\Driver\Html;

/**
 * Reference helpers shared by the HTML-producing drivers.
 */
final class Reference
{
    private function __construct()
    {
    }

    /**
     * Whether a value points outside the book: an absolute URL with a scheme
     * (`http:`, `data:`, `javascript:`, ...) or a protocol-relative `//` URL.
     * Fragments (`#...`), relative paths and empty values are not external.
     */
    public static function isExternal(string $reference): bool
    {
        if ('' === $reference || '#' === $reference[0]) {
            return false;
        }

        return \str_starts_with($reference, '//') || 1 === \preg_match('~^[a-z][a-z0-9+.-]*:~i', $reference);
    }
}
