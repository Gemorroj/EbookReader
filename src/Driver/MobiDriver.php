<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Meta\MobiMeta;

/**
 * @see https://wiki.mobileread.com/wiki/MOBI
 * @see https://github.com/choccybiccy/mobi
 */
class MobiDriver extends AbstractDriver
{
    public function isValid(): bool
    {
        $f = \fopen($this->getFile(), 'rb');
        if (!$f) {
            return false;
        }
        \fseek($f, 60);
        $content = \fread($f, 8);
        \fclose($f);

        return 'BOOKMOBI' === $content;
    }

    public function getMeta(): MobiMeta
    {
        throw new \RuntimeException('Not implemented');
    }
}
