<?php

declare(strict_types=1);

namespace EbookReader;

use EbookReader\Resource\Style;

interface EbookDataInterface
{
    /**
     * can be a HTML.
     */
    public function getText(): string;

    public function getTitle(): ?string;

    /**
     * @return Style[]
     */
    public function getStyles(): array;

    public function isNavigation(): ?bool;
}
