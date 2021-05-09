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
            $fb2File = $zip->getNameIndex(0); // get first file in archive
            $zip->close();
            if (false === $fb2File) {
                throw new ParserException();
            }

            $file = 'zip://'.$this->getFile().'#'.$fb2File;
        } else {
            $file = $this->getFile();
        }

        /** @var \XMLReader|false $reader */
        $reader = @\XMLReader::open($file, 'UTF-8', \LIBXML_NOENT | \LIBXML_NOERROR); // throws \ValueError for php 8
        if (!$reader) {
            throw new ParserException();
        }

        while ($reader->read()) {
            if (\XmlReader::ELEMENT === $reader->nodeType && 'description' === $reader->name) { // first description element
                /** @var \DOMElement|false $descriptionNode */
                $descriptionNode = $reader->expand();
                if (!$descriptionNode) {
                    throw new ParserException();
                }

                return $descriptionNode;
            }
        }

        throw new ParserException();
    }
}
