<?php

declare(strict_types=1);

namespace EbookReader\Driver\Xml;

/**
 * DOM element helpers for the namespace-agnostic reading the drivers do
 * (OPF/EPUB documents declare elements in various namespaces, so lookups go by
 * local name).
 */
final class Element
{
    private function __construct()
    {
    }

    /**
     * The value of an attribute, or "" when it is absent.
     */
    public static function attribute(\Dom\Element $element, string $name): string
    {
        return $element->getAttribute($name) ?? '';
    }

    /**
     * The first descendant element with the given local name.
     */
    public static function first(\Dom\XMLDocument|\Dom\Element $scope, string $localName): ?\Dom\Element
    {
        $item = $scope->getElementsByTagNameNS('*', $localName)->item(0);

        return $item instanceof \Dom\Element ? $item : null;
    }
}
