<?php

declare(strict_types=1);

namespace EbookReader;

use EbookReader\Driver\Epub3Driver;
use EbookReader\Driver\Fb2Driver;
use EbookReader\Exception\UnsupportedFormatException;

class EbookReaderFactory
{
    public static function create(string $file): EbookDriverInterface
    {
        if (Epub3Driver::isValid($file)) {
            return new Epub3Driver($file);
        }
        if (Fb2Driver::isValid($file)) {
            return new Fb2Driver($file);
        }

        throw new UnsupportedFormatException();
    }
}
