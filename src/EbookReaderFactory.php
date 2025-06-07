<?php

declare(strict_types=1);

namespace EbookReader;

use EbookReader\Driver\Epub3Driver;
use EbookReader\Driver\Fb2Driver;
use EbookReader\Driver\MobiDriver;
use EbookReader\Driver\TxtDriver;
use EbookReader\Exception\FileNotReadableException;
use EbookReader\Exception\UnsupportedFormatException;

readonly class EbookReaderFactory
{
    /**
     * @throws UnsupportedFormatException
     */
    public static function create(string $file): EbookDriverInterface
    {
        if (!\is_readable($file)) {
            throw new FileNotReadableException();
        }

        $epub3Driver = new Epub3Driver($file);
        if ($epub3Driver->isValid()) {
            return $epub3Driver;
        }
        $mobiDriver = new MobiDriver($file);
        if ($mobiDriver->isValid()) {
            return $mobiDriver;
        }
        $fb2Driver = new Fb2Driver($file);
        if ($fb2Driver->isValid()) {
            return $fb2Driver;
        }
        $txtDriver = new TxtDriver($file);
        if ($txtDriver->isValid()) {
            return $txtDriver;
        }

        throw new UnsupportedFormatException();
    }
}
