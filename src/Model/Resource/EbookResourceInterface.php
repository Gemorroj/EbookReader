<?php

declare(strict_types=1);

namespace EbookReader\Model\Resource;

/**
 * A binary asset referenced by the content markup (image, font, stylesheet,
 * audio, video, ...).
 *
 * The interface allows alternative implementations (e.g. a lazily loaded
 * resource that reads from the container on demand) to be returned by
 * EbookDriverInterface::getResources() and EbookSectionInterface::getResources().
 */
interface EbookResourceInterface
{
    /**
     * Canonical key of the resource. It is exactly the reference that appears
     * in the section markup returned by EbookSectionInterface::getContent().
     */
    public function getPath(): string;

    public function getData(): string;

    public function getMime(): string;

    public function getType(): ResourceType;
}
