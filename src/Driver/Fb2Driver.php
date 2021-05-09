<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Exception\ParserException;
use EbookReader\Meta\Fb2Meta;

/**
 * @see https://wiki.mobileread.com/wiki/FB2
 * @see http://www.fictionbook.org/index.php/XML_%D1%81%D1%85%D0%B5%D0%BC%D0%B0_FictionBook2.2
 */
class Fb2Driver extends AbstractDriver
{
    public function isValid(): bool
    {
        try {
            $this->getFictionBookDescription();
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    public function getMeta(): Fb2Meta
    {
        $descriptionNode = $this->getFictionBookDescription();

        /** @var \DOMElement $titleInfoNode */
        $titleInfoNode = $descriptionNode->getElementsByTagName('title-info')->item(0);
        /** @var \DOMElement $titleInfoBookTitleNode */
        $titleInfoBookTitleNode = $titleInfoNode->getElementsByTagName('book-title')->item(0);

        return new Fb2Meta(
            $titleInfoBookTitleNode->nodeValue
        );
    }

    protected function getFictionBookDescription(): \DOMElement
    {
        $zip = new \ZipArchive();
        $res = $zip->open($this->getFile(), \ZipArchive::RDONLY);
        if (true === $res) {
            $content = $zip->getFromIndex(0); // read first file
            $zip->close();
        } else {
            $content = \file_get_contents($this->getFile());
        }
        if (false === $content) {
            throw new ParserException();
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        if (false === $dom->loadXML($content, \LIBXML_NOENT | \LIBXML_NOERROR)) { // throws \ValueError for php 8
            throw new ParserException();
        }

        /** @var \DOMElement|null $fictionBookNode */
        $fictionBookNode = $dom->getElementsByTagName('FictionBook')->item(0);
        if (!$fictionBookNode) {
            throw new ParserException();
        }

        /** @var \DOMElement|null $descriptionNode */
        $descriptionNode = $fictionBookNode->getElementsByTagName('description')->item(0);
        if (!$descriptionNode) {
            throw new ParserException();
        }

        return $descriptionNode;
    }
}
