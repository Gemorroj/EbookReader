<?php

declare(strict_types=1);

namespace EbookReader\Driver\Html;

/**
 * The HTML elements and attributes the drivers treat as asset references.
 */
final class Tags
{
    /**
     * Elements whose references point at assets (images, fonts, stylesheets,
     * media).
     *
     * @var list<string>
     */
    public const array ASSET = ['img', 'image', 'link', 'source', 'video', 'audio', 'track', 'object', 'embed', 'iframe'];

    /**
     * Attributes whose values may reference an asset.
     *
     * @var list<string>
     */
    public const array REFERENCE = ['href', 'src', 'data', 'poster'];

    private function __construct()
    {
    }
}
