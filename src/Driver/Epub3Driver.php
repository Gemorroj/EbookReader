<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Exception\ParserException;
use EbookReader\Meta\Epub3Meta;

/**
 * @see https://www.w3.org/publishing/epub3/epub-spec.html
 * @see https://wiki.mobileread.com/wiki/EPUB
 */
class Epub3Driver extends AbstractDriver
{
    private ?\DOMElement $packageNode = null;

    public function isValid(): bool
    {
        try {
            $this->getPackageNode();
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    public function getMeta(): Epub3Meta
    {
        // todo: check version. 2 or 3
        $packageNode = $this->getPackageNode();

        /** @var \DOMElement $metadataNode */
        $metadataNode = $packageNode->getElementsByTagName('metadata')->item(0);

        /** @var \DOMElement $titleNode */
        $titleNode = $metadataNode->getElementsByTagName('title')->item(0);

        return new Epub3Meta(
            $titleNode->nodeValue
        );
    }

    protected function getPackageNode(): \DOMElement
    {
        if ($this->packageNode) {
            return $this->packageNode;
        }

        $zip = new \ZipArchive();
        $res = $zip->open($this->getFile(), \ZipArchive::RDONLY);
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

        /** @var \DOMElement|null $packageNode */
        $packageNode = $domPackage->getElementsByTagName('package')->item(0);
        if (!$packageNode) {
            throw new ParserException();
        }

        $this->packageNode = $packageNode;

        return $this->packageNode;
    }
}
