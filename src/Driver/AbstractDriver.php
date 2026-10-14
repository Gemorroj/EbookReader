<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\MimeDetector\MimeDetector;
use EbookReader\MimeDetector\MimeDetectorInterface;
use EbookReader\Resource\EbookResourceInterface;

abstract class AbstractDriver implements EbookDriverInterface
{
    public function __construct(private readonly string $file, private readonly MimeDetectorInterface $mimeDetector = new MimeDetector())
    {
    }

    public function getFile(): string
    {
        return $this->file;
    }

    protected function getMimeDetector(): MimeDetectorInterface
    {
        return $this->mimeDetector;
    }

    /**
     * @return EbookResourceInterface[]
     */
    public function getResources(): array
    {
        $resources = [];
        $seen = [];
        foreach ($this->getSections() as $section) {
            foreach ($section->getResources() as $resource) {
                $path = $resource->getPath();
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;
                $resources[] = $resource;
            }
        }

        return $resources;
    }
}
