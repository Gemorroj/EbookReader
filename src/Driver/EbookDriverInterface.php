<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Model\Cover\EbookCoverInterface;
use EbookReader\Model\Format;
use EbookReader\Model\Meta\EbookMetaInterface;
use EbookReader\Model\Resource\EbookResourceInterface;
use EbookReader\Model\Section\EbookSectionInterface;
use EbookReader\Model\TableOfContents\TableOfContents;

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
