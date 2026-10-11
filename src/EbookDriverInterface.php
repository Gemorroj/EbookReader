<?php

declare(strict_types=1);

namespace EbookReader;

use EbookReader\Cover\Cover;

interface EbookDriverInterface
{
    public function getCover(): ?Cover;

    /**
     * @return EbookDataInterface[]
     */
    public function getData(): array;

    public function getMeta(): EbookMetaInterface;

    public function isValid(): bool;
}
