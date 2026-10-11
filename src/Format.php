<?php

declare(strict_types=1);

namespace EbookReader;

enum Format: string
{
    case EPUB = 'epub';
    case MOBI = 'mobi';
    case FB2 = 'fb2';
    case TXT = 'txt';
}
