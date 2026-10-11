<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Cover\Cover;
use EbookReader\Data\EbookDataInterface;
use EbookReader\Format;
use EbookReader\Meta\EbookMetaInterface;
use EbookReader\TableOfContents\TableOfContents;

interface EbookDriverInterface
{
    public function getFormat(): Format;

    public function getCover(): ?Cover;

    /**
     * Flat table of contents (level = nesting depth, starting at 1).
     *
     * @return TableOfContents[]
     */
    public function getTableOfContents(): array;

    /**
     * @return EbookDataInterface[]
     */
    public function getData(): array;

    public function getMeta(): EbookMetaInterface;

    public function isValid(): bool;
}
