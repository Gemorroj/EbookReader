<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Cover\Cover;
use EbookReader\Data\Epub3Data;
use EbookReader\EbookCoverInterface;
use EbookReader\Exception\ParserException;
use EbookReader\Exception\UnsupportedFormatException;
use EbookReader\Meta\Epub3Meta;
use EbookReader\Meta\PublishDate;
use EbookReader\Resource\Style;
use EbookReader\Resource\StyleType;

/**
 * @see https://www.w3.org/publishing/epub3/epub-spec.html
 * @see https://wiki.mobileread.com/wiki/EPUB
 */
final class Epub3Driver extends AbstractDriver
{
    private ?\DOMElement $packageNode = null;
    private ?string $packageDirectory = null;

    public function isValid(): bool
    {
        try {
            $this->parsePackage();
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    private function getPackageNode(): \DOMElement
    {
        if (null === $this->packageNode || null === $this->packageDirectory) {
            [$this->packageNode, $this->packageDirectory] = $this->parsePackage();
        }

        return $this->packageNode;
    }

    /**
     * Should unzip the EPUB file for references.
     *
     * @return Epub3Data[]
     */
    public function getData(): array
    {
        $packageNode = $this->getPackageNode();
        $version = (int) $packageNode->getAttribute('version');

        /** @var \DOMElement|null $manifestNode */
        $manifestNode = $packageNode->getElementsByTagName('manifest')->item(0);
        if (!$manifestNode instanceof \DOMElement) {
            throw new ParserException();
        }
        $manifestItemsNode = $manifestNode->getElementsByTagName('item');

        /** @var \DOMElement|null $spineNode */
        $spineNode = $packageNode->getElementsByTagName('spine')->item(0);
        if (!$spineNode instanceof \DOMElement) {
            throw new ParserException();
        }

        $zip = new \ZipArchive();
        $res = $zip->open($this->getFile(), \ZipArchive::RDONLY);
        if (true !== $res) {
            throw new ParserException();
        }

        try {
            $data = [];
            /** @var \DOMElement $spinItemNode */
            foreach ($spineNode->getElementsByTagName('itemref') as $spinItemNode) {
                $itemNode = null;
                $id = $spinItemNode->getAttribute('idref');
                /** @var \DOMElement $manifestItemNode */
                foreach ($manifestItemsNode as $manifestItemNode) {
                    if ($id === $manifestItemNode->getAttribute('id')) {
                        $itemNode = $manifestItemNode;
                    }
                }
                if (!$itemNode) {
                    throw new ParserException();
                }

                // @see https://www.w3.org/publishing/epub3/epub-packages.html#app-item-properties-vocab
                $isNavigation = 2 === $version ? null : false;
                if ($itemNode->hasAttribute('properties')) {
                    $properties = \preg_split('/\s+/', $itemNode->getAttribute('properties'), -1, \PREG_SPLIT_NO_EMPTY);
                    if ($properties && \in_array('nav', $properties, true)) {
                        $isNavigation = true;
                    }
                }

                $file = $itemNode->getAttribute('href');
                $zipFilePath = '.' === $this->packageDirectory ? $file : $this->packageDirectory.'/'.$file;

                $content = $zip->getFromName($zipFilePath);
                if (false === $content) {
                    throw new ParserException();
                }

                $domFile = new \DOMDocument('1.0', 'UTF-8');
                if (false === $domFile->loadXML($content, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS)) {
                    throw new ParserException();
                }

                /** @var \DOMElement|null $headNode */
                $headNode = $domFile->getElementsByTagName('head')->item(0);
                if (!$headNode instanceof \DOMElement) {
                    throw new ParserException();
                }
                $title = $headNode->getElementsByTagName('title')->item(0)?->textContent;

                $styles = [];
                $linkNodes = $headNode->getElementsByTagName('link');
                /** @var \DOMElement $linkNode */
                foreach ($linkNodes as $linkNode) {
                    if ('stylesheet' === $linkNode->getAttribute('rel')) {
                        $styles[] = new Style($linkNode->getAttribute('href'), StyleType::LINK);
                    }
                }
                $styleNodes = $headNode->getElementsByTagName('style');
                /** @var \DOMElement $styleNode */
                foreach ($styleNodes as $styleNode) {
                    $styles[] = new Style($styleNode->nodeValue, StyleType::CSS);
                }

                /** @var \DOMElement|null $bodyNode */
                $bodyNode = $domFile->getElementsByTagName('body')->item(0);
                if (!$bodyNode instanceof \DOMElement) {
                    throw new ParserException();
                }
                $text = '';
                foreach ($bodyNode->childNodes as $childNode) {
                    $text .= $bodyNode->ownerDocument->saveHTML($childNode);
                }

                $bodyClass = '';
                $bodyStyle = '';

                if ($bodyNode->hasAttribute('class')) {
                    $bodyClass = ' class="'.$bodyNode->getAttribute('class').'"';
                }
                if ($bodyNode->hasAttribute('style')) {
                    $bodyStyle = ' style="'.$bodyNode->getAttribute('style').'"';
                }

                $text = '<div'.$bodyClass.$bodyStyle.'>'.$text.'</div>';

                $data[] = new Epub3Data($text, $title, $styles, $isNavigation);
            }
        } finally {
            $zip->close();
        }

        return $data;
    }

    public function getCover(): ?EbookCoverInterface
    {
        // https://wiki.mobileread.com/wiki/Ebook_Covers

        $packageNode = $this->getPackageNode();
        /** @var \DOMElement|null $metadataNode */
        $metadataNode = $packageNode->getElementsByTagName('metadata')->item(0);
        if (!$metadataNode instanceof \DOMElement) {
            return null;
        }
        $metaNodeList = $metadataNode->getElementsByTagName('meta');

        $manifestId = null;
        /** @var \DOMElement $metaNode */
        foreach ($metaNodeList as $metaNode) {
            $name = $metaNode->getAttribute('name');
            if ('cover' === $name) {
                $manifestId = $metaNode->getAttribute('content');
                break;
            }
        }

        /** @var \DOMElement[] $potentialCoverNodes */
        $potentialCoverNodes = [];
        /** @var \DOMElement|null $manifestNode */
        $manifestNode = $packageNode->getElementsByTagName('manifest')->item(0);
        if (!$manifestNode instanceof \DOMElement) {
            return null;
        }
        $manifestItemsNode = $manifestNode->getElementsByTagName('item');
        /** @var \DOMElement $manifestItemNode */
        foreach ($manifestItemsNode as $manifestItemNode) {
            if ($manifestId && $manifestId === $manifestItemNode->getAttribute('id') && \str_starts_with($manifestItemNode->getAttribute('media-type'), 'image/')) {
                \array_unshift($potentialCoverNodes, $manifestItemNode);
            } elseif (\in_array($manifestItemNode->getAttribute('id'), ['cover', 'cover-image', 'cover-img'], true) && \str_starts_with($manifestItemNode->getAttribute('media-type'), 'image/')) {
                $potentialCoverNodes[] = $manifestItemNode;
            }
        }

        $potentialCoverNode = $potentialCoverNodes[0] ?? null;
        if (!$potentialCoverNode) {
            return null;
        }

        $mime = $potentialCoverNode->getAttribute('media-type');
        $file = $potentialCoverNode->getAttribute('href');
        $zipFilePath = '.' === $this->packageDirectory ? $file : $this->packageDirectory.'/'.$file;

        $zip = new \ZipArchive();
        $res = $zip->open($this->getFile(), \ZipArchive::RDONLY);
        if (true !== $res) {
            throw new ParserException();
        }

        try {
            $content = $zip->getFromName($zipFilePath);
            if (false === $content) {
                throw new ParserException();
            }
        } finally {
            $zip->close();
        }

        return new Cover($content, $mime);
    }

    public function getMeta(): Epub3Meta
    {
        $packageNode = $this->getPackageNode();

        $version = (int) $packageNode->getAttribute('version');
        if (!\in_array($version, [2, 3], true)) {
            throw new UnsupportedFormatException();
        }
        /** @var \DOMElement|null $metadataNode */
        $metadataNode = $packageNode->getElementsByTagName('metadata')->item(0);
        if (!$metadataNode instanceof \DOMElement) {
            throw new ParserException();
        }

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
            $publishDate,
        );
    }

    /**
     * dc:date per W3CDTF/ISO 8601: YYYY, YYYY-MM, YYYY-MM-DD or full date-time.
     */
    protected function makePublishDate(\DOMElement $metadataNode): PublishDate
    {
        // 3 - https://www.w3.org/publishing/epub3/epub-packages.html#sec-opf-dcdate
        // 2 - http://idpf.org/epub/20/spec/OPF_2.0.1_draft.htm#Section2.2.7

        /** @var \DOMElement|null $dateNode */
        $dateNode = $metadataNode->getElementsByTagName('date')->item(0);

        if (!$dateNode) {
            return new PublishDate();
        }

        $dateStr = \trim($dateNode->nodeValue);
        if ('' === $dateStr) {
            return new PublishDate();
        }

        if (\preg_match('/^(\d{4})$/', $dateStr, $matches)) {
            return new PublishDate((int) $matches[1]);
        }

        if (\preg_match('/^(\d{4})-(\d{1,2})$/', $dateStr, $matches)) {
            return new PublishDate((int) $matches[1], (int) $matches[2]);
        }

        try {
            $obj = new \DateTimeImmutable($dateStr, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return new PublishDate();
        }

        return new PublishDate((int) $obj->format('Y'), (int) $obj->format('m'), (int) $obj->format('d'));
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

        /** @var \DOMElement|null $languageNode */
        $languageNode = $metadataNode->getElementsByTagName('language')->item(0);

        return $languageNode?->nodeValue;
    }

    protected function makeDescription(\DOMElement $metadataNode): ?string
    {
        // 3 - https://www.w3.org/publishing/epub3/epub-packages.html#sec-opf-dcmes-optional-def
        // 2 - http://idpf.org/epub/20/spec/OPF_2.0.1_draft.htm#Section2.2.4

        /** @var \DomElement|null $descriptionNode */
        $descriptionNode = $metadataNode->getElementsByTagName('description')->item(0);

        if ($descriptionNode) {
            return \mb_trim($descriptionNode->nodeValue);
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
                if (\str_starts_with($identifier, 'urn:isbn:')) {
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

    /**
     * @return array{0: \DOMElement, 1: string}
     */
    private function parsePackage(): array
    {
        $zip = new \ZipArchive();
        $res = $zip->open($this->getFile(), \ZipArchive::RDONLY);
        if (true !== $res) {
            throw new ParserException();
        }

        try {
            $container = $zip->getFromName('META-INF/container.xml');
            if (false === $container) {
                throw new ParserException();
            }

            $domContainer = new \DOMDocument('1.0', 'UTF-8');
            if (false === $domContainer->loadXML($container, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS)) {
                throw new ParserException();
            }

            $node = $domContainer->getElementsByTagName('rootfile')->item(0);
            if (!$node instanceof \DOMElement) {
                throw new ParserException();
            }

            /** @var \DOMAttr|null $packageName */
            $packageName = $node->attributes->getNamedItem('full-path');
            if (!$packageName) {
                throw new ParserException();
            }

            $package = $zip->getFromName($packageName->value);
            if (false === $package) {
                throw new ParserException();
            }
        } finally {
            $zip->close();
        }

        $domPackage = new \DOMDocument('1.0', 'UTF-8');
        if (false === $domPackage->loadXML($package, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS)) {
            throw new ParserException();
        }

        /** @var \DOMElement|null $packageNode */
        $packageNode = $domPackage->getElementsByTagName('package')->item(0);
        if (!$packageNode) {
            throw new ParserException();
        }

        return [$packageNode, \dirname($packageName->value)];
    }
}
