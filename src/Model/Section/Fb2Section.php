<?php

declare(strict_types=1);

namespace EbookReader\Model\Section;

use EbookReader\Model\Resource\EbookResourceInterface;

/**
 * FB2 section: HTML content plus the FB2-specific annotation and epigraphs.
 */
final readonly class Fb2Section extends Section
{
    /**
     * @param list<Fb2Epigraph>            $epigraphs
     * @param list<EbookResourceInterface> $resources
     */
    public function __construct(
        string $id,
        string $content,
        ?string $title,
        private ?string $annotation,
        private array $epigraphs = [],
        array $resources = [],
    ) {
        parent::__construct($id, $content, $title, 'text/html', $resources);
    }

    /**
     * @return list<Fb2Epigraph>
     */
    public function getEpigraphs(): array
    {
        return $this->epigraphs;
    }

    public function getAnnotation(): ?string
    {
        return $this->annotation;
    }
}
