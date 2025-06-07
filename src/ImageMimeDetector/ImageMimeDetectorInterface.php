<?php

declare(strict_types=1);

namespace EbookReader\ImageMimeDetector;

interface ImageMimeDetectorInterface
{
    public function detect(string $data): ?string;
}
