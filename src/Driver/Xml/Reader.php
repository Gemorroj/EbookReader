<?php

declare(strict_types=1);

namespace EbookReader\Driver\Xml;

use EbookReader\Exception\ParserException;

/**
 * Streaming XML reading: opens an XMLReader over a file with the flags the
 * readers rely on and expands nodes into DOM elements.
 */
final class Reader
{
    private function __construct()
    {
    }

    /**
     * @throws ParserException when the file cannot be opened
     */
    public static function open(string $file): \XMLReader
    {
        $reader = @\XMLReader::open($file, 'UTF-8', \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
        if (!$reader) {
            throw new ParserException();
        }

        return $reader;
    }

    /**
     * Expands the reader's current node into a DOM element.
     *
     * @throws ParserException
     */
    public static function expand(\XMLReader $reader): \DOMElement
    {
        $element = $reader->expand(new \DOMDocument('1.0', 'UTF-8'));
        if (!$element instanceof \DOMElement) {
            throw new ParserException();
        }

        return $element;
    }
}
