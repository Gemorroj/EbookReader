<?php

declare(strict_types=1);

namespace EbookReader\Driver;

use EbookReader\Exception\ParserException;
use EbookReader\Model\Cover\Cover;
use EbookReader\Model\Format;
use EbookReader\Model\Meta\Metadata;
use EbookReader\Model\Meta\PublishDate;
use EbookReader\Model\Resource\Resource as EbookResource;
use EbookReader\Model\Section\Fb2Epigraph;
use EbookReader\Model\Section\Fb2Section;
use EbookReader\Model\TableOfContents\TableOfContentsEntry;

/**
 * @see https://wiki.mobileread.com/wiki/FB2
 * @see http://www.fictionbook.org/index.php/XML_%D1%81%D1%85%D0%B5%D0%BC%D0%B0_FictionBook2.2
 * @see http://www.tinlib.ru/kompyutery_i_internet/sozdanie_yelektronnyh_knig_v_formate_fictionbook_2_1_prakticheskoe_rukovodstvo/p5.php
 */
final class Fb2Driver extends AbstractDriver
{
    private ?string $internalFile = null;

    #[\Override]
    public function getFormat(): Format
    {
        return Format::FB2;
    }

    #[\Override]
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
     * @return list<Fb2Section>
     */
    #[\Override]
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

        $titleNode = self::childElements($sectionNode, 'title')[0] ?? null;
        $title = $titleNode?->textContent;
        $text = $styleBlock.$this->renderChildren($sectionNode);

        $annotationNode = self::childElements($sectionNode, 'annotation')[0] ?? null;
        $annotation = $annotationNode ? $this->renderChildren($annotationNode) : null;

        $epigraphs = [];
        foreach (self::childElements($sectionNode, 'epigraph') as $epigraphNode) {
            $epigraphText = $this->renderChildren($epigraphNode);
            if ('' !== $epigraphText) {
                $authors = [];
                foreach (self::childElements($epigraphNode, 'text-author') as $textAuthorNode) {
                    $author = \mb_trim($this->renderChildren($textAuthorNode));
                    if ('' !== $author) {
                        $authors[] = $author;
                    }
                }
                $epigraphs[] = new Fb2Epigraph($epigraphText, $authors);
            }
        }

