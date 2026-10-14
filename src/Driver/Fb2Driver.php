<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Cover\Cover;
use EbookReader\Exception\ParserException;
use EbookReader\Format;
use EbookReader\Meta\Fb2Meta;
use EbookReader\Meta\PublishDate;
use EbookReader\Resource\Resource as EbookResource;
use EbookReader\Section\Fb2Epigraph;
use EbookReader\Section\Fb2Section;
use EbookReader\TableOfContents\TableOfContents;

/**
 * @see https://wiki.mobileread.com/wiki/FB2
 * @see http://www.fictionbook.org/index.php/XML_%D1%81%D1%85%D0%B5%D0%BC%D0%B0_FictionBook2.2
 * @see http://www.tinlib.ru/kompyutery_i_internet/sozdanie_yelektronnyh_knig_v_formate_fictionbook_2_1_prakticheskoe_rukovodstvo/p5.php
 */
final class Fb2Driver extends AbstractDriver
{
    private ?string $internalFile = null;

    public function getFormat(): Format
    {
        return Format::FB2;
    }

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
     * @return Fb2Section[]
     */
    public function getSections(): array
    {
        $stylesheet = $this->getFictionBookStylesheet()?->nodeValue;
        $styleBlock = null !== $stylesheet && '' !== \mb_trim($stylesheet)
            ? '<style type="text/css">'."\n".$stylesheet."\n".'</style>'
            : '';

        $data = [];
        foreach ($this->getFictionBookSections() as $index => $sectionNode) {
            $data[] = $this->makeSectionData($index, $sectionNode, $styleBlock);
        }

        return $data;
    }

