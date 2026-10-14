<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Cover\Cover;
use EbookReader\Exception\ParserException;
use EbookReader\Exception\UnsupportedFormatException;
use EbookReader\Format;
use EbookReader\Meta\Epub3Meta;
use EbookReader\Meta\PublishDate;
use EbookReader\Resource\Resource as EbookResource;
use EbookReader\Resource\ResourceType;
use EbookReader\Section\Epub3Section;
use EbookReader\TableOfContents\TableOfContents;

/**
 * @see https://www.w3.org/publishing/epub3/epub-spec.html
 * @see https://wiki.mobileread.com/wiki/EPUB
 */
final class Epub3Driver extends AbstractDriver
{
    /**
     * Attributes whose values may reference an asset or another document.
     */
    private const array REFERENCE_ATTRIBUTES = ['href', 'src', 'data'];

    /**
     * Elements whose references point at assets (images, fonts, stylesheets, media).
     */
    private const array ASSET_TAGS = ['img', 'image', 'link', 'source', 'video', 'audio', 'track', 'object', 'embed'];

    private ?\DOMElement $packageNode = null;
    private ?string $packageDirectory = null;

    #[\Override]
    public function getFormat(): Format
    {
        return Format::EPUB;
    }

    #[\Override]
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
     * Resolves a (URL-encoded, possibly relative) href to a ZIP entry name.
     * The base defaults to the package directory; pass the containing
     * document directory to resolve references inside content documents.
     */
    private function resolveZipPath(string $href, ?string $baseDir = null): string
    {
        $path = \rawurldecode($href);
        $fragment = \strpos($path, '#');
        if (false !== $fragment) {
            $path = \substr($path, 0, $fragment);
        }

        $base = $baseDir ?? $this->packageDirectory;
        if (null !== $base && '' !== $base && '.' !== $base) {
            $path = $base.'/'.$path;
        }

        $parts = [];
        foreach (\explode('/', $path) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part) {
                \array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return \implode('/', $parts);
    }

    /**
     * @return array<string, array{href: string, mediaType: string, path: string, properties: string}>
     */
    private function manifestMap(\DOMElement $manifestNode): array
    {
        $manifest = [];
        /** @var \DOMElement $item */
        foreach ($manifestNode->getElementsByTagName('item') as $item) {
            $id = $item->getAttribute('id');
            if ('' === $id) {
                continue;
            }

            $manifest[$id] = [
                'href' => $item->getAttribute('href'),
                'mediaType' => $item->getAttribute('media-type'),
                'path' => $this->resolveZipPath($item->getAttribute('href')),
                'properties' => $item->getAttribute('properties'),
            ];
        }

        return $manifest;
    }

    /**
     * Builds the book asset map (images, fonts, stylesheets, media) keyed by
     * their canonical ZIP path, rewriting references inside stylesheets.
     *
     * @param array<string, array{href: string, mediaType: string, path: string, properties: string}> $manifest
     *
     * @return array{0: array<string, EbookResource>, 1: array<string, string[]>}
     */
    private function collectAssets(\ZipArchive $zip, array $manifest): array
    {
        $knownPaths = [];
        foreach ($manifest as $item) {
            $knownPaths[$item['path']] = true;
        }

        $assets = [];
        $styleReferences = [];
        foreach ($manifest as $item) {
            if (ResourceType::OTHER === ResourceType::fromMime($item['mediaType'])) {
                continue;
            }

            $content = $zip->getFromName($item['path']);
            if (false === $content) {
                continue;
            }

            $path = $item['path'];
            if ('text/css' === $item['mediaType']) {
                [$content, $references] = $this->rewriteCss($content, \dirname($path), $knownPaths);
                $styleReferences[$path] = \array_keys($references);
            }

            $mime = '' !== $item['mediaType'] ? $item['mediaType'] : ($this->getMimeDetector()->detect($content) ?? '');
            $assets[$path] = new EbookResource($path, $content, $mime);
        }

        return [$assets, $styleReferences];
    }

    /**
     * Rewrites relative asset/document references to canonical ZIP paths and
     * returns the set of referenced asset paths.
     *
     * @param array<string, true> $knownPaths
     *
     * @return array<string, true>
     */
    private function rewriteReferences(\DOMDocument $dom, string $baseDir, array $knownPaths): array
    {
        $referenced = [];
        /** @var \DOMElement $node */
        foreach ($dom->getElementsByTagName('*') as $node) {
            $isAsset = \in_array(\strtolower($node->localName ?? $node->nodeName), self::ASSET_TAGS, true);

            /** @var \DOMAttr $attribute */
            foreach ($node->attributes as $attribute) {
                if (!\in_array(\strtolower($attribute->localName), self::REFERENCE_ATTRIBUTES, true)) {
                    continue;
                }

                $resolved = $this->canonicalReference($attribute->value, $baseDir);
                if (null === $resolved) {
                    continue;
                }
                [$path, $fragment] = $resolved;
                if (!isset($knownPaths[$path])) {
                    continue;
                }

                $attribute->value = $path.$fragment;
                if ($isAsset) {
                    $referenced[$path] = true;
                }
            }
        }

        return $referenced;
    }

    /**
     * @param array<string, true> $knownPaths
     *
     * @return array{0: string, 1: array<string, true>}
     */
    private function rewriteCss(string $css, string $baseDir, array $knownPaths): array
    {
        $references = [];
        $css = \preg_replace_callback(
            '~url\(\s*([\'"]?)([^\'")]+)\1\s*\)~i',
            function (array $matches) use ($baseDir, $knownPaths, &$references): string {
                $resolved = $this->canonicalReference($matches[2], $baseDir);
                if (null === $resolved) {
                    return $matches[0];
                }
                [$path] = $resolved;
                if (!isset($knownPaths[$path])) {
                    return $matches[0];
                }
                $references[$path] = true;

                return 'url('.$matches[1].$path.$matches[1].')';
            },
            $css
        ) ?? $css;

        return [$css, $references];
    }

    /**
     * Resolves a non-fragment, non-external reference to [canonical path, fragment].
     *
     * @return array{0: string, 1: string}|null
     */
    private function canonicalReference(string $reference, string $baseDir): ?array
    {
        if ('' === $reference || '#' === $reference[0] || $this->isExternal($reference)) {
            return null;
        }

        $fragment = '';
        $hash = \strpos($reference, '#');
        if (false !== $hash) {
            $fragment = \substr($reference, $hash);
            $reference = \substr($reference, 0, $hash);
        }

        if ('' === $reference) {
            return null;
        }

        return [$this->resolveZipPath($reference, $baseDir), $fragment];
    }

    private function isExternal(string $reference): bool
    {
        return \str_starts_with($reference, '//') || \preg_match('~^[a-z][a-z0-9+.-]*:~i', $reference) > 0;
    }

    /**
     * @return Epub3Section[]
     */
    #[\Override]
    public function getSections(): array
    {
        $packageNode = $this->getPackageNode();
        $version = (int) $packageNode->getAttribute('version');

        /** @var \DOMElement|null $manifestNode */
        $manifestNode = $packageNode->getElementsByTagName('manifest')->item(0);
        if (!$manifestNode instanceof \DOMElement) {
            throw new ParserException();
        }
        $manifest = $this->manifestMap($manifestNode);

        /** @var \DOMElement|null $spineNode */
        $spineNode = $packageNode->getElementsByTagName('spine')->item(0);
        if (!$spineNode instanceof \DOMElement) {
            throw new ParserException();
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($this->getFile(), \ZipArchive::RDONLY)) {
            throw new ParserException();
        }

        try {
            [$assets, $styleReferences] = $this->collectAssets($zip, $manifest);

            $knownPaths = [];
            foreach ($manifest as $item) {
                $knownPaths[$item['path']] = true;
            }

            $data = [];
            /** @var \DOMElement $spinItemNode */
            foreach ($spineNode->getElementsByTagName('itemref') as $spinItemNode) {
                $id = $spinItemNode->getAttribute('idref');
                if (!isset($manifest[$id])) {
                    throw new ParserException();
                }
                $item = $manifest[$id];
                $documentPath = $item['path'];

                $content = $zip->getFromName($documentPath);
                if (false === $content) {
                    throw new ParserException();
                }

                $domFile = new \DOMDocument('1.0', 'UTF-8');
                if (false === $domFile->loadXML($content, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS)) {
                    throw new ParserException();
                }

                $isNavigation = 2 === $version ? null : false;
                $properties = \preg_split('/\s+/', $item['properties'], -1, \PREG_SPLIT_NO_EMPTY);
                if ($properties && \in_array('nav', $properties, true)) {
                    $isNavigation = true;
                }

                $referenced = $this->rewriteReferences($domFile, \dirname($documentPath), $knownPaths);
                foreach (\array_keys($referenced) as $path) {
                    foreach ($styleReferences[$path] ?? [] as $styleReference) {
                        $referenced[$styleReference] = true;
                    }
                }

                $resources = [];
                foreach (\array_keys($referenced) as $path) {
                    if (isset($assets[$path])) {
                        $resources[] = $assets[$path];
                    }
                }

                $title = $domFile->getElementsByTagName('title')->item(0)?->textContent;
                $text = $domFile->saveXML($domFile->documentElement);
                if (false === $text) {
                    throw new ParserException();
                }

                $data[] = new Epub3Section($documentPath, $text, $title, $isNavigation, $resources);
            }
        } finally {
            $zip->close();
        }

        return $data;
    }

    #[\Override]
    public function getCover(): ?Cover
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
        $zipFilePath = $this->resolveZipPath($potentialCoverNode->getAttribute('href'));

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

        if ('' === $mime) {
            $mime = $this->getMimeDetector()->detect($content) ?? '';
        }

        return new Cover($content, $mime);
    }

