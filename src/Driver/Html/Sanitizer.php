<?php

declare(strict_types=1);

namespace EbookReader\Driver\Html;

/**
 * Makes driver-produced markup self-contained: scripts are removed and asset
 * references pointing outside the book (remote URLs) are dropped, so rendering
 * a section never executes code or fetches external content.
 *
 * Local references, fragments and `data:` URIs are kept.
 */
final class Sanitizer
{
    private function __construct()
    {
    }

    public static function sanitize(string $html): string
    {
        $html = \preg_replace('~<script\b[^>]*>.*?</script\s*>~is', '', $html) ?? $html;
        $html = \preg_replace('~<script\b[^>]*/\s*>~i', '', $html) ?? $html;

        $tags = \implode('|', Tags::ASSET);

        return \preg_replace_callback(
            '~<(?:'.$tags.')\b[^>]*>~i',
            static function (array $matches): string {
                $attributes = \implode('|', Tags::REFERENCE);

                return \preg_replace_callback(
                    '~\s*\b(?:'.$attributes.')\s*=\s*("?)([^"\s>]+)\1~i',
                    static fn (array $attribute): string => self::isRemote($attribute[2]) ? '' : $attribute[0],
                    $matches[0]
                ) ?? $matches[0];
            },
            $html
        ) ?? $html;
    }

    private static function isRemote(string $reference): bool
    {
        return !\str_starts_with($reference, 'data:') && Reference::isExternal($reference);
    }
}
