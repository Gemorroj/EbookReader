<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\EbookDriverInterface;
use EbookReader\EbookMetaInterface;
use EbookReader\Exception\ParserException;
use EbookReader\Meta\EpubMeta;

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

    public function read(): EbookMetaInterface
    {
        $dom = self::getPackage($this->file);
        $dom->getElementsByTagName('rootfile');

        /** @var \DOMElement $metadata */
        $metadata = $dom->getElementsByTagName('metadata')->item(0);
        /** @var \DOMElement $titleNode */
        $titleNode = $metadata->getElementsByTagName('title')->item(0);

        return new EpubMeta(
            $titleNode->nodeValue
        );
    }

    protected static function getPackage(string $file): \DOMDocument
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
        if (false === @$domContainer->loadXML($container)) { // throws \ValueError for php 8
            $zip->close();
            throw new ParserException();
        }

        $list = $domContainer->getElementsByTagName('rootfile');
        $node = $list->item(0);

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
        if (false === $package) {
            $zip->close();
            throw new ParserException();
        }

        $domPackage = new \DOMDocument('1.0', 'UTF-8');
        if (false === @$domPackage->loadXML($package)) { // throws \ValueError for php 8
            $zip->close();
            throw new ParserException();
        }

        return $domPackage;
    }

    public static function isValid(string $file): bool
    {
        try {
            self::getPackage($file);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }
}
