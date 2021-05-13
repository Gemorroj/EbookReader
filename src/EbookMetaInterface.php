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
    // cover - image (binary? or base64? or stream? or what?). add property to control that? loadCover: bool = false

    public function getTitle(): string;
}
