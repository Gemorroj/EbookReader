<?php

declare(strict_types=1);

namespace EbookReader\Model\Section;

final readonly class Fb2Epigraph
{
    /**
     * @param list<string> $authors
     */
    public function __construct(
        private string $text,
        private array $authors = [],
    ) {
    }

    public function getContent(): string
    {
        return $this->text;
    }

    /**
     * @return list<string>
     */
    public function getAuthors(): array
    {
        return $this->authors;
    }
}