    /**
     * @return TableOfContents[]
     */
    #[\Override]
    public function getTableOfContents(): array
    {
        $packageNode = $this->getPackageNode();
        /** @var \DOMElement|null $manifestNode */
        $manifestNode = $packageNode->getElementsByTagName('manifest')->item(0);
        if (!$manifestNode instanceof \DOMElement) {
            return [];
        }

        $navHref = null;
        $ncxHref = null;
        /** @var \DOMElement $item */
        foreach ($manifestNode->getElementsByTagName('item') as $item) {
            if (null === $navHref) {
                $properties = \preg_split('/\s+/', $item->getAttribute('properties'), -1, \PREG_SPLIT_NO_EMPTY);
                if ($properties && \in_array('nav', $properties, true)) {
                    $navHref = $item->getAttribute('href');
                }
            }
            if (null === $ncxHref && 'application/x-dtbncx+xml' === $item->getAttribute('media-type')) {
                $ncxHref = $item->getAttribute('href');
            }
        }

        $spineMap = $this->spineIndexMap();

        $zip = new \ZipArchive();
        if (true !== $zip->open($this->getFile(), \ZipArchive::RDONLY)) {
            throw new ParserException();
        }

        try {
            foreach ([$navHref, $ncxHref] as $href) {
                if (null === $href) {
                    continue;
                }
                $resolved = $this->resolveZipPath($href);
                $content = $zip->getFromName($resolved);
                if (false === $content) {
                    continue;
                }
                $dom = new \DOMDocument('1.0', 'UTF-8');
                if (false === @$dom->loadXML($content, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS)) {
                    continue;
                }

                $baseDir = \dirname($resolved);
                $entries = $href === $navHref
                    ? $this->parseNav($dom, $baseDir, $spineMap)
                    : $this->parseNcx($dom, $baseDir, $spineMap);
                if ($entries) {
                    return $entries;
                }
            }
        } finally {
            $zip->close();
        }

        return [];
    }

