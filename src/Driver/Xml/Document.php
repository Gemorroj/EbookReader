<?php

declare(strict_types=1);

namespace EbookReader\Driver\Xml;

use EbookReader\Exception\ParserException;

/**
 * Parses XML strings into DOM documents with the flags the readers rely on
 * (no network, no error output, ignorable whitespace dropped).
 */
final class Document
{
    private function __construct()
    {
    }

    /**
     * @throws ParserException when the content is not well-formed XML
     */
    public static function parse(string $content): \Dom\XMLDocument
    {
        try {
            return \Dom\XMLDocument::createFromString($content, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
        } catch (\DOMException $exception) {
            throw new ParserException(previous: $exception);
        }
    }
}
