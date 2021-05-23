<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Exception\ParserException;
use EbookReader\Meta\Fb2Meta;

/**
 * @see https://wiki.mobileread.com/wiki/FB2
 * @see http://www.fictionbook.org/index.php/XML_%D1%81%D1%85%D0%B5%D0%BC%D0%B0_FictionBook2.2
 * @see http://www.tinlib.ru/kompyutery_i_internet/sozdanie_yelektronnyh_knig_v_formate_fictionbook_2_1_prakticheskoe_rukovodstvo/p5.php
 */
class Fb2Driver extends AbstractDriver
{
    private ?\DOMElement $fictionBookDescription = null;

    public function isValid(): bool
    {
        try {
            $this->getFictionBookDescription();
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    public function getCover(): ?string
    {
        // todo
        throw new \RuntimeException('Not implemented');
    }

    public function getMeta(): Fb2Meta
    {
        $descriptionNode = $this->getFictionBookDescription();

        /** @var \DOMElement $titleInfoNode */
        $titleInfoNode = $descriptionNode->getElementsByTagName('title-info')->item(0);

        $title = $this->makeTitle($titleInfoNode);
        $author = $this->makeAuthor($titleInfoNode);

        return new Fb2Meta(
            $title,
            $author
        );
    }

    protected function makeAuthor(\DOMElement $titleInfoNode): ?string
    {
        // default minOccurs = 1. so author node is required
        /*
          <xs:element name="author" type="authorType" maxOccurs="unbounded">
           <xs:annotation>
            <xs:documentation>Author(s) of this particular document</xs:documentation>
           </xs:annotation>
          </xs:element>

<xs:complexType name="authorType">
  <xs:annotation>
   <xs:documentation>Information about a single author</xs:documentation>
  </xs:annotation>
  <xs:choice>
   <xs:sequence>
    <xs:element name="first-name" type="textFieldType"/>
    <xs:element name="middle-name" type="textFieldType" minOccurs="0"/>
    <xs:element name="last-name" type="textFieldType"/>
    <xs:element name="nickname" type="textFieldType" minOccurs="0"/>
    <xs:element name="home-page" type="xs:string" minOccurs="0" maxOccurs="unbounded"/>
    <xs:element name="email" type="xs:string" minOccurs="0" maxOccurs="unbounded"/>
    <xs:element name="id" type="xs:token" minOccurs="0"/>
   </xs:sequence>
   <xs:sequence>
    <xs:element name="nickname" type="textFieldType"/>
    <xs:element name="home-page" type="xs:string" minOccurs="0" maxOccurs="unbounded"/>
    <xs:element name="email" type="xs:string" minOccurs="0" maxOccurs="unbounded"/>
    <xs:element name="id" type="xs:token" minOccurs="0"/>
   </xs:sequence>
  </xs:choice>
 </xs:complexType>
         */

        // http://www.tinlib.ru/kompyutery_i_internet/sozdanie_yelektronnyh_knig_v_formate_fictionbook_2_1_prakticheskoe_rukovodstvo/p5.php#elm_author
        $authorNodeList = $titleInfoNode->getElementsByTagName('author');

        $authors = [];
        /** @var \DOMElement $authorNode */
        foreach ($authorNodeList as $authorNode) {
            $firstNameNode = $authorNode->getElementsByTagName('first-name');
            $middleNameNode = $authorNode->getElementsByTagName('middle-name');
            $lastNameNode = $authorNode->getElementsByTagName('last-name');
            $nicknameNode = $authorNode->getElementsByTagName('nickname');

            if ($firstNameNode->length && $middleNameNode->length && $lastNameNode->length) {
                $authors[] = $firstNameNode->item(0)->nodeValue.' '.$middleNameNode->item(0)->nodeValue.' '.$lastNameNode->item(0)->nodeValue;
            } elseif ($firstNameNode->length && $lastNameNode->length) {
                $authors[] = $firstNameNode->item(0)->nodeValue.' '.$lastNameNode->item(0)->nodeValue;
            } else {
                $authors[] = $nicknameNode->item(0)->nodeValue;
            }
        }

        return \implode(', ', $authors);
    }

    protected function makeTitle(\DOMElement $titleInfoNode): string
    {
        return $titleInfoNode->getElementsByTagName('book-title')->item(0)->nodeValue;
    }

    protected function getFictionBookDescription(): \DOMElement
    {
        if ($this->fictionBookDescription) {
            return $this->fictionBookDescription;
        }

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
                $reader->close();
                if (!$descriptionNode) {
                    throw new ParserException();
                }

                $this->fictionBookDescription = $descriptionNode;

                return $this->fictionBookDescription;
            }
        }

        $reader->close();
        throw new ParserException();
    }
}
