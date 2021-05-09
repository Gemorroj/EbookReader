<?php

declare(strict_types=1);

namespace EbookReader;

use EbookReader\Driver\Epub3Driver;
use EbookReader\Driver\Fb2Driver;
use EbookReader\Driver\MobiDriver;
use EbookReader\Exception\FileNotReadableException;
use EbookReader\Exception\UnsupportedFormatException;

class EbookReaderFactory
{
    /**
     * @throws UnsupportedFormatException
     */
    public static function create(string $file): EbookDriverInterface
    {
        if (!\is_readable($file)) {
            throw new FileNotReadableException();
        }

        if (Epub3Driver::isValid($file)) {
            return new Epub3Driver($file);
        }
        if (Fb2Driver::isValid($file)) {
            return new Fb2Driver($file);
        }
        if (MobiDriver::isValid($file)) {
            return new MobiDriver($file);
        }

        throw new UnsupportedFormatException();
    }
}
