<?php

declare(strict_types=1);

namespace EbookReader;

interface EbookDriverInterface
{
    public function __construct(string $file);

    public function read(): EbookMetaInterface;

    public static function isValid(string $file): bool;
}
