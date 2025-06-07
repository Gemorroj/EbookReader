<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\EbookDriverInterface;
use EbookReader\ImageMimeDetector\ImageMimeDetector;
use EbookReader\ImageMimeDetector\ImageMimeDetectorInterface;

abstract class AbstractDriver implements EbookDriverInterface
{
    public function __construct(private readonly string $file, private readonly ImageMimeDetectorInterface $imageMimeDetector = new ImageMimeDetector())
    {
    }

    public function getFile(): string
    {
        return $this->file;
    }

    protected function getImageMimeDetector(): ImageMimeDetectorInterface
    {
        return $this->imageMimeDetector;
    }
}
