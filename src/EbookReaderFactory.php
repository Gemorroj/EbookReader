<?php

declare(strict_types=1);

namespace EbookReader;

use EbookReader\Driver\Azw3Driver;
use EbookReader\Driver\EbookDriverInterface;
use EbookReader\Driver\Epub3Driver;
use EbookReader\Driver\Fb2Driver;
use EbookReader\Driver\HtmlDriver;
use EbookReader\Driver\MobiDriver;
use EbookReader\Driver\TxtDriver;
use EbookReader\Exception\FileNotReadableException;
use EbookReader\Exception\UnsupportedFormatException;
use EbookReader\MimeDetector\MimeDetector;
use EbookReader\MimeDetector\MimeDetectorInterface;
use EbookReader\Text\TextDecoder;
use EbookReader\Text\TextDecoderInterface;

final class EbookReaderFactory
{
    private function __construct()
    {
    }

    /**
     * @throws UnsupportedFormatException
     * @throws FileNotReadableException
     */
    public static function create(
        string $file,
        MimeDetectorInterface $mimeDetector = new MimeDetector(),
        TextDecoderInterface $textDecoder = new TextDecoder(),
    ): EbookDriverInterface {
        if (!\is_readable($file)) {
            throw new FileNotReadableException();
        }

        foreach (self::getCandidates($file, $mimeDetector, $textDecoder) as $driver) {
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
    private static function getCandidates(string $file, MimeDetectorInterface $mimeDetector, TextDecoderInterface $textDecoder): array
    {
        $head = @\file_get_contents($file, false, null, 0, 68);
        if (false === $head) {
            return [
                new Epub3Driver($file, $mimeDetector, $textDecoder),
                new Azw3Driver($file, $mimeDetector, $textDecoder),
                new MobiDriver($file, $mimeDetector, $textDecoder),
                new Fb2Driver($file, $mimeDetector, $textDecoder),
                new HtmlDriver($file, $mimeDetector, $textDecoder),
                new TxtDriver($file, $mimeDetector, $textDecoder),
            ];
        }

        // ZIP container: EPUB, FB2-ZIP, HTMLZ or TXT-ZIP.
        if (\str_starts_with($head, "PK\x03\x04")) {
            return [
                new Epub3Driver($file, $mimeDetector, $textDecoder),
                new Fb2Driver($file, $mimeDetector, $textDecoder),
                new HtmlDriver($file, $mimeDetector, $textDecoder),
                new TxtDriver($file, $mimeDetector, $textDecoder),
            ];
        }

        // Palm Database (MOBI/KF8): 4-byte type + 4-byte creator at offset 60.
        if (\strlen($head) >= 68 && 'BOOKMOBI' === \substr($head, 60, 8)) {
            return [
                new Azw3Driver($file, $mimeDetector, $textDecoder),
                new MobiDriver($file, $mimeDetector, $textDecoder),
                new Fb2Driver($file, $mimeDetector, $textDecoder),
                new TxtDriver($file, $mimeDetector, $textDecoder),
            ];
        }

        // Plain XML (FB2), HTML or plain text.
        return [
            new Epub3Driver($file, $mimeDetector, $textDecoder),
            new Azw3Driver($file, $mimeDetector, $textDecoder),
            new MobiDriver($file, $mimeDetector, $textDecoder),
            new Fb2Driver($file, $mimeDetector, $textDecoder),
            new HtmlDriver($file, $mimeDetector, $textDecoder),
            new TxtDriver($file, $mimeDetector, $textDecoder),
        ];
    }
}