    /**
     * @param array<string, int> $spineMap
     *
     * @return TableOfContents[]
     */
    private function parseNav(\DOMDocument $dom, string $baseDir, array $spineMap): array
    {
        $nav = null;
        $fallback = null;
        /** @var \DOMElement $navNode */
        foreach ($dom->getElementsByTagName('nav') as $navNode) {
            $fallback ??= $navNode;
            $type = $navNode->getAttribute('epub:type');
            if ('' === $type) {
                $type = $navNode->getAttributeNS('http://www.idpf.org/2007/ops', 'type');
            }
            if ('' !== $type && \str_contains($type, 'toc')) {
                $nav = $navNode;
                break;
            }
        }
        $nav ??= $fallback;
        if (null === $nav) {
            return [];
        }

        /** @var \DOMElement|null $ol */
        $ol = null;
        foreach ($nav->childNodes as $child) {
            if ($child instanceof \DOMElement && 'ol' === $child->localName) {
                $ol = $child;
                break;
            }
        }
        if (null === $ol) {
            return [];
        }

        $entries = [];
        $this->walkOl($ol, 1, $entries, $baseDir, $spineMap);

        return $entries;
    }

    /**
     * @param TableOfContents[]  $entries
     * @param array<string, int> $spineMap
     */
    private function walkOl(\DOMElement $ol, int $level, array &$entries, string $baseDir, array $spineMap): void
    {
        foreach ($ol->childNodes as $li) {
            if (!$li instanceof \DOMElement || 'li' !== $li->localName) {
                continue;
            }

            foreach ($li->childNodes as $child) {
                if ($child instanceof \DOMElement && 'a' === $child->localName) {
                    $title = \mb_trim($child->textContent);
                    if ('' !== $title) {
                        [$sectionIndex, $anchor] = $this->resolveTarget($child->getAttribute('href'), $baseDir, $spineMap);
                        $entries[] = new TableOfContents($title, $level, $sectionIndex, $anchor);
                    }
                    break;
                }
            }

            foreach ($li->childNodes as $child) {
                if ($child instanceof \DOMElement && 'ol' === $child->localName) {
                    $this->walkOl($child, $level + 1, $entries, $baseDir, $spineMap);
                }
            }
        }
    }

