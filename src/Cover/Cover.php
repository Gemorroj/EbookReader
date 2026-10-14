<?php

declare(strict_types=1);

namespace EbookReader\Cover;

final readonly class Cover implements EbookCoverInterface
{
    public function __construct(
        private string $data,
        private string $mime,
    ) {
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getMime(): string
    {
        return $this->mime;
    }
}
