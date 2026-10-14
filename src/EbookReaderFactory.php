<?php

declare(strict_types=1);

namespace EbookReader;

use EbookReader\Driver\Azw3Driver;
use EbookReader\Driver\EbookDriverInterface;
use EbookReader\Driver\Epub3Driver;
use EbookReader\Driver\Fb2Driver;
use EbookReader\Driver\MobiDriver;
use EbookReader\Driver\TxtDriver;
use EbookReader\Exception\FileNotReadableException;
use EbookReader\Exception\UnsupportedFormatException;
use EbookReader\ImageMimeDetector\ImageMimeDetector;
use EbookReader\ImageMimeDetector\ImageMimeDetectorInterface;

final class EbookReaderFactory
{
    private function __construct()
    {
    }

    /**
     * @throws UnsupportedFormatException
     * @throws FileNotReadableException
     */
    public static function create(string $file, ImageMimeDetectorInterface $imageMimeDetector = new ImageMimeDetector()): EbookDriverInterface
    {
        if (!\is_readable($file)) {
            throw new FileNotReadableException();
        }

        foreach (self::getCandidates($file, $imageMimeDetector) as $driver) {
            if ($driver->isValid()) {
                return $driver;
            }
        }

        throw new UnsupportedFormatException();
    }

    /**
     * Cheap magic-bytes sniffing to order drivers and skip obviously incompatible ones.
     *
     * @return EbookDriverInterface[]
     */
    private static function getCandidates(string $file, ImageMimeDetectorInterface $imageMimeDetector): array
    {
        $head = @\file_get_contents($file, false, null, 0, 68);
        if (false === $head) {
            return [
                new Epub3Driver($file, $imageMimeDetector),
                new Azw3Driver($file, $imageMimeDetector),
                new MobiDriver($file, $imageMimeDetector),
                new Fb2Driver($file, $imageMimeDetector),
                new TxtDriver($file, $imageMimeDetector),
            ];
        }

        // ZIP container: EPUB, FB2-ZIP or TXT-ZIP.
        if (\str_starts_with($head, "PK\x03\x04")) {
            return [
                new Epub3Driver($file, $imageMimeDetector),
                new Fb2Driver($file, $imageMimeDetector),
                new TxtDriver($file, $imageMimeDetector),
            ];
        }

        // Palm Database (MOBI/KF8): 4-byte type + 4-byte creator at offset 60.
        if (\strlen($head) >= 68 && 'BOOKMOBI' === \substr($head, 60, 8)) {
            return [
                new Azw3Driver($file, $imageMimeDetector),
                new MobiDriver($file, $imageMimeDetector),
                new Fb2Driver($file, $imageMimeDetector),
                new TxtDriver($file, $imageMimeDetector),
            ];
        }

        // Plain XML (FB2) or plain text.
        return [
            new Fb2Driver($file, $imageMimeDetector),
            new TxtDriver($file, $imageMimeDetector),
            new MobiDriver($file, $imageMimeDetector),
            new Azw3Driver($file, $imageMimeDetector),
        ];
    }
}
