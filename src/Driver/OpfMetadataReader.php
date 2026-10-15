<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Model\Meta\Metadata;
use EbookReader\Model\Meta\PublishDate;

/**
 * Reads book metadata from an OPF (Open Packaging Format) document.
 *
 * This is the single parser for the `<metadata>` section shared by the EPUB
 * package document and the `metadata.opf` sidecar that ZIP containers such as
 * Calibre's HTMLZ and TXTZ carry. EPUB-only parts (manifest, spine, nav/ncx)
 * stay in {@see Epub3Driver}.
 */
final class OpfMetadataReader
{
    /**
     * @param \Dom\Element $packageNode the `<package>` element
     */
    public static function metadata(\Dom\Element $packageNode): Metadata
    {
        /** @var \Dom\Element|null $metadata */
        $metadata = $packageNode->getElementsByTagNameNS('*', 'metadata')->item(0);
        [$series, $seriesIndex] = $metadata ? self::series($metadata) : [null, null];

        return new Metadata(
            title: $metadata ? self::title($metadata) : '',
            authors: $metadata ? self::creators($metadata) : [],
            publisher: $metadata ? self::firstText($metadata, 'publisher') : null,
            isbn: $metadata ? self::isbn($metadata) : null,
            description: $metadata ? self::firstText($metadata, 'description') : null,
            language: $metadata ? self::firstText($metadata, 'language') : null,
            subjects: $metadata ? self::texts($metadata, 'subject') : [],
            license: $metadata ? self::firstText($metadata, 'rights') : null,
            publishDate: $metadata ? self::date($metadata) : new PublishDate(),
            series: $series,
            seriesIndex: $seriesIndex,
        );
    }

    public static function fromXml(string $xml): Metadata
    {
        $dom = self::parse($xml);
        if (null === $dom) {
            return new Metadata();
        }

        return self::metadata($dom->documentElement);
    }

    /**
     * The cover entry declared by the document, or null when there is none.
     */
    public static function coverHref(string $xml): ?string
    {
        $dom = self::parse($xml);
        if (null === $dom) {
            return null;
        }

        return self::cover($dom->documentElement);
    }