    /**
     * @param array<string, int> $spineMap
     *
     * @return TableOfContents[]
     */
    private function parseNcx(\DOMDocument $dom, string $baseDir, array $spineMap): array
    {
        /** @var \DOMElement|null $navMap */
        $navMap = $dom->getElementsByTagName('navMap')->item(0);
        if (!$navMap instanceof \DOMElement) {
            return [];
        }

        $entries = [];
        $this->walkNavPoints($navMap, 1, $entries, $baseDir, $spineMap);

        return $entries;
    }

    /**
     * @param TableOfContents[]  $entries
     * @param array<string, int> $spineMap
     */
    private function walkNavPoints(\DOMElement $parent, int $level, array &$entries, string $baseDir, array $spineMap): void
    {
        foreach ($parent->childNodes as $navPoint) {
            if (!$navPoint instanceof \DOMElement || 'navPoint' !== $navPoint->localName) {
                continue;
            }

            $text = $navPoint->getElementsByTagName('text')->item(0)?->textContent;
            $title = null === $text ? '' : \mb_trim($text);
            if ('' !== $title) {
                $src = $navPoint->getElementsByTagName('content')->item(0)?->getAttribute('src');
                [$sectionIndex, $anchor] = null === $src ? [null, null] : $this->resolveTarget($src, $baseDir, $spineMap);
                $entries[] = new TableOfContents($title, $level, $sectionIndex, $anchor);
            }

            $this->walkNavPoints($navPoint, $level + 1, $entries, $baseDir, $spineMap);
        }
    }

