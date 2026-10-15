<?php

declare(strict_types=1);

namespace EbookReader\Driver;

/**
 * Makes driver-produced markup self-contained: scripts are removed and asset
 * references pointing outside the book (remote URLs) are dropped, so rendering
 * a section never executes code or fetches external content.
 *
 * Local references, fragments and `data:` URIs are kept.
 */
final class HtmlSanitizer
{
    /**
     * @var list<string>
     */
    private const array ASSET_TAGS = ['img', 'image', 'link', 'source', 'video', 'audio', 'track', 'object', 'embed', 'iframe'];

    /**
     * @var list<string>
     */
    private const array REFERENCE_ATTRIBUTES = ['src', 'href', 'data', 'poster'];

    private function __construct()
    {
    }

    public static function sanitize(string $html): string
    {
        $html = \preg_replace('~<script\b[^>]*>.*?</script\s*>~is', '', $html) ?? $html;
        $html = \preg_replace('~<script\b[^>]*/\s*>~i', '', $html) ?? $html;

        $tags = \implode('|', self::ASSET_TAGS);

        return \preg_replace_callback(
            '~<(?:'.$tags.')\b[^>]*>~i',
            static function (array $matches): string {
                $attributes = \implode('|', self::REFERENCE_ATTRIBUTES);

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
        if ('' === $reference || '#' === $reference[0] || \str_starts_with($reference, 'data:')) {
            return false;
        }

        return \str_starts_with($reference, '//') || 1 === \preg_match('~^[a-z][a-z0-9+.-]*:~i', $reference);
    }
}