    private function makeSectionData(int $index, \DOMElement $sectionNode, string $styleBlock): Fb2Section
    {
        $id = $sectionNode->getAttribute('id');
        if ('' === $id) {
            $id = 'section-'.$index;
        }

        $title = $sectionNode->getElementsByTagName('title')->item(0)?->textContent;
        $text = $styleBlock.$this->makeText($sectionNode);

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
                $epigraphs[] = new Fb2Epigraph($epigraphText, $authors);
            }
        }

        return new Fb2Section($id, $text, $title, $annotation, $epigraphs, $this->collectResources($sectionNode));
    }

    /**
     * @return EbookResource[]
     */
    private function collectResources(\DOMElement $sectionNode): array
    {
        $resources = [];
        $seen = [];
        /** @var \DOMElement $imageNode */
        foreach ($sectionNode->getElementsByTagName('image') as $imageNode) {
            $ref = $this->getHrefAttribute($imageNode);
            if ('' === $ref || '#' !== $ref[0] || isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;

            try {
                $binaryNode = $this->getFictionBookBinaryById(\substr($ref, 1));
            } catch (ParserException) {
                continue;
            }

            $data = \base64_decode($binaryNode->textContent, true);
            if (false === $data) {
                continue;
            }

            $mime = $binaryNode->getAttribute('content-type');
            if ('' === $mime) {
                $mime = $this->getMimeDetector()->detect($data) ?? '';
            }

            $resources[] = new EbookResource($ref, $data, $mime);
        }

        return $resources;
    }

    private function getHrefAttribute(\DOMElement $node): string
    {
        foreach ($node->attributes as $attribute) {
            if ('href' === $attribute->localName) {
                return $attribute->value;
            }
        }

        return '';
    }

    private function makeText(\DOMElement $node, bool $rowFrame = false): string
    {
        $text = [];
        /** @var \DOMElement|\DOMNode|\DOMNameSpaceNode $childNode */
        foreach ($node->childNodes as $childNode) {
            if (!$childNode instanceof \DOMElement) {
                if ($rowFrame) {
                    $text[] = '<p>'.$childNode->nodeValue.'</p>';
                } else {
                    $text[] = $childNode->nodeValue."\n";
                }
                continue;
            }

            if (\in_array($childNode->tagName, ['p', 'table', 'strong', 'emphasis', 'style', 'strikethrough', 'sub', 'sup', 'code', 'image'], true)) {
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

    public function getCover(): ?Cover
    {
        // http://www.fictionbook.org/index.php/%D0%AD%D0%BB%D0%B5%D0%BC%D0%B5%D0%BD%D1%82_coverpage

        $descriptionNode = $this->getFictionBookDescription();
        /** @var \DOMElement $titleInfoNode */
        $titleInfoNode = $descriptionNode->getElementsByTagName('title-info')->item(0);

        /** @var \DOMElement|null $coverpageNode */
        $coverpageNode = $titleInfoNode->getElementsByTagName('coverpage')->item(0);
        if ($coverpageNode) {
            /** @var \DOMElement|null $imageNode */
            $imageNode = $coverpageNode->getElementsByTagName('image')->item(0);
            if (null !== $imageNode) {
                $link = \ltrim($this->getHrefAttribute($imageNode), '#');
                if ('' !== $link) {
                    $node = $this->getFictionBookBinaryById($link);

                    $data = \base64_decode($node->textContent, true);
                    if (false !== $data) {
                        $mime = $node->getAttribute('content-type');
                        if ('' === $mime) {
                            $mime = $this->getMimeDetector()->detect($data) ?? '';
                        }

                        return new Cover($data, $mime);
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return TableOfContents[]
     */
    public function getTableOfContents(): array
    {
        $file = $this->internalFile ?? $this->makeInternalFile();

        /** @var \XMLReader|false $reader */
        $reader = @\XMLReader::open($file, 'UTF-8', \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
        if (!$reader) {
            throw new ParserException();
        }

        $entries = [];
        $sectionIndexes = [];
        $sectionCount = 0;
        $inBody = false;

        try {
            while ($reader->read()) {
                if (\XMLReader::ELEMENT === $reader->nodeType) {
                    if ('body' === $reader->name) {
                        $inBody = true;
                    } elseif ($inBody && 'section' === $reader->name) {
                        $sectionIndexes[] = $sectionCount;
                        ++$sectionCount;
                    } elseif ($inBody && 'title' === $reader->name) {
                        $title = \mb_trim(\strip_tags($reader->readInnerXml()));
                        if ('' !== $title) {
                            $sectionIndex = \end($sectionIndexes);
                            $entries[] = new TableOfContents($title, \max(1, \count($sectionIndexes)), false === $sectionIndex ? null : $sectionIndex, null, null);
                        }
                    }
                } elseif ($inBody && \XMLReader::END_ELEMENT === $reader->nodeType) {
                    if ('section' === $reader->name) {
                        \array_pop($sectionIndexes);
                    } elseif ('body' === $reader->name) {
                        break;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return $entries;
    }

    public function getMeta(): Fb2Meta
    {
        $descriptionNode = $this->getFictionBookDescription();

        /** @var \DOMElement $titleInfoNode */
        $titleInfoNode = $descriptionNode->getElementsByTagName('title-info')->item(0);

        /** @var \DOMElement|null $publishNodeInfo */
        $publishNodeInfo = $descriptionNode->getElementsByTagName('publish-info')->item(0);

        $title = $this->makeTitle($titleInfoNode);
        $authors = $this->makeAuthors($titleInfoNode);
        $publisher = $publishNodeInfo ? $this->makePublisher($publishNodeInfo) : null;
        $isbn = $publishNodeInfo ? $this->makeIsbn($publishNodeInfo) : null;
        $description = $this->makeDescription($titleInfoNode);
        $language = $this->makeLanguage($titleInfoNode);
        $publishYear = $this->makePublishYear($publishNodeInfo);
        $subjects = $this->makeSubjects($titleInfoNode);

        return new Fb2Meta(
            title: $title,
            authors: $authors,
            publisher: $publisher,
            isbn: $isbn,
            description: $description,
            language: $language,
            subjects: $subjects,
            license: null,
            publishDate: new PublishDate($publishYear),
        );
    }

    /**
     * @return string[]
     */
    protected function makeSubjects(\DOMElement $titleInfoNode): array
    {
        $subjects = [];
        /** @var \DOMElement $genreNode */
        foreach ($titleInfoNode->getElementsByTagName('genre') as $genreNode) {
            $value = \mb_trim($genreNode->nodeValue);
            if ('' !== $value) {
                $subjects[] = $value;
            }
        }

        return $subjects;
    }

    protected function makePublishYear(?\DOMElement $publishNodeInfo): ?int
    {
        if (null === $publishNodeInfo) {
            return null;
        }

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

    /**
     * @return string[]
     */
    protected function makeAuthors(\DOMElement $titleInfoNode): array
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
            $firstName = $authorNode->getElementsByTagName('first-name')->item(0)?->nodeValue;
            $middleName = $authorNode->getElementsByTagName('middle-name')->item(0)?->nodeValue;
            $lastName = $authorNode->getElementsByTagName('last-name')->item(0)?->nodeValue;
            $nickname = $authorNode->getElementsByTagName('nickname')->item(0)?->nodeValue;

            if ($firstName && $middleName && $lastName) {
                $authors[] = $firstName.' '.$middleName.' '.$lastName;
            } elseif ($firstName && $lastName) {
                $authors[] = $firstName.' '.$lastName;
            } elseif ($nickname) {
                $authors[] = $nickname;
            }
        }

        return $authors;
    }

    protected function makeTitle(\DOMElement $titleInfoNode): string
    {
        return $titleInfoNode->getElementsByTagName('book-title')->item(0)->nodeValue;
    }

    protected function makeInternalFile(): string
    {
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

        return $this->internalFile;
    }

    protected function getFictionBookDescription(): \DOMElement
    {
        $file = $this->internalFile ?? $this->makeInternalFile();

        /** @var \XMLReader|false $reader */
        $reader = @\XMLReader::open($file, 'UTF-8', \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
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

                return $descriptionNode;
            }
        }

        $reader->close();
        throw new ParserException();
    }

    protected function getFictionBookStylesheet(): ?\DOMElement
    {
        $file = $this->internalFile ?? $this->makeInternalFile();

        /** @var \XMLReader|false $reader */
        $reader = @\XMLReader::open($file, 'UTF-8', \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
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

                return $stylesheetNode;
            }
        }

        $reader->close();

        return null;
    }

    /**
     * Streams <section> elements of the first <body> without expanding the whole body into memory.
     *
     * @return \Generator<\DOMElement>
     */
    protected function getFictionBookSections(): \Generator
    {
        $file = $this->internalFile ?? $this->makeInternalFile();

        /** @var \XMLReader|false $reader */
        $reader = @\XMLReader::open($file, 'UTF-8', \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
        if (!$reader) {
            throw new ParserException();
        }

        try {
            $inBody = false;
            while ($reader->read()) {
                if (\XMLReader::ELEMENT === $reader->nodeType) {
                    if ('body' === $reader->name) {
                        $inBody = true;
                        continue;
                    }

                    if ($inBody && 'section' === $reader->name) {
                        /** @var \DOMElement|false $sectionNode */
                        $sectionNode = $reader->expand(new \DOMDocument('1.0', 'UTF-8'));
                        if (!$sectionNode) {
                            throw new ParserException();
                        }

                        yield $sectionNode;
                    }
                } elseif ($inBody && \XMLReader::END_ELEMENT === $reader->nodeType && 'body' === $reader->name) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }
    }

    protected function getFictionBookBinaryById(string $id): \DOMElement
    {
        $file = $this->internalFile ?? $this->makeInternalFile();

        /** @var \XMLReader|false $reader */
        $reader = @\XMLReader::open($file, 'UTF-8', \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOBLANKS);
        if (!$reader) {
            throw new ParserException();
        }

        while ($reader->read()) {
            if (\XMLReader::ELEMENT === $reader->nodeType && 'binary' === $reader->name && $id === $reader->getAttribute('id')) {
                /** @var \DOMElement|false $binaryNode */
                $binaryNode = $reader->expand(new \DOMDocument('1.0', 'UTF-8'));
                $reader->close();
                if (!$binaryNode) {
                    throw new ParserException();
                }

                return $binaryNode;
            }
        }

        $reader->close();
        throw new ParserException();
    }
}
