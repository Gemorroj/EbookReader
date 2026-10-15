<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Exception\EncryptedBookException;
use EbookReader\Exception\ParserException;
use EbookReader\Exception\UnsupportedFormatException;
use EbookReader\Model\Cover\Cover;
use EbookReader\Model\Format;
use EbookReader\Model\Meta\Metadata;
use EbookReader\Model\Resource\Resource as EbookResource;
use EbookReader\Model\Resource\ResourceType;
use EbookReader\Model\Section\Epub3Section;
use EbookReader\Model\TableOfContents\TableOfContentsEntry;

/**
 * @see https://www.w3.org/publishing/epub3/epub-spec.html
 * @see https://wiki.mobileread.com/wiki/EPUB
 */
final class Epub3Driver extends AbstractDriver
{
    /**
     * Attributes whose values may reference an asset or another document.
     *
     * @var list<string>
     */
    private const array REFERENCE_ATTRIBUTES = ['href', 'src', 'data'];

    /**
     * Elements whose references point at assets (images, fonts, stylesheets, media).
     *
     * @var list<string>
     */
    private const array ASSET_TAGS = ['img', 'image', 'link', 'source', 'video', 'audio', 'track', 'object', 'embed'];

    /**
     * Font obfuscation algorithms (not DRM): the first bytes of the font are
     * XORed with a key derived from the book identifier.
     *
     * @see https://wiki.mobileread.com/wiki/EPUB#Font_Obfuscation
     */
    private const string IDPF_OBFUSCATION = 'http://www.idpf.org/2008/embedding';
    private const string ADOBE_OBFUSCATION = 'http://ns.adobe.com/pdf/enc#RC';

    private ?\Dom\Element $packageNode = null;
    private ?string $packageDirectory = null;
    private ?string $encryptionXml = null;
    private bool $rightsManaged = false;

    /** @var list<array{algorithm: string, uri: string}>|null */
    private ?array $encryptionEntries = null;

    /** @var array<string, string>|null ZIP path => obfuscation algorithm */
    private ?array $obfuscatedFonts = null;

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

    private function getPackageNode(): \Dom\Element
    {
        if (null === $this->packageNode || null === $this->packageDirectory) {
            [$packageNode, $packageDirectory] = $this->parsePackage();
            $this->assertNotEncrypted();
            $this->packageNode = $packageNode;
            $this->packageDirectory = $packageDirectory;
        }

        return $this->packageNode;
    }

    /**
     * Rejects DRM-protected books. Font obfuscation is allowed (and undone when
     * the fonts are read); any other encryption algorithm, or the presence of an
     * Adobe/LCP rights file, means the content is encrypted.
     *
     * @throws EncryptedBookException
     */
    private function assertNotEncrypted(): void
    {
        if ($this->rightsManaged) {
            throw new EncryptedBookException('Encrypted EPUB (rights.xml/license.lcpl) is not supported');
        }

        foreach ($this->getEncryptionEntries() as $entry) {
            if (!\in_array($entry['algorithm'], [self::IDPF_OBFUSCATION, self::ADOBE_OBFUSCATION], true)) {
                throw new EncryptedBookException(\sprintf('Encrypted EPUB (%s) is not supported', '' === $entry['algorithm'] ? 'unknown algorithm' : $entry['algorithm']));
            }
        }
    }

    /**
     * @return list<array{algorithm: string, uri: string}>
     */
    private function getEncryptionEntries(): array
    {
        if (null !== $this->encryptionEntries) {
            return $this->encryptionEntries;
        }

        $entries = [];
        if (null !== $this->encryptionXml) {
            $dom = $this->parseXml($this->encryptionXml);
            foreach ($dom->getElementsByTagNameNS('*', 'EncryptedData') as $data) {
                $method = $data->getElementsByTagNameNS('*', 'EncryptionMethod')->item(0);
                $reference = $data->getElementsByTagNameNS('*', 'CipherReference')->item(0);
                $entries[] = [
                    'algorithm' => $method instanceof \Dom\Element ? self::attribute($method, 'Algorithm') : '',
                    'uri' => $reference instanceof \Dom\Element ? self::attribute($reference, 'URI') : '',
                ];
            }
        }

        return $this->encryptionEntries = $entries;
    }

