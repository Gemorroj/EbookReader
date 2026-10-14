<?php

declare(strict_types=1);

namespace EbookReader\MimeDetector;

interface MimeDetectorInterface
{
    /**
     * Detects the MIME type of in-memory data by its magic bytes.
     */
    public function detect(string $data): ?string;
}
