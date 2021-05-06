<?php

declare(strict_types=1);

namespace EbookReader;

use EbookReader\Driver\Epub3Driver;
use EbookReader\Exception\UnsupportedFormatException;

class EbookReaderFactory
{
    public static function create(string $file): EbookDriverInterface
    {
        if (Epub3Driver::isValid($file)) {
            return new Epub3Driver($file);
        }

        throw new UnsupportedFormatException();
    }
}
