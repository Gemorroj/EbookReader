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

    public function getData(): array
    {
        // todo
        throw new \RuntimeException('Not implemented');
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

        /** @var \DOMElement|null $publishNodeInfo */
        $publishNodeInfo = $descriptionNode->getElementsByTagName('publish-info')->item(0);

        $title = $this->makeTitle($titleInfoNode);
        $author = $this->makeAuthor($titleInfoNode);
        $publisher = $publishNodeInfo ? $this->makePublisher($publishNodeInfo) : null;
        $isbn = $publishNodeInfo ? $this->makeIsbn($publishNodeInfo) : null;
        $description = $this->makeDescription($titleInfoNode);
        $language = $this->makeLanguage($titleInfoNode);
        $license = $this->makeLicense();
        $publishYear = $this->makePublishYear($publishNodeInfo);

        return new Fb2Meta(
            $title,
            $author,
            $publisher,
            $isbn,
            $description,
            $language,
            $license,
            $publishYear,
            null,
            null,
        );
    }

    protected function makePublishYear(\DOMElement $publishNodeInfo): ?int
    {
        /*
          <xs:element name="year" type="xs:gYear" minOccurs="0">
           <xs:annotation>
            <xs:documentation>Year of the original (paper) publication</xs:documentation>
           </xs:annotation>
          </xs:element>
         */

        /** @var \DOMElement|null $yearNode */
        $yearNode = $publishNodeInfo->getElementsByTagName('year')->item(0);
        if ($yearNode) {
            return (int) $yearNode->nodeValue;
        }

        return null;
    }

    protected function makeLicense(): ?string
    {
        return null;
    }

    protected function makeLanguage(\DOMElement $titleInfoNode): ?string
    {
        /*
   <xs:element name="lang" type="xs:string">
    <xs:annotation>
     <xs:documentation>Book's language</xs:documentation>
    </xs:annotation>
   </xs:element>
         */

        /** @var \DOMElement|null $langNode */
        $langNode = $titleInfoNode->getElementsByTagName('lang')->item(0);
        if ($langNode) {
            return $langNode->nodeValue;
        }

        return null;
    }

    protected function makeDescription(\DOMElement $titleInfoNode): ?string
    {
        /*
   <xs:element name="annotation" type="annotationType" minOccurs="0">
    <xs:annotation>
     <xs:documentation>Annotation for this book</xs:documentation>
    </xs:annotation>
   </xs:element>

  <xs:complexType name="annotationType">
  <xs:annotation>
   <xs:documentation>A cut-down version of <section> used in annotations</xs:documentation>
  </xs:annotation>
  <xs:choice minOccurs="0" maxOccurs="unbounded">
   <xs:element name="p" type="pType"/>
   <xs:element name="poem" type="poemType"/>
   <xs:element name="cite" type="citeType"/>
   <xs:element name="subtitle" type="pType"/>
   <xs:element name="table" type="tableType"/>
   <xs:element name="empty-line"/>
  </xs:choice>
  <xs:attribute name="id" type="xs:ID" use="optional"/>
  <xs:attribute ref="xml:lang"/>
 </xs:complexType>
         */

        /** @var \DOMElement|null $annotationNode */
        $annotationNode = $titleInfoNode->getElementsByTagName('annotation')->item(0);
        if ($annotationNode) {
            $text = [];
            foreach ($annotationNode->childNodes as $childNode) {
                $text[] = $annotationNode->ownerDocument->saveHTML($childNode);
            }

            return \trim(\implode('', $text));
        }

        return null;
    }

    protected function makeIsbn(\DOMElement $publishNodeInfo): ?string
    {
        /*
            <xs:element name="isbn" type="textFieldType" minOccurs="0"/>
         */
        /** @var \DOMElement|null $isbnNode */
        $isbnNode = $publishNodeInfo->getElementsByTagName('isbn')->item(0);
        if ($isbnNode) {
            return $isbnNode->nodeValue;
        }

        return null;
    }

    protected function makePublisher(\DOMElement $publishNodeInfo): ?string
    {
        /*
          <xs:element name="publisher" type="textFieldType" minOccurs="0">
           <xs:annotation>
            <xs:documentation>Original (paper) book publisher</xs:documentation>
           </xs:annotation>
          </xs:element>
         */
        /** @var \DOMElement|null $publisherNode */
        $publisherNode = $publishNodeInfo->getElementsByTagName('publisher')->item(0);
        if ($publisherNode) {
            return $publisherNode->nodeValue;
        }

        return null;
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
        $reader = @\XMLReader::open($file, 'UTF-8', \LIBXML_NOENT | \LIBXML_NOERROR | \LIBXML_NOBLANKS); // throws \ValueError for php 8
        if (!$reader) {
            throw new ParserException();
        }

        while ($reader->read()) {
            if (\XmlReader::ELEMENT === $reader->nodeType && 'description' === $reader->name) { // first description element
                /** @var \DOMElement|false $descriptionNode */
                $descriptionNode = $reader->expand(new \DOMDocument('1.0', 'UTF-8'));
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