        return new Fb2Section($id, $text, $title, $annotation, $epigraphs, $this->collectResources($sectionNode));
    }

    /**
     * Direct child elements with the given local name (no descent into
     * subsections, so a section never borrows its children's title/annotation).
     *
     * @return list<\DOMElement>
     */
    private static function childElements(\DOMElement $node, string $name): array
    {
        $elements = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $name === \strtolower($child->localName ?? $child->tagName)) {
                $elements[] = $child;
            }
        }

        return $elements;
    }

    /**
     * @return list<EbookResource>
     */
    private function collectResources(\DOMElement $sectionNode): array
    {
        $resources = [];
        $seen = [];
        /** @var \DOMElement $imageNode */
        foreach ($sectionNode->getElementsByTagName('image') as $imageNode) {
            if ($this->hasSectionAncestor($imageNode, $sectionNode)) {
                continue;
            }

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

    /**
     * Whether the image sits inside a nested <section>, i.e. belongs to a
     * section that is emitted on its own.
     */
    private function hasSectionAncestor(\DOMElement $imageNode, \DOMElement $sectionNode): bool
    {
        for ($node = $imageNode->parentNode; $node instanceof \DOMElement && $node !== $sectionNode; $node = $node->parentNode) {
            if ('section' === \strtolower($node->localName ?? $node->tagName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Renders an FB2 element (and its subtree) as HTML.
     */
    private function renderNode(\DOMElement $node, bool $inPoem = false): string
    {
        $tag = \strtolower($node->localName ?? $node->tagName);

        return match ($tag) {
            'p' => '<p>'.$this->renderChildren($node, $inPoem).'</p>',
            'subtitle' => $this->renderHeading($node, 'h3'),
            'cite' => '<blockquote>'.$this->renderChildren($node, $inPoem).'</blockquote>',
            'poem' => '<div class="poem">'.$this->renderChildren($node, true).'</div>',
            'stanza' => '<div class="stanza">'.$this->renderChildren($node, true).'</div>',
            'v' => '<p>'.$this->renderChildren($node, $inPoem).'</p>',
            'title' => $this->renderHeading($node, $inPoem ? 'h4' : 'h2'),
            'table' => '<table>'.$this->renderChildren($node, $inPoem).'</table>',
            'tr' => '<tr>'.$this->renderChildren($node, $inPoem).'</tr>',
            'td' => '<td>'.$this->renderChildren($node, $inPoem).'</td>',
            'th' => '<th>'.$this->renderChildren($node, $inPoem).'</th>',
            'strong' => '<strong>'.$this->renderChildren($node, $inPoem).'</strong>',
            'emphasis' => '<em>'.$this->renderChildren($node, $inPoem).'</em>',
            'strikethrough' => '<s>'.$this->renderChildren($node, $inPoem).'</s>',
            'sub' => '<sub>'.$this->renderChildren($node, $inPoem).'</sub>',
            'sup' => '<sup>'.$this->renderChildren($node, $inPoem).'</sup>',
            'code' => '<code>'.$this->renderChildren($node, $inPoem).'</code>',
            'style' => $this->renderStyle($node, $inPoem),
            'a' => $this->renderLink($node, $inPoem),
            'image' => $this->renderImage($node),
            'empty-line' => '<br/>',
            // Exposed by getTitle()/getEpigraphs()/getAnnotation() or emitted as
            // its own section, so it must not be duplicated inside the content.
            'epigraph', 'annotation', 'text-author', 'section' => '',
            default => $this->renderChildren($node, $inPoem),
        };
    }

    /**
     * Renders the children of an FB2 node as HTML, escaping text nodes so the
     * result is a well-formed HTML fragment.
     */
    private function renderChildren(\DOMNode $node, bool $inPoem = false): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $html .= $this->renderNode($child, $inPoem);
            } elseif (\XML_TEXT_NODE === $child->nodeType || \XML_CDATA_SECTION_NODE === $child->nodeType) {
                $html .= \htmlspecialchars($child->nodeValue ?? '', \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            }
        }

        return $html;
    }

    /**
     * Renders an FB2 <title>/<subtitle> as a heading. Their children are
     * block-level <p>/<empty-line>, which would be invalid inside a heading, so
     * the text is flattened to a single line.
     */
    private function renderHeading(\DOMElement $node, string $tag): string
    {
        $parts = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $value = \mb_trim($child->textContent);
            } elseif (\XML_TEXT_NODE === $child->nodeType || \XML_CDATA_SECTION_NODE === $child->nodeType) {
                $value = \mb_trim($child->nodeValue ?? '');
            } else {
                continue;
            }

            if ('' !== $value) {
                $parts[] = $value;
            }
        }

        $text = \mb_trim(\implode(' ', $parts));
        if ('' === $text) {
            return '';
        }

        return '<'.$tag.'>'.\htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8').'</'.$tag.'>';
    }

    private function renderStyle(\DOMElement $node, bool $inPoem): string
    {
        $name = $node->getAttribute('name');
        $attribute = '' === $name ? '' : ' class="'.\htmlspecialchars($name, \ENT_QUOTES | \ENT_HTML5, 'UTF-8').'"';

        return '<span'.$attribute.'>'.$this->renderChildren($node, $inPoem).'</span>';
    }

    private function renderLink(\DOMElement $node, bool $inPoem): string
    {
        $href = $this->getHrefAttribute($node);
        $attribute = '' === $href ? '' : ' href="'.\htmlspecialchars($href, \ENT_QUOTES | \ENT_HTML5, 'UTF-8').'"';

        return '<a'.$attribute.'>'.$this->renderChildren($node, $inPoem).'</a>';
    }

    private function renderImage(\DOMElement $node): string
    {
        $href = $this->getHrefAttribute($node);
        if ('' === $href) {
            return '';
        }

        return '<img src="'.\htmlspecialchars($href, \ENT_QUOTES | \ENT_HTML5, 'UTF-8').'"/>';
    }

    #[\Override]
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
     * @return list<TableOfContentsEntry>
     */
    #[\Override]
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
                            $entries[] = new TableOfContentsEntry($title, \max(1, \count($sectionIndexes)), false === $sectionIndex ? null : $sectionIndex, null, null);
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

    #[\Override]
    public function getMeta(): Metadata
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

        return new Metadata(
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
     * @return list<string>
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
     * @return list<string>
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
