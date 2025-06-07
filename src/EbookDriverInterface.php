<?php

declare(strict_types=1);

namespace EbookReader;

interface EbookDriverInterface
{
    public function __construct(string $file);

    public function getCover(): ?EbookCoverInterface;

    /**
     * @return EbookDataInterface[]
     */
    public function getData(): array;

    public function getMeta(): EbookMetaInterface;

    public function isValid(): bool;
}
