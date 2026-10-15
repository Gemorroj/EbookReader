<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\MimeDetector\MimeDetector;
use EbookReader\MimeDetector\MimeDetectorInterface;
use EbookReader\Model\Resource\EbookResourceInterface;
use EbookReader\Text\TextDecoder;
use EbookReader\Text\TextDecoderInterface;

abstract class AbstractDriver implements EbookDriverInterface
{
    public function __construct(
        private readonly string $file,
        private readonly MimeDetectorInterface $mimeDetector = new MimeDetector(),
        private readonly TextDecoderInterface $textDecoder = new TextDecoder(),
    ) {
    }

    public function getFile(): string
    {
        return $this->file;
    }

    protected function getMimeDetector(): MimeDetectorInterface
    {
        return $this->mimeDetector;
    }

    protected function getTextDecoder(): TextDecoderInterface
    {
        return $this->textDecoder;
    }

    /**
     * @return list<EbookResourceInterface>
     */
    #[\Override]
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
