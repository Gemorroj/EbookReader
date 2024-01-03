<?php

declare(strict_types=1);

namespace EbookReader\Data;

class Fb2DataEpigraph
{
    /**
     * @param string[] $author
     */
    public function __construct(
        private string $text,
        private array $author = [],
    ) {
    }

    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @return string[]
     */
    public function getAuthor(): array
    {
        return $this->author;
    }
}
