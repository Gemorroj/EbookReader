<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\EbookDriverInterface;
use EbookReader\Exception\ParserException;
use EbookReader\Meta\Epub3Meta;

/**
 * @see https://www.w3.org/publishing/epub3/epub-spec.html
 */
class Epub3Driver implements EbookDriverInterface
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    public function read(): Epub3Meta
    {
        $metadataNode = self::getPackageMetadata($this->file);

        /** @var \DOMElement $titleNode */
        $titleNode = $metadataNode->getElementsByTagName('title')->item(0);

        return new Epub3Meta(
            $titleNode->nodeValue
        );
    }

    protected static function getPackageMetadata(string $file): \DOMElement
    {
        $zip = new \ZipArchive();
        $res = $zip->open($file, \ZipArchive::RDONLY);
        if (true !== $res) {
            throw new ParserException();
        }

        $container = $zip->getFromName('META-INF/container.xml');

        if (false === $container) {
            $zip->close();
            throw new ParserException();
        }

        $domContainer = new \DOMDocument('1.0', 'UTF-8');
        if (false === $domContainer->loadXML($container, \LIBXML_NOENT | \LIBXML_NOERROR)) { // throws \ValueError for php 8
            $zip->close();
            throw new ParserException();
        }

        $node = $domContainer->getElementsByTagName('rootfile')->item(0);

        if (!$node || !$node->attributes) {
            $zip->close();
            throw new ParserException();
        }

        /** @var \DomAttr|null $packageName */
        $packageName = $node->attributes->getNamedItem('full-path');
        if (!$packageName) {
            $zip->close();
            throw new ParserException();
        }

        $package = $zip->getFromName($packageName->value);
        $zip->close();
        if (false === $package) {
            throw new ParserException();
        }

        $domPackage = new \DOMDocument('1.0', 'UTF-8');
        if (false === $domPackage->loadXML($package, \LIBXML_NOENT | \LIBXML_NOERROR)) { // throws \ValueError for php 8
            throw new ParserException();
        }

        /** @var \DOMElement|null $metadataNode */
        $metadataNode = $domPackage->getElementsByTagName('metadata')->item(0);
        if (!$metadataNode) {
            throw new ParserException();
        }

        return $metadataNode;
    }

    public static function isValid(string $file): bool
    {
        try {
            self::getPackageMetadata($file);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }
}