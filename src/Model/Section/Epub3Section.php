<?php

declare(strict_types=1);

namespace EbookReader\Model\Section;

use EbookReader\Model\Resource\EbookResourceInterface;

/**
 * EPUB content document (XHTML). `navigation` is the tri-state from the spine
 * properties: true for the nav document, false for the rest, null when the
 * format does not mark one.
 */
final readonly class Epub3Section extends Section
{
    /**
     * @param list<EbookResourceInterface> $resources
     */
    public function __construct(
        string $id,
        string $content,
        ?string $title,
        private ?bool $navigation = null,
        array $resources = [],
    ) {
        parent::__construct($id, $content, $title, 'application/xhtml+xml', $resources);
    }

    public function isNavigation(): ?bool
    {
        return $this->navigation;
    }
}
