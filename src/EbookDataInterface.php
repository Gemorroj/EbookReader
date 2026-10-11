<?php

declare(strict_types=1);

namespace EbookReader;

use EbookReader\Resource\Resource;
use EbookReader\Resource\Style;

interface EbookDataInterface
{
    /**
     * Inline resources (images) referenced by the content markup, keyed by
     * the same reference string used in getText() (href/src/recindex value).
     *
     * @return resource[]
     */
    public function getResources(): array;

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