    /**
     * Fonts declared as obfuscated, keyed by their canonical ZIP path. The
     * CipherReference URIs are relative to the container root.
     *
     * @return array<string, string>
     */
    private function getObfuscatedFonts(): array
    {
        if (null !== $this->obfuscatedFonts) {
            return $this->obfuscatedFonts;
        }

        $map = [];
        foreach ($this->getEncryptionEntries() as $entry) {
            if ('' === $entry['uri'] || !\in_array($entry['algorithm'], [self::IDPF_OBFUSCATION, self::ADOBE_OBFUSCATION], true)) {
                continue;
            }
            $map[$this->resolveZipPath($entry['uri'], '')] = $entry['algorithm'];
        }

        return $this->obfuscatedFonts = $map;
    }

    /**
     * The 20-byte IDPF key (SHA-1 of the unique identifier, whitespace removed)
     * or the 16-byte Adobe key (the UUID identifier), or null when unavailable.
     */
    private function deobfuscationKey(string $algorithm): ?string
    {
        if (self::IDPF_OBFUSCATION === $algorithm) {
            $identifier = $this->uniqueIdentifier();
            if (null === $identifier) {
                return null;
            }

            return \sha1(\preg_replace('~[ \t\r\n]~', '', $identifier) ?? $identifier, true);
        }

        return $this->adobeIdentifierKey();
    }

    private function uniqueIdentifier(): ?string
    {
        $packageNode = $this->getPackageNode();
        $id = self::attribute($packageNode, 'unique-identifier');
        $metadataNode = $packageNode->getElementsByTagNameNS('*', 'metadata')->item(0);
        if ('' === $id || !$metadataNode instanceof \Dom\Element) {
            return null;
        }

        foreach ($metadataNode->getElementsByTagNameNS('*', 'identifier') as $node) {
            if ($id === self::attribute($node, 'id')) {
                return $node->textContent;
            }
        }

        return null;
    }

    private function adobeIdentifierKey(): ?string
    {
        $packageNode = $this->getPackageNode();
        $metadataNode = $packageNode->getElementsByTagNameNS('*', 'metadata')->item(0);
        if (!$metadataNode instanceof \Dom\Element) {
            return null;
        }

        foreach ($metadataNode->getElementsByTagNameNS('*', 'identifier') as $node) {
            $text = $node->textContent;
            $isUuid = \str_starts_with($text, 'urn:uuid:');
            foreach ($node->attributes as $attribute) {
                if (\str_ends_with(\strtolower($attribute->localName), 'scheme') && 'uuid' === \strtolower($attribute->value)) {
                    $isUuid = true;
                }
            }
            if (!$isUuid) {
                continue;
            }

            $position = \strrpos($text, ':');
            $uuid = false === $position ? $text : \substr($text, $position + 1);
            $hex = \preg_replace('~[^0-9a-fA-F]~', '', $uuid) ?? '';
            if (32 !== \strlen($hex)) {
                continue;
            }
            $binary = \hex2bin($hex);
            if (false !== $binary) {
                return $binary;
            }
        }

        return null;
    }

    /**
     * XORs the first bytes of the font with the key (encryption and decryption
     * are the same operation).
     */
    private static function deobfuscateFont(string $data, string $key, string $algorithm): string
    {
        $keyLength = \strlen($key);
        if (0 === $keyLength) {
            return $data;
        }

        $cryptLength = self::ADOBE_OBFUSCATION === $algorithm ? 1024 : 1040;
        $length = \min(\strlen($data), $cryptLength);
        for ($i = 0; $i < $length; ++$i) {
            $data[$i] = \chr(\ord($data[$i]) ^ \ord($key[$i % $keyLength]));
        }

        return $data;
    }

    /**
     * Reads an attribute as a string, treating a missing attribute as "".
     */
    private static function attribute(\Dom\Element $element, string $name): string
    {
        return $element->getAttribute($name) ?? '';
    }

