<?php

declare(strict_types=1);

namespace EbookReader;

interface EbookCoverInterface
{
    public function getData(): string;

    public function getMime(): string;
}
