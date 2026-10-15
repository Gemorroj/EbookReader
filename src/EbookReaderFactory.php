<?php

declare(strict_types=1);

namespace EbookReader;

use EbookReader\Driver\Azw3Driver;
use EbookReader\Driver\EbookDriverInterface;
use EbookReader\Driver\Epub3Driver;
use EbookReader\Driver\Fb2Driver;
use EbookReader\Driver\HtmlDriver;
use EbookReader\Driver\MarkdownDriver;
use EbookReader\Driver\MobiDriver;
use EbookReader\Driver\TxtDriver;
use EbookReader\Exception\FileNotReadableException;
use EbookReader\Exception\UnsupportedFormatException;
use EbookReader\MimeDetector\MimeDetector;
use EbookReader\MimeDetector\MimeDetectorInterface;
use EbookReader\Source\BookSource;
use EbookReader\Source\FileSource;
use EbookReader\Source\StringSource;
use EbookReader\Text\TextDecoder;
use EbookReader\Text\TextDecoderInterface;

final class EbookReaderFactory
{
    private function __construct()
    {
    }

    /**
     * Creates a reader for a book stored in a local file.
     *
     * @throws UnsupportedFormatException
     * @throws FileNotReadableException
     */
    public static function create(
        string $file,
        MimeDetectorInterface $mimeDetector = new MimeDetector(),
        TextDecoderInterface $textDecoder = new TextDecoder(),
    ): EbookDriverInterface {
        if (!\is_readable($file)) {
            throw new FileNotReadableException(\sprintf('File "%s" is not readable', $file));
        }

        return self::detect(new FileSource($file), $file, $mimeDetector, $textDecoder);
    }

    /**
     * Creates a reader for a book held in memory.
     *
     * @throws UnsupportedFormatException
     */
    public static function createFromContent(
        string $contents,
        MimeDetectorInterface $mimeDetector = new MimeDetector(),
        TextDecoderInterface $textDecoder = new TextDecoder(),
    ): EbookDriverInterface {
        return self::detect(new StringSource($contents), 'in-memory content', $mimeDetector, $textDecoder);
    }

    /**
     * Creates a reader for a book from an arbitrary {@see BookSource}.
     *
     * @throws UnsupportedFormatException
     */
    public static function createFromSource(
        BookSource $source,
        MimeDetectorInterface $mimeDetector = new MimeDetector(),
        TextDecoderInterface $textDecoder = new TextDecoder(),
    ): EbookDriverInterface {
        return self::detect($source, 'book source', $mimeDetector, $textDecoder);
    }

    /**
     * @throws UnsupportedFormatException
     */
    private static function detect(
        BookSource $source,
        string $description,
        MimeDetectorInterface $mimeDetector,
        TextDecoderInterface $textDecoder,
    ): EbookDriverInterface {
        foreach (self::getCandidates($source, $mimeDetector, $textDecoder) as $driver) {
            if ($driver->isValid()) {
                return $driver;
            }
        }

        throw new UnsupportedFormatException(\sprintf('Unsupported format: "%s"', $description));
    }

    /**
     * Cheap magic-bytes sniffing to order drivers and skip obviously incompatible ones.
     *
     * @return list<EbookDriverInterface>
     */
    private static function getCandidates(BookSource $source, MimeDetectorInterface $mimeDetector, TextDecoderInterface $textDecoder): array
    {
        $head = $source->getHead(68);

        // ZIP container: EPUB, FB2-ZIP, HTMLZ, markdown or TXT-ZIP.
        if (\str_starts_with($head, "PK\x03\x04")) {
            return [
                new Epub3Driver($source, $mimeDetector, $textDecoder),
                new Fb2Driver($source, $mimeDetector, $textDecoder),
                new HtmlDriver($source, $mimeDetector, $textDecoder),
                new MarkdownDriver($source, $mimeDetector, $textDecoder),
                new TxtDriver($source, $mimeDetector, $textDecoder),
            ];
        }

        // Palm Database (MOBI/KF8): 4-byte type + 4-byte creator at offset 60.
        if (\strlen($head) >= 68 && 'BOOKMOBI' === \substr($head, 60, 8)) {
            return [
                new Azw3Driver($source, $mimeDetector, $textDecoder),
                new MobiDriver($source, $mimeDetector, $textDecoder),
                new Fb2Driver($source, $mimeDetector, $textDecoder),
                new MarkdownDriver($source, $mimeDetector, $textDecoder),
                new TxtDriver($source, $mimeDetector, $textDecoder),
            ];
        }

        // Plain XML (FB2), HTML, markdown or plain text, or an unreadable head.
        return [
            new Epub3Driver($source, $mimeDetector, $textDecoder),
            new Azw3Driver($source, $mimeDetector, $textDecoder),
            new MobiDriver($source, $mimeDetector, $textDecoder),
            new Fb2Driver($source, $mimeDetector, $textDecoder),
            new HtmlDriver($source, $mimeDetector, $textDecoder),
            new MarkdownDriver($source, $mimeDetector, $textDecoder),
            new TxtDriver($source, $mimeDetector, $textDecoder),
        ];
    }
}
