<?php

declare(strict_types=1);

namespace EbookReader\Text;

/**
 * Fallback used when bytes are neither declared nor valid UTF-8.
 */
enum TextDecoding: string
{
    case STRICT = 'strict';
    case DETECT = 'detect';
    case ENCODING = 'encoding';
    case CALLBACK = 'callback';
}
