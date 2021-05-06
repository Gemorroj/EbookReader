<?php

declare(strict_types=1);

namespace EbookReader\Meta;

use EbookReader\EbookMetaInterface;

class EpubMeta implements EbookMetaInterface
{
    private string $title;

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    public function getTitle(): string
    {
        return $this->title;
    }
}
