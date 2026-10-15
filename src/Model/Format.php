<?php

declare(strict_types=1);

namespace EbookReader\Model;

enum Format: string
{
    case EPUB = 'epub';
    case MOBI = 'mobi';
    case AZW3 = 'azw3';
    case FB2 = 'fb2';
    case HTML = 'html';
    case MARKDOWN = 'markdown';
    case TXT = 'txt';
}
