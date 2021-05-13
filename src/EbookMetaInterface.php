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
    // cover - image (binary? or base64? or stream? or what?)

    public function getTitle(): string;
}
