<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Cover\EbookCoverInterface;
use EbookReader\Format;
use EbookReader\Meta\EbookMetaInterface;
use EbookReader\Resource\EbookResourceInterface;
use EbookReader\Section\EbookSectionInterface;
use EbookReader\TableOfContents\TableOfContents;

interface EbookDriverInterface
{
    public function getFormat(): Format;

    public function getCover(): ?EbookCoverInterface;

    /**
     * Flat table of contents (level = nesting depth, starting at 1).
     *
     * @return TableOfContents[]
     */
    public function getTableOfContents(): array;

    /**
     * Content sections.
     *
     * @return EbookSectionInterface[]
     */
    public function getSections(): array;

    /**
     * All assets used by the book, de-duplicated by path.
     *
     * @return EbookResourceInterface[]
     */
    public function getResources(): array;

    public function getMeta(): EbookMetaInterface;

    public function isValid(): bool;
}
