<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\EbookDriverInterface;

abstract class AbstractDriver implements EbookDriverInterface
{
    public function __construct(private readonly string $file)
    {
    }

    public function getFile(): string
    {
        return $this->file;
    }
}
