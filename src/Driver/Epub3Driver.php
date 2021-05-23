<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Exception\ParserException;
use EbookReader\Exception\UnsupportedFormatException;
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

    public function getCover(): ?string
    {
        // todo
        // For Apple's iBooks to identify and use a cover image, it's necessary to add metadata to the opf file identifying the cover image. <meta name="cover" content="[cover image id]" /> where [cover image id] is the id given to the cover image in the manifest section of the OPF file.
        // https://wiki.mobileread.com/wiki/Ebook_Covers
        // for manifest check `id="cover` and `media-type="image/`
        throw new \RuntimeException('Not implemented');
    }

    public function getMeta(): Epub3Meta
    {
        $packageNode = $this->getPackageNode();

        $version = (int) $packageNode->getAttribute('version');
        if (!\in_array($version, [2, 3], true)) {
            throw new UnsupportedFormatException();
        }
        /** @var \DOMElement $metadataNode */
        $metadataNode = $packageNode->getElementsByTagName('metadata')->item(0);

        $title = $this->makeTitle($metadataNode);
        $author = $this->makeAuthor($metadataNode);

        return new Epub3Meta(
            $title,
            $author
        );
    }

    protected function makeTitle(\DOMElement $metadataNode): string
    {
        // 3 - https://www.w3.org/publishing/epub3/epub-packages.html#sec-opf-dctitle
        // 2 - http://idpf.org/epub/20/spec/OPF_2.0.1_draft.htm#Section2.2.1

        $titleNodeList = $metadataNode->getElementsByTagName('title');

        $titles = [];
        /** @var \DOMElement $titleNode */
        foreach ($titleNodeList as $titleNode) {
            $titles[] = $titleNode->nodeValue;
        }

        return \implode(', ', $titles);
    }

    protected function makeAuthor(\DOMElement $metadataNode): ?string
    {
        // 3 - https://www.w3.org/publishing/epub3/epub-packages.html#sec-opf-dccreator
        // 2 - http://idpf.org/epub/20/spec/OPF_2.0.1_draft.htm#Section2.2.2

        $creatorNodeList = $metadataNode->getElementsByTagName('creator');
        if (!$creatorNodeList->length) {
            return null;
        }

        $authors = $allAuthors = [];
        /** @var \DOMElement $creatorNode */
        foreach ($creatorNodeList as $creatorNode) {
            $allAuthors[] = $creatorNode->nodeValue;
            $role = $creatorNode->getAttribute('role');
            if ('aut' === $role) {
                $authors[] = $creatorNode->nodeValue;
            }
        }

        if ($authors) {
            return \implode(', ', $authors);
        }

        return \implode(', ', $allAuthors);
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
