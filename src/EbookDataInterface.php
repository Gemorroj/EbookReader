<?php

declare(strict_types=1);

namespace EbookReader;

use EbookReader\Resource\Style;

interface EbookDataInterface
{
    /**
     * can be an HTML.
     */
    public function getText(): string;

    public function getTitle(): ?string;

    /**
     * @return Style[]
     */
    public function getStyles(): array;
}