    private static function parse(string $xml): ?\Dom\XMLDocument
    {
        try {
            return \Dom\XMLDocument::createFromString($xml, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
        } catch (\DOMException) {
            return null;
        }
    }

    /**
     * All dc:title values joined, as OPF3 titles may be refined (main/subtitle).
     */
    private static function title(\Dom\Element $metadata): string
    {
        $titles = [];
        foreach ($metadata->getElementsByTagNameNS('*', 'title') as $node) {
            $value = \mb_trim($node->textContent);
            if ('' !== $value) {
                $titles[] = $value;
            }
        }

        return \implode(', ', $titles);
    }

    /**
     * Creators with opf:role="aut"; when none is marked, all creators.
     *
     * @return list<string>
     */
    private static function creators(\Dom\Element $metadata): array
    {
        $authors = $all = [];
        foreach ($metadata->getElementsByTagNameNS('*', 'creator') as $node) {
            $value = \mb_trim($node->textContent);
            if ('' === $value) {
                continue;
            }
            $all[] = $value;
            if ('aut' === self::attr($node, 'opf:role')) {
                $authors[] = $value;
            }
        }

        return $authors ?: $all;
    }

    /**
     * EPUB3 declares it as `urn:isbn:`, EPUB2/Calibre as `opf:scheme="ISBN"`.
     */
    private static function isbn(\Dom\Element $metadata): ?string
    {
        $identifiers = $metadata->getElementsByTagNameNS('*', 'identifier');

        foreach ($identifiers as $node) {
            $value = \mb_trim($node->textContent);
            if (\str_starts_with($value, 'urn:isbn:')) {
                return \substr($value, 9);
            }
        }
        foreach ($identifiers as $node) {
            if ('ISBN' === self::attr($node, 'opf:scheme')) {
                return \mb_trim($node->textContent);
            }
        }

        return null;
    }

    /**
     * dc:date per W3CDTF/ISO 8601: YYYY, YYYY-MM or a full date/time.
     */
    private static function date(\Dom\Element $metadata): PublishDate
    {
        $value = self::firstText($metadata, 'date');
        if (null === $value) {
            return new PublishDate();
        }

        if (1 === \preg_match('/^(\d{4})$/', $value, $matches)) {
            return new PublishDate((int) $matches[1]);
        }
        if (1 === \preg_match('/^(\d{4})-(\d{1,2})$/', $value, $matches)) {
            return new PublishDate((int) $matches[1], (int) $matches[2]);
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return new PublishDate();
        }

        return new PublishDate((int) $date->format('Y'), (int) $date->format('m'), (int) $date->format('d'));
    }

    /**
     * Series name and position: Calibre's `calibre:series` metas, or an EPUB3
     * `belongs-to-collection` refined with `collection-type` = "series".
     *
     * @return array{0: ?string, 1: ?float}
     */
    private static function series(\Dom\Element $metadata): array
    {
        foreach ($metadata->getElementsByTagNameNS('*', 'meta') as $node) {
            if ('belongs-to-collection' !== self::attr($node, 'property')) {
                continue;
            }

            $id = self::attr($node, 'id') ?? '';
            if ('' === $id || 'series' !== self::refinedValue($metadata, $id, 'collection-type')) {
                continue;
            }

            $name = \mb_trim($node->textContent);
            if ('' === $name) {
                continue;
            }

            $position = self::refinedValue($metadata, $id, 'group-position');

            return [$name, null !== $position && \is_numeric($position) ? (float) $position : null];
        }

        foreach ($metadata->getElementsByTagNameNS('*', 'meta') as $node) {
            if ('calibre:series' === self::attr($node, 'name')) {
                $name = self::metaValue($node);
                if ('' !== $name) {
                    return [$name, self::calibreSeriesIndex($metadata)];
                }
                break;
            }
        }

        return [null, null];
    }

    private static function calibreSeriesIndex(\Dom\Element $metadata): ?float
    {
        foreach ($metadata->getElementsByTagNameNS('*', 'meta') as $node) {
            if ('calibre:series_index' === self::attr($node, 'name')) {
                $value = self::metaValue($node);

                return '' !== $value && \is_numeric($value) ? (float) $value : null;
            }
        }

        return null;
    }

    /**
     * The value of a `<meta property="...">` that refines the #id element.
     */
    private static function refinedValue(\Dom\Element $metadata, string $id, string $property): ?string
    {
        $refines = '#'.\ltrim($id, '#');
        foreach ($metadata->getElementsByTagNameNS('*', 'meta') as $node) {
            if ($property === self::attr($node, 'property') && $refines === self::attr($node, 'refines')) {
                $value = \mb_trim($node->textContent);

                return '' === $value ? null : $value;
            }
        }

        return null;
    }

    /**
     * A meta's `content` attribute, falling back to its text.
     */
    private static function metaValue(\Dom\Element $node): string
    {
        $content = self::attr($node, 'content') ?? '';
        if ('' !== \mb_trim($content)) {
            return \mb_trim($content);
        }

        return \mb_trim($node->textContent);
    }

    /**
     * The declared cover entry: the `<meta name="cover">` referenced manifest
     * item, or a `<cover-relpath-from-base>` element (a Calibre extension).
     * Whether it exists is up to the container, not the metadata.
     */
    private static function cover(\Dom\Element $packageNode): ?string
    {
        $coverId = null;
        foreach ($packageNode->getElementsByTagNameNS('*', 'meta') as $node) {
            if ('cover' === self::attr($node, 'name')) {
                $coverId = self::attr($node, 'content');
                break;
            }
        }
        if (null !== $coverId) {
            foreach ($packageNode->getElementsByTagNameNS('*', 'item') as $node) {
                $href = self::attr($node, 'href');
                if (null !== $href && $coverId === self::attr($node, 'id')) {
                    return $href;
                }
            }
        }

        $node = $packageNode->getElementsByTagNameNS('*', 'cover-relpath-from-base')->item(0);
        $href = null === $node ? '' : \mb_trim($node->textContent);

        return '' === $href ? null : $href;
    }

    private static function firstText(\Dom\Element $node, string $localName): ?string
    {
        $text = $node->getElementsByTagNameNS('*', $localName)->item(0)?->textContent;
        $text = null === $text ? null : \mb_trim($text);

        return null === $text || '' === $text ? null : $text;
    }

    /**
     * @return list<string>
     */
    private static function texts(\Dom\Element $node, string $localName): array
    {
        $values = [];
        foreach ($node->getElementsByTagNameNS('*', $localName) as $item) {
            $text = \mb_trim($item->textContent);
            if ('' !== $text) {
                $values[] = $text;
            }
        }

        return $values;
    }

    private static function attr(\Dom\Element $element, string $name): ?string
    {
        return $element->getAttribute($name);
    }
}
