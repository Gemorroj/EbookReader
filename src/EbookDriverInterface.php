<?php

declare(strict_types=1);

namespace EbookReader;

interface EbookDriverInterface
{
    public function __construct(string $file);

    /**
     * binary content.
     */
    public function getCover(): ?string;

    /**
     * can be a HTML.
     */
    public function getText(): string;

    public function getMeta(): EbookMetaInterface;

    public function isValid(): bool;
}
