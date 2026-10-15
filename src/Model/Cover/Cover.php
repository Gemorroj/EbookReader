<?php

declare(strict_types=1);

namespace EbookReader\Model\Cover;

final readonly class Cover implements EbookCoverInterface
{
    public function __construct(
        private string $data,
        private string $mime,
    ) {
    }

    #[\Override]
    public function getData(): string
    {
        return $this->data;
    }

    #[\Override]
    public function getMime(): string
    {
        return $this->mime;
    }
}
