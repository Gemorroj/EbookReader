<?php

declare(strict_types=1);

namespace EbookReader\Decompressor;

interface DecompressorInterface
{
    public function decompress(string $data): string;
}
