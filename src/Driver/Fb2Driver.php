<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Data\Fb2Data;
use EbookReader\Data\Fb2DataEpigraph;
use EbookReader\EbookCoverInterface;
use EbookReader\Exception\ParserException;
use EbookReader\Meta\Fb2Meta;
use EbookReader\Resource\Style;
use EbookReader\Resource\StyleType;

/**
 * @see https://wiki.mobileread.com/wiki/FB2
 * @see http://www.fictionbook.org/index.php/XML_%D1%81%D1%85%D0%B5%D0%BC%D0%B0_FictionBook2.2
 * @see http://www.tinlib.ru/kompyutery_i_internet/sozdanie_yelektronnyh_knig_v_formate_fictionbook_2_1_prakticheskoe_rukovodstvo/p5.php
 */
final class Fb2Driver extends AbstractDriver
{
    private ?string $internalFile = null;
    private ?\DOMElement $fictionBookDescription = null;
    private ?\DOMElement $fictionBookStylesheet = null;
    private ?\DOMElement $fictionBookBody = null;

    public function isValid(): bool
    {
        try {
            $this->getFictionBookDescription();
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * @return Fb2Data[]
     */
    public function getData(): array
    {
        $data = [];
        $stylesheetNode = $this->getFictionBookStylesheet();
        $bodyNode = $this->getFictionBookBody();

        $styles = [];
        if ($stylesheetNode) {
            $styles[] = new Style($stylesheetNode->nodeValue, StyleType::CSS);
        }

        /** @var \DOMElement $sectionNode */
        foreach ($bodyNode->getElementsByTagName('section') as $sectionNode) {
            $title = $sectionNode->getElementsByTagName('title')->item(0)?->textContent;
            $text = $this->makeText($sectionNode);

            $annotation = null;
            /** @var \DOMElement|null $annotationNode */
            $annotationNode = $sectionNode->getElementsByTagName('annotation')->item(0);
            if ($annotationNode) {
                $annotation = $this->makeText($annotationNode);
            }

            $epigraphs = [];
            /** @var \DOMElement $epigraphNode */
            foreach ($sectionNode->getElementsByTagName('epigraph') as $epigraphNode) {
                $epigraphText = $this->makeText($epigraphNode);
                if ($epigraphText) {
                    $authors = [];
                    foreach ($epigraphNode->getElementsByTagName('text-author') as $textAuthorNode) {
                        $authors[] = $this->makeAuthorText($textAuthorNode);
                    }
                    $epigraphs[] = new Fb2DataEpigraph($epigraphText, $authors);
                }
            }

            $data[] = new Fb2Data($text, $title, $annotation, $epigraphs, $styles);
        }

        return $data;
    }

    private function makeText(\DOMElement $node, bool $rowFrame = false): string
    {
        $text = [];
        /** @var \DOMElement|\DOMNode|\DOMNameSpaceNode $childNode */
        foreach ($node->childNodes as $childNode) {
            if (!($childNode instanceof \DOMElement)) {
                if ($rowFrame) {
                    $text[] = '<p>'.$childNode->nodeValue.'</p>';
                } else {
                    $text[] = $childNode->nodeValue."\n";
                }
                continue;
            }

            if (\in_array($childNode->tagName, ['p', 'table', 'strong', 'emphasis', 'style', 'strikethrough', 'sub', 'sup', 'code'], true)) {
                $text[] = $node->ownerDocument->saveHTML($childNode);
            }
            if ('cite' === $childNode->tagName) {
                $text[] = '<blockquote>'.$this->makeText($childNode).'</blockquote>';
            }
            if ('poem' === $childNode->tagName) {
                /** @var \DOMElement $stanzaNode */
                foreach ($childNode->getElementsByTagName('stanza') as $stanzaNode) {
                    /** @var \DOMElement $vNode */
                    foreach ($stanzaNode->getElementsByTagName('v') as $vNode) {
                        $text[] = $this->makeText($vNode, true);
                    }
                }
            }
        }

        return \implode('', $text);
    }

    private function makeAuthorText(\DOMElement $textAuthorNode): string
    {
        $author = [];
        /** @var \DOMElement|\DOMNode|\DOMNameSpaceNode $childNode */
        foreach ($textAuthorNode->childNodes as $childNode) {
            $isElement = $childNode instanceof \DOMElement;
            if ($isElement && \in_array($childNode->tagName, ['strong', 'emphasis', 'style', 'strikethrough', 'sub', 'sup', 'code'], true)) {
                $author[] = $textAuthorNode->ownerDocument->saveHTML($childNode);
            }

            if (!$isElement) {
                $author[] = $childNode->nodeValue."\n";
            }
        }

        return \implode(' ', $author);
    }

    public function getCover(): ?EbookCoverInterface
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
        $publishYear = $this->makePublishYear($publishNodeInfo);

        return new Fb2Meta(
            $title,
            $author,
            $publisher,
            $isbn,
            $description,
            $language,
            null,
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

        return $langNode?->nodeValue;
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
            /** @var \DOMElement|\DOMNode|\DOMNameSpaceNode $childNode */
            foreach ($annotationNode->childNodes as $childNode) {
                if ($childNode instanceof \DOMElement && \in_array($childNode->tagName, ['p', 'table'], true)) {
                    $text[] = $annotationNode->ownerDocument->saveHTML($childNode);
                }
            }

            return \mb_trim(\implode('', $text));
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

        return $isbnNode?->nodeValue;
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

        return $publisherNode?->nodeValue;
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

        return $authors ? \implode(', ', $authors) : null;
    }

    protected function makeTitle(\DOMElement $titleInfoNode): string
    {
        return $titleInfoNode->getElementsByTagName('book-title')->item(0)->nodeValue;
    }

    protected function getInternalFile(): string
    {
        if (!$this->internalFile) {
            $zip = new \ZipArchive();
            $res = $zip->open($this->getFile(), \ZipArchive::RDONLY);
            if (true === $res) {
                $fb2File = $zip->getNameIndex(0); // get first file in archive
                $zip->close();
                if (false === $fb2File) {
                    throw new ParserException();
                }

                $this->internalFile = 'zip://'.$this->getFile().'#'.$fb2File;
            } else {
                $this->internalFile = 'file://'.$this->getFile();
            }
        }

        return $this->internalFile;
    }

    protected function getFictionBookDescription(): \DOMElement
    {
        if ($this->fictionBookDescription) {
            return $this->fictionBookDescription;
        }

        $file = $this->getInternalFile();

        /** @var \XMLReader|false $reader */
        $reader = @\XMLReader::open($file, 'UTF-8', \LIBXML_NOENT | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
        if (!$reader) {
            throw new ParserException();
        }

        while ($reader->read()) {
            if (\XMLReader::ELEMENT === $reader->nodeType && 'description' === $reader->name) { // first description element
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

    protected function getFictionBookStylesheet(): ?\DOMElement
    {
        if ($this->fictionBookStylesheet) {
            return $this->fictionBookStylesheet;
        }

        $file = $this->getInternalFile();

        /** @var \XMLReader|false $reader */
        $reader = @\XMLReader::open($file, 'UTF-8', \LIBXML_NOENT | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
        if (!$reader) {
            throw new ParserException();
        }

        while ($reader->read()) {
            if (\XMLReader::ELEMENT === $reader->nodeType && 'stylesheet' === $reader->name) { // first stylesheet element
                /** @var \DOMElement|false $stylesheetNode */
                $stylesheetNode = $reader->expand(new \DOMDocument('1.0', 'UTF-8'));
                $reader->close();
                if (!$stylesheetNode) {
                    throw new ParserException();
                }

                $this->fictionBookStylesheet = $stylesheetNode;

                return $this->fictionBookStylesheet;
            }
        }

        $reader->close();

        return null;
    }

    protected function getFictionBookBody(): \DOMElement
    {
        if ($this->fictionBookBody) {
            return $this->fictionBookBody;
        }

        $file = $this->getInternalFile();

        /** @var \XMLReader|false $reader */
        $reader = @\XMLReader::open($file, 'UTF-8', \LIBXML_NOENT | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
        if (!$reader) {
            throw new ParserException();
        }

        while ($reader->read()) {
            if (\XMLReader::ELEMENT === $reader->nodeType && 'body' === $reader->name) { // first body element
                /** @var \DOMElement|false $bodyNode */
                $bodyNode = $reader->expand(new \DOMDocument('1.0', 'UTF-8'));
                $reader->close();
                if (!$bodyNode) {
                    throw new ParserException();
                }

                $this->fictionBookBody = $bodyNode;

                return $this->fictionBookBody;
            }
        }

        $reader->close();
        throw new ParserException();
    }
}
