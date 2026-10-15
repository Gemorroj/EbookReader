<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Encoding\TextDecoder;
use EbookReader\Encoding\TextDecoderInterface;
use EbookReader\MimeDetector\MimeDetector;
use EbookReader\MimeDetector\MimeDetectorInterface;
use EbookReader\Model\Resource\EbookResourceInterface;
use EbookReader\Source\BookSource;
use EbookReader\Source\FileSource;

abstract class AbstractDriver implements EbookDriverInterface
{
    private readonly BookSource $source;

    public function __construct(
        BookSource|string $source,
        private readonly MimeDetectorInterface $mimeDetector = new MimeDetector(),
        private readonly TextDecoderInterface $textDecoder = new TextDecoder(),
    ) {
        $this->source = \is_string($source) ? new FileSource($source) : $source;
    }

    /**
     * Path to the book on the local filesystem (a temporary file for in-memory content).
     */
    public function getFile(): string
    {
        return $this->source->getPath();
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