    /**
     * Maps each spine document (ZIP-relative) to its index in getSections().
     *
     * @return array<string, int>
     */
    private function spineIndexMap(): array
    {
        $packageNode = $this->getPackageNode();
        /** @var \DOMElement|null $manifestNode */
        $manifestNode = $packageNode->getElementsByTagName('manifest')->item(0);
        /** @var \DOMElement|null $spineNode */
        $spineNode = $packageNode->getElementsByTagName('spine')->item(0);
        if (!$manifestNode instanceof \DOMElement || !$spineNode instanceof \DOMElement) {
            return [];
        }

        $hrefs = [];
        /** @var \DOMElement $item */
        foreach ($manifestNode->getElementsByTagName('item') as $item) {
            $hrefs[$item->getAttribute('id')] = $item->getAttribute('href');
        }

        $map = [];
        $index = 0;
        /** @var \DOMElement $itemref */
        foreach ($spineNode->getElementsByTagName('itemref') as $itemref) {
            $id = $itemref->getAttribute('idref');
            if (isset($hrefs[$id])) {
                $map[$this->resolveZipPath($hrefs[$id])] = $index;
            }
            ++$index;
        }

        return $map;
    }

    /**
     * @param array<string, int> $spineMap
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function resolveTarget(string $href, string $baseDir, array $spineMap): array
    {
        if ('' === $href || $this->isExternal($href)) {
            return [null, null];
        }

        $anchor = null;
        $pos = \strpos($href, '#');
        if (false !== $pos) {
            $anchor = \substr($href, $pos + 1);
            $href = \substr($href, 0, $pos);
            if ('' === $anchor) {
                $anchor = null;
            }
        }

        if ('' === $href) {
            return [null, $anchor];
        }

        return [$spineMap[$this->resolveZipPath($href, $baseDir)] ?? null, $anchor];
    }

    #[\Override]
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
        $authors = $this->makeAuthors($metadataNode);
        $publisher = $this->makePublisher($metadataNode);
        $isbn = $this->makeIsbn($metadataNode, $version);
        $description = $this->makeDescription($metadataNode);
        $language = $this->makeLanguage($metadataNode);
        $license = $this->makeLicense($metadataNode);
        $publishDate = $this->makePublishDate($metadataNode);
        $subjects = $this->makeSubjects($metadataNode);

        return new Epub3Meta(
            title: $title,
            authors: $authors,
            publisher: $publisher,
            isbn: $isbn,
            description: $description,
            language: $language,
            subjects: $subjects,
            license: $license,
            publishDate: $publishDate,
        );
    }

    /**
     * @return string[]
     */
    protected function makeSubjects(\DOMElement $metadataNode): array
    {
        $subjects = [];
        /** @var \DOMElement $subjectNode */
        foreach ($metadataNode->getElementsByTagName('subject') as $subjectNode) {
            $value = \mb_trim($subjectNode->nodeValue);
            if ('' !== $value) {
                $subjects[] = $value;
            }
        }

        return $subjects;
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

    /**
     * @return string[]
     */
    protected function makeAuthors(\DOMElement $metadataNode): array
    {
        // 3 - https://www.w3.org/publishing/epub3/epub-packages.html#sec-opf-dccreator
        // 2 - http://idpf.org/epub/20/spec/OPF_2.0.1_draft.htm#Section2.2.2

        $authors = $allAuthors = [];
        /** @var \DOMElement $creatorNode */
        foreach ($metadataNode->getElementsByTagName('creator') as $creatorNode) {
            $allAuthors[] = $creatorNode->nodeValue;
            if ('aut' === $creatorNode->getAttribute('opf:role')) {
                $authors[] = $creatorNode->nodeValue;
            }
        }

        return $authors ?: $allAuthors;
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
