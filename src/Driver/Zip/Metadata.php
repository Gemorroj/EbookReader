<?php

declare(strict_types=1);

namespace EbookReader\Driver\Zip;

use EbookReader\Driver\OpfMetadataReader;
use EbookReader\Model\Meta\Metadata as BookMetadata;

/**
 * The `metadata.opf` sidecar that ZIP containers such as Calibre's HTMLZ and
 * TXTZ carry: book metadata and the declared cover entry.
 */
final readonly class Metadata
{
    public function __construct(private Reader $zip)
    {
    }

    /**
     * The sidecar metadata, or empty metadata when the archive has none.
     */
    public function metadata(): BookMetadata
    {
        $content = $this->zip->tryRead('metadata.opf');

        return null === $content ? new BookMetadata() : OpfMetadataReader::fromXml($content);
    }

    /**
     * The declared cover entry, or null when the archive has none.
     */
    public function coverHref(): ?string
    {
        $content = $this->zip->tryRead('metadata.opf');

        return null === $content ? null : OpfMetadataReader::coverHref($content);
    }
}
