<?php

declare(strict_types=1);

namespace EbookReader\Decompressor;

/**
 * MOBI "compression" type 1: the text records are stored as-is.
 */
final class NoCompression implements DecompressorInterface
{
    public function decompress(string $data): string
    {
        return $data;
    }
}
