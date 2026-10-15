<?php

declare(strict_types=1);

namespace EbookReader\Model\Section;

use EbookReader\Model\Resource\EbookResourceInterface;

interface EbookSectionInterface
{
    /**
     * Stable identifier of the section (e.g. the container path of the source
     * document, a skeleton name, or "0" for single-section formats).
     */
    public function getId(): string;

    /**
     * Title of the section itself (from <title>/<h1>), or null when the format
     * does not provide one. The book title is exposed by
     * EbookReader\Model\Meta\EbookMetaInterface::getTitle().
     */
    public function getTitle(): ?string;

    /**
     * Self-contained content: markup with styles included and asset references
     * normalized to the matching Resource::getPath() values.
     */
    public function getContent(): string;

    /**
     * MIME type of getContent(), e.g. "application/xhtml+xml", "text/html",
     * "text/plain".
     */
    public function getMediaType(): string;

    /**
     * Assets referenced by this section. Each Resource::getPath() matches the
     * reference used in getContent().
     *
     * @return list<EbookResourceInterface>
     */
    public function getResources(): array;
}
