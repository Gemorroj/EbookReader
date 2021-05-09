<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\EbookDriverInterface;
use EbookReader\Meta\MobiMeta;

/**
 * @see https://wiki.mobileread.com/wiki/MOBI
 * @see https://github.com/choccybiccy/mobi
 */
class MobiDriver implements EbookDriverInterface
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    public function read(): MobiMeta
    {
        throw new \RuntimeException('Not implemented');
    }

    public static function isValid(string $file): bool
    {
        $f = \fopen($file, 'rb');
        if (!$f) {
            return false;
        }
        \fseek($f, 60);
        $content = \fread($f, 8);

        return 'BOOKMOBI' === $content;
    }
}
