<?php

declare(strict_types=1);

namespace EbookReader;

interface EbookMetaInterface
{
    // author
    // publisher
    // isbn
    // description
    // date
    // language
    // license

    public function getTitle(): string;
}
