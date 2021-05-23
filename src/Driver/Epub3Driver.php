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

    public function getText(): string
    {
        // todo
        throw new \RuntimeException('Not implemented');
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
        $publisher = $this->makePublisher($metadataNode);
        $isbn = $this->makeIsbn($metadataNode, $version);
        $description = $this->makeDescription($metadataNode);
        $language = $this->makeLanguage($metadataNode);
        $license = $this->makeLicense($metadataNode);
        $publishDate = $this->makePublishDate($metadataNode);

        return new Epub3Meta(
            $title,
            $author,
            $publisher,
            $isbn,
            $description,
            $language,
            $license,
            $publishDate['year'],
            $publishDate['month'],
            $publishDate['day'],
        );
    }

    /**
     * @return array{year: int|null, month: int|null, day: int|null}
     */
    protected function makePublishDate(\DOMElement $metadataNode): ?array
    {
        // 3 - https://www.w3.org/publishing/epub3/epub-packages.html#sec-opf-dcdate
        // 2 - http://idpf.org/epub/20/spec/OPF_2.0.1_draft.htm#Section2.2.7

        /** @var \DomElement|null $dateNode */
        $dateNode = $metadataNode->getElementsByTagName('date')->item(0);

        $date = [
            'year' => null,
            'month' => null,
            'day' => null,
        ];

        if ($dateNode) {
            $dateStr = $dateNode->nodeValue;
            $dateStrLength = \strlen($dateStr);

            if (4 === $dateStrLength) {
                $date['year'] = (int) $dateStr;
            } elseif (7 === $dateStrLength) {
                $arr = \explode('-', $dateStr, 2);
                $date['year'] = (int) $arr[0];
                $date['month'] = (int) $arr[1];
            } else {
                $obj = new \DateTimeImmutable($dateStr, new \DateTimeZone('UTC'));
                $date['year'] = (int) $obj->format('Y');
                $date['month'] = (int) $obj->format('m');
                $date['day'] = (int) $obj->format('d');
            }
        }

        return $date;
    }

    protected function makeLicense(\DOMElement $metadataNode): ?string
    {
        // 3 - https://www.w3.org/publishing/epub3/epub-packages.html#sec-opf-dcmes-optional-def
        // 2 - http://idpf.org/epub/20/spec/OPF_2.0.1_draft.htm#Section2.2.15

        /** @var \DomElement|null $rightsNode */
        $rightsNode = $metadataNode->getElementsByTagName('rights')->item(0);

        if ($rightsNode) {
            return $rightsNode->nodeValue;
        }

        return null;
    }

    protected function makeLanguage(\DOMElement $metadataNode): ?string
    {
        // 3 - https://www.w3.org/publishing/epub3/epub-packages.html#sec-opf-dclanguage
        // 2 - http://idpf.org/epub/20/spec/OPF_2.0.1_draft.htm#Section2.2.12

        /** @var \DomElement $languageNode */
        $languageNode = $metadataNode->getElementsByTagName('language')->item(0);

        return $languageNode->nodeValue;
    }

    protected function makeDescription(\DOMElement $metadataNode): ?string
    {
        // 3 - https://www.w3.org/publishing/epub3/epub-packages.html#sec-opf-dcmes-optional-def
        // 2 - http://idpf.org/epub/20/spec/OPF_2.0.1_draft.htm#Section2.2.4

        /** @var \DomElement|null $descriptionNode */
        $descriptionNode = $metadataNode->getElementsByTagName('description')->item(0);

        if ($descriptionNode) {
            return \trim($descriptionNode->nodeValue);
        }

        return null;
    }

    protected function makeIsbn(\DOMElement $metadataNode, int $version): ?string
    {
        // 3 - https://www.w3.org/publishing/epub3/epub-packages.html#sec-opf-dcidentifier
        // 2 - http://idpf.org/epub/20/spec/OPF_2.0.1_draft.htm#Section2.2.10

        $identifierNodeList = $metadataNode->getElementsByTagName('identifier');

        if (3 === $version) {
            /** @var \DOMElement $identifierNode */
            foreach ($identifierNodeList as $identifierNode) {
                $identifier = $identifierNode->nodeValue;
                if (0 === \strpos($identifier, 'urn:isbn:')) {
                    return \substr($identifier, 9);
                }
            }
        } elseif (2 === $version) {
            /** @var \DOMElement $identifierNode */
            foreach ($identifierNodeList as $identifierNode) {
                $scheme = $identifierNode->getAttribute('opf:scheme');
                if ('ISBN' === $scheme) {
                    return $identifierNode->nodeValue;
                }
            }
        }

        return null;
    }

    protected function makePublisher(\DOMElement $metadataNode): ?string
    {
        // 3 - https://www.w3.org/publishing/epub3/epub-packages.html#sec-opf-dcmes-optional-def
        // 2 - http://idpf.org/epub/20/spec/OPF_2.0.1_draft.htm#Section2.2.5

        /** @var \DOMElement|null $publisherNode */
        $publisherNode = $metadataNode->getElementsByTagName('publisher')->item(0);

        if ($publisherNode) {
            return $publisherNode->nodeValue;
        }

        return null;
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
            $role = $creatorNode->getAttribute('opf:role');
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