    /**
     * @throws ParserException
     */
    private function parseXml(string $content): \Dom\XMLDocument
    {
        try {
            return \Dom\XMLDocument::createFromString($content, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
        } catch (\DOMException $e) {
            throw new ParserException(previous: $e);
        }
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
     * @return array<string, array{mediaType: string, path: string, properties: string}>
     */
    private function manifestMap(\Dom\Element $manifestNode): array
    {
        $manifest = [];
        /** @var \Dom\Element $item */
        foreach ($manifestNode->getElementsByTagNameNS('*', 'item') as $item) {
            $id = self::attribute($item, 'id');
            if ('' === $id) {
                continue;
            }

            $manifest[$id] = [
                'mediaType' => self::attribute($item, 'media-type'),
                'path' => $this->resolveZipPath(self::attribute($item, 'href')),
                'properties' => self::attribute($item, 'properties'),
            ];
        }

        return $manifest;
    }

    /**
     * Builds the book asset map (images, fonts, stylesheets, media) keyed by
     * their canonical ZIP path, rewriting references inside stylesheets.
     *
     * @param array<string, array{mediaType: string, path: string, properties: string}> $manifest
     *
     * @return array{0: array<string, EbookResource>, 1: array<string, list<string>>}
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

            $obfuscation = $this->getObfuscatedFonts()[$item['path']] ?? null;
            if (null !== $obfuscation) {
                $key = $this->deobfuscationKey($obfuscation);
                if (null === $key) {
                    throw new ParserException('Missing font obfuscation key');
                }
                $content = self::deobfuscateFont($content, $key, $obfuscation);
            }

            $path = $item['path'];
            if ('text/css' === $item['mediaType']) {
                [$content, $references] = $this->rewriteCss($content, \dirname($path), $knownPaths);
                $styleReferences[$path] = \array_keys($references);
            }

            $mime = $item['mediaType'];
            $assets[$path] = new EbookResource($path, $content, $mime);
        }

        // Follow @import chains so a section pulls in the whole stylesheet
        // dependency tree, not only the directly linked sheet.
        $resolved = [];
        foreach ($styleReferences as $path => $_) {
            $resolved[$path] = \array_keys(self::transitiveReferences($path, $styleReferences, []));
        }

        return [$assets, $resolved];
    }

    /**
     * The transitive closure of a stylesheet's references (imported sheets and
     * their own assets, recursively).
     *
     * @param array<string, list<string>> $direct
     * @param array<string, true>         $visited
     *
     * @return array<string, true>
     */
    private static function transitiveReferences(string $path, array $direct, array $visited): array
    {
        if (isset($visited[$path])) {
            return [];
        }
        $visited[$path] = true;

        $references = [];
        foreach ($direct[$path] ?? [] as $reference) {
            $references[$reference] = true;
            foreach (self::transitiveReferences($reference, $direct, $visited) as $nested => $_) {
                $references[$nested] = true;
            }
        }

        return $references;
    }

    /**
     * Rewrites relative asset/document references to canonical ZIP paths and
     * returns the set of referenced asset paths.
     *
     * @param array<string, true> $knownPaths
     *
     * @return array<string, true>
     */
    private function rewriteReferences(\Dom\XMLDocument $dom, string $baseDir, array $knownPaths): array
    {
        $referenced = [];
        /** @var \Dom\Element $node */
        foreach ($dom->getElementsByTagNameNS('*', '*') as $node) {
            $isAsset = \in_array(\strtolower($node->localName ?? $node->nodeName), self::ASSET_TAGS, true);

            /** @var \Dom\Attr $attribute */
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

        $css = \preg_replace_callback(
            '~@import\s+(?:url\(\s*)?([\'"]?)([^\'")>\s;]+)\1\s*\)?~i',
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

                return '@import '.$matches[1].$path.$matches[1];
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
     * @return list<Epub3Section>
     */
    #[\Override]
    public function getSections(): array
    {
        $packageNode = $this->getPackageNode();
        $version = (int) self::attribute($packageNode, 'version');

        /** @var \Dom\Element|null $manifestNode */
        $manifestNode = $packageNode->getElementsByTagNameNS('*', 'manifest')->item(0);
        if (!$manifestNode instanceof \Dom\Element) {
            throw new ParserException();
        }
        $manifest = $this->manifestMap($manifestNode);

        /** @var \Dom\Element|null $spineNode */
        $spineNode = $packageNode->getElementsByTagNameNS('*', 'spine')->item(0);
        if (!$spineNode instanceof \Dom\Element) {
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
            /** @var \Dom\Element $spinItemNode */
            foreach ($spineNode->getElementsByTagNameNS('*', 'itemref') as $spinItemNode) {
                $id = self::attribute($spinItemNode, 'idref');
                if (!isset($manifest[$id])) {
                    continue;
                }
                $item = $manifest[$id];
                $documentPath = $item['path'];

                $content = $zip->getFromName($documentPath);
                if (false === $content) {
                    continue;
                }

                $domFile = $this->parseXml($content);

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

                $title = $domFile->getElementsByTagNameNS('*', 'title')->item(0)?->textContent;
                $text = $domFile->saveXml($domFile->documentElement);
                if (false === $text) {
                    throw new ParserException();
                }

                $data[] = new Epub3Section($documentPath, HtmlSanitizer::sanitize($text), $title, $isNavigation, $resources);
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
        /** @var \Dom\Element|null $manifestNode */
        $manifestNode = $packageNode->getElementsByTagNameNS('*', 'manifest')->item(0);
        if (!$manifestNode instanceof \Dom\Element) {
            return null;
        }
        $manifest = $this->manifestMap($manifestNode);

        $zip = new \ZipArchive();
        if (true !== $zip->open($this->getFile(), \ZipArchive::RDONLY)) {
            throw new ParserException();
        }

        try {
            foreach ($this->coverHrefs($packageNode, $manifest) as $path) {
                $image = $this->findCoverImage($path, $manifest, $zip);
                if (null !== $image) {
                    return new Cover($image['content'], $image['mime']);
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    /**
     * Cover candidates in priority order: the `<meta name="cover">` item, an
     * EPUB3 `cover-image`, a cover-named item, then the guide's cover reference.
     *
     * @param array<string, array{mediaType: string, path: string, properties: string}> $manifest
     *
     * @return list<string> canonical ZIP paths
     */
    private function coverHrefs(\Dom\Element $packageNode, array $manifest): array
    {
        $paths = [];

        /** @var \Dom\Element|null $metadataNode */
        $metadataNode = $packageNode->getElementsByTagNameNS('*', 'metadata')->item(0);
        if ($metadataNode instanceof \Dom\Element) {
            foreach ($metadataNode->getElementsByTagNameNS('*', 'meta') as $metaNode) {
                if ('cover' === self::attribute($metaNode, 'name')) {
                    $coverId = self::attribute($metaNode, 'content');
                    if (isset($manifest[$coverId])) {
                        $paths[] = $manifest[$coverId]['path'];
                    }
                    break;
                }
            }
        }

        foreach ($manifest as $item) {
            $properties = \preg_split('/\s+/', $item['properties'], -1, \PREG_SPLIT_NO_EMPTY);
            if ($properties && \in_array('cover-image', $properties, true) && \str_starts_with($item['mediaType'], 'image/')) {
                $paths[] = $item['path'];
            }
        }

        foreach ($manifest as $id => $item) {
            if (\str_starts_with($item['mediaType'], 'image/') && \in_array(\strtolower($id), ['cover', 'cover-image', 'cover-img'], true)) {
                $paths[] = $item['path'];
            }
        }

        /** @var \Dom\Element|null $guideNode */
        $guideNode = $packageNode->getElementsByTagNameNS('*', 'guide')->item(0);
        if ($guideNode instanceof \Dom\Element) {
            foreach ($guideNode->getElementsByTagNameNS('*', 'reference') as $referenceNode) {
                if (\str_contains(\strtolower(self::attribute($referenceNode, 'type')), 'cover')) {
                    $href = self::attribute($referenceNode, 'href');
                    if ('' !== $href) {
                        $paths[] = $this->resolveZipPath($href);
                    }
                }
            }
        }

        return \array_values(\array_unique($paths));
    }

    /**
     * The image behind a cover candidate: the item itself, or the image inside a
     * cover XHTML page.
     *
     * @param array<string, array{mediaType: string, path: string, properties: string}> $manifest
     *
     * @return array{content: string, mime: string}|null
     */
    private function findCoverImage(string $path, array $manifest, \ZipArchive $zip): ?array
    {
        $mediaTypeByPath = [];
        foreach ($manifest as $item) {
            $mediaTypeByPath[$item['path']] = $item['mediaType'];
        }

        $content = $zip->getFromName($path);
        if (false === $content) {
            return null;
        }

        $mediaType = $mediaTypeByPath[$path] ?? '';
        if (\str_starts_with($mediaType, 'image/')) {
            return ['content' => $content, 'mime' => $mediaType];
        }

        try {
            $dom = $this->parseXml($content);
        } catch (ParserException) {
            return null;
        }

        /** @var \Dom\Element $node */
        foreach ($dom->getElementsByTagNameNS('*', '*') as $node) {
            if (!\in_array(\strtolower($node->localName ?? $node->nodeName), ['img', 'image'], true)) {
                continue;
            }

            /** @var \Dom\Attr $attribute */
            foreach ($node->attributes as $attribute) {
                if (!\in_array(\strtolower($attribute->localName), ['src', 'href'], true)) {
                    continue;
                }
                $resolved = $this->canonicalReference($attribute->value, \dirname($path));
                if (null === $resolved) {
                    continue;
                }
                [$imagePath] = $resolved;
                $imageMime = $mediaTypeByPath[$imagePath] ?? '';
                $image = \str_starts_with($imageMime, 'image/') ? $zip->getFromName($imagePath) : false;
                if (false !== $image) {
                    return ['content' => $image, 'mime' => $imageMime];
                }
            }
        }

        return null;
    }

    /**
     * @return list<TableOfContentsEntry>
     */
    #[\Override]
    public function getTableOfContents(): array
    {
        $packageNode = $this->getPackageNode();
        /** @var \Dom\Element|null $manifestNode */
        $manifestNode = $packageNode->getElementsByTagNameNS('*', 'manifest')->item(0);
        if (!$manifestNode instanceof \Dom\Element) {
            return [];
        }

        $navHref = null;
        $ncxHref = null;
        /** @var \Dom\Element $item */
        foreach ($manifestNode->getElementsByTagNameNS('*', 'item') as $item) {
            if (null === $navHref) {
                $properties = \preg_split('/\s+/', self::attribute($item, 'properties'), -1, \PREG_SPLIT_NO_EMPTY);
                if ($properties && \in_array('nav', $properties, true)) {
                    $navHref = self::attribute($item, 'href');
                }
            }
            if (null === $ncxHref && 'application/x-dtbncx+xml' === self::attribute($item, 'media-type')) {
                $ncxHref = self::attribute($item, 'href');
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
                try {
                    $dom = $this->parseXml($content);
                } catch (ParserException) {
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
     * @return list<TableOfContentsEntry>
     */
    private function parseNav(\Dom\XMLDocument $dom, string $baseDir, array $spineMap): array
    {
        $nav = null;
        $fallback = null;
        /** @var \Dom\Element $navNode */
        foreach ($dom->getElementsByTagNameNS('*', 'nav') as $navNode) {
            $fallback ??= $navNode;
            $type = self::attribute($navNode, 'epub:type');
            if ('' === $type) {
                $type = $navNode->getAttributeNS('http://www.idpf.org/2007/ops', 'type') ?? '';
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

        /** @var \Dom\Element|null $ol */
        $ol = null;
        foreach ($nav->childNodes as $child) {
            if ($child instanceof \Dom\Element && 'ol' === $child->localName) {
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
     * @param list<TableOfContentsEntry> $entries
     * @param array<string, int>         $spineMap
     */
    private function walkOl(\Dom\Element $ol, int $level, array &$entries, string $baseDir, array $spineMap): void
    {
        foreach ($ol->childNodes as $li) {
            if (!$li instanceof \Dom\Element || 'li' !== $li->localName) {
                continue;
            }

            foreach ($li->childNodes as $child) {
                if ($child instanceof \Dom\Element && 'a' === $child->localName) {
                    $title = \mb_trim($child->textContent);
                    if ('' !== $title) {
                        [$sectionIndex, $anchor] = $this->resolveTarget(self::attribute($child, 'href'), $baseDir, $spineMap);
                        $entries[] = new TableOfContentsEntry($title, $level, $sectionIndex, $anchor);
                    }
                    break;
                }
            }

            foreach ($li->childNodes as $child) {
                if ($child instanceof \Dom\Element && 'ol' === $child->localName) {
                    $this->walkOl($child, $level + 1, $entries, $baseDir, $spineMap);
                }
            }
        }
    }

    /**
     * @param array<string, int> $spineMap
     *
     * @return list<TableOfContentsEntry>
     */
    private function parseNcx(\Dom\XMLDocument $dom, string $baseDir, array $spineMap): array
    {
        /** @var \Dom\Element|null $navMap */
        $navMap = $dom->getElementsByTagNameNS('*', 'navMap')->item(0);
        if (!$navMap instanceof \Dom\Element) {
            return [];
        }

        $entries = [];
        $this->walkNavPoints($navMap, 1, $entries, $baseDir, $spineMap);

        return $entries;
    }

    /**
     * @param list<TableOfContentsEntry> $entries
     * @param array<string, int>         $spineMap
     */
    private function walkNavPoints(\Dom\Element $parent, int $level, array &$entries, string $baseDir, array $spineMap): void
    {
        foreach ($parent->childNodes as $navPoint) {
            if (!$navPoint instanceof \Dom\Element || 'navPoint' !== $navPoint->localName) {
                continue;
            }

            $text = $navPoint->getElementsByTagNameNS('*', 'text')->item(0)?->textContent;
            $title = null === $text ? '' : \mb_trim($text);
            if ('' !== $title) {
                $src = $navPoint->getElementsByTagNameNS('*', 'content')->item(0)?->getAttribute('src');
                [$sectionIndex, $anchor] = null === $src ? [null, null] : $this->resolveTarget($src, $baseDir, $spineMap);
                $entries[] = new TableOfContentsEntry($title, $level, $sectionIndex, $anchor);
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
        /** @var \Dom\Element|null $manifestNode */
        $manifestNode = $packageNode->getElementsByTagNameNS('*', 'manifest')->item(0);
        /** @var \Dom\Element|null $spineNode */
        $spineNode = $packageNode->getElementsByTagNameNS('*', 'spine')->item(0);
        if (!$manifestNode instanceof \Dom\Element || !$spineNode instanceof \Dom\Element) {
            return [];
        }

        $hrefs = [];
        /** @var \Dom\Element $item */
        foreach ($manifestNode->getElementsByTagNameNS('*', 'item') as $item) {
            $hrefs[self::attribute($item, 'id')] = self::attribute($item, 'href');
        }

        $map = [];
        $index = 0;
        /** @var \Dom\Element $itemref */
        foreach ($spineNode->getElementsByTagNameNS('*', 'itemref') as $itemref) {
            $id = self::attribute($itemref, 'idref');
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
    public function getMeta(): Metadata
    {
        $packageNode = $this->getPackageNode();

        $version = (int) self::attribute($packageNode, 'version');
        if (!\in_array($version, [2, 3], true)) {
            throw new UnsupportedFormatException(\sprintf('Unsupported EPUB version: %d', $version));
        }
        if (!$packageNode->getElementsByTagNameNS('*', 'metadata')->item(0) instanceof \Dom\Element) {
            throw new ParserException();
        }

        return OpfMetadataReader::metadata($packageNode);
    }

    /**
     * @return array{0: \Dom\Element, 1: string}
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

            $domContainer = $this->parseXml($container);

            $node = $domContainer->getElementsByTagNameNS('*', 'rootfile')->item(0);
            if (!$node instanceof \Dom\Element) {
                throw new ParserException();
            }

            /** @var \Dom\Attr|null $packageName */
            $packageName = $node->attributes->getNamedItem('full-path');
            if (!$packageName) {
                throw new ParserException();
            }

            $package = $zip->getFromName($packageName->value);
            if (false === $package) {
                throw new ParserException();
            }

            $encryption = $zip->getFromName('META-INF/encryption.xml');
            $this->encryptionXml = false === $encryption ? null : $encryption;
            $this->rightsManaged = false !== $zip->getFromName('META-INF/rights.xml')
                || false !== $zip->getFromName('META-INF/license.lcpl');
        } finally {
            $zip->close();
        }

        $domPackage = $this->parseXml($package);

        /** @var \Dom\Element|null $packageNode */
        $packageNode = $domPackage->getElementsByTagNameNS('*', 'package')->item(0);
        if (!$packageNode) {
            throw new ParserException();
        }

        return [$packageNode, \dirname($packageName->value)];
    }
}
