# E-book reader

[![License](https://poser.pugx.org/gemorroj/ebook-reader/license)](https://packagist.org/packages/gemorroj/ebook-reader)
[![Latest Stable Version](https://poser.pugx.org/gemorroj/ebook-reader/v/stable)](https://packagist.org/packages/gemorroj/ebook-reader)
[![Continuous Integration](https://github.com/Gemorroj/EbookReader/workflows/Continuous%20Integration/badge.svg)](https://github.com/Gemorroj/EbookReader/actions?query=workflow%3A%22Continuous+Integration%22)


### Formats:
| Format | Versions | Cover | Assets | Table of contents | Content |
| --- | --- | --- | --- | --- | --- |
| EPUB | 2 and 3 | yes | images, styles, fonts | yes (anchors) | self-contained XHTML |
| MOBI | uncompressed, PalmDOC, HUFF/CDIC | yes | images | yes (positions) | HTML |
| AZW3 | standalone and joint KF8 | yes | images, fonts | yes (positions) | HTML (flows inlined) |
| FB2 | 2.x, plain or ZIP | yes | images | yes | HTML (styles inlined) |
| HTML | single file or `htmlz` ZIP | ZIP only | images, styles, fonts (ZIP) | headings | HTML |
| TXT | plain or ZIP (`txtz`) | ZIP only | - | chapters | plain text |

Every format is read through the same interface. `EbookReaderFactory::create()` detects the format automatically; the returned reader exposes metadata, cover, table of contents, content sections and their assets.


### Requirements:
- PHP >= 8.4
- ext-zip
- ext-dom
- ext-xmlreader
- ext-libxml
- ext-mbstring


### Installation:
```bash
composer require gemorroj/ebook-reader
```

### Usage:

The format is detected automatically:

```php
use EbookReader\EbookReaderFactory;

$book = EbookReaderFactory::create('book.epub');
$book->getFormat();                       // EbookReader\Model\Format::EPUB
```

#### Metadata and cover

```php
$meta = $book->getMeta();                 // EbookReader\Model\Meta\EbookMetaInterface
$meta->getTitle();
$meta->getAuthor();                       // authors joined by ", "
$meta->getAuthors();                      // string[]
$meta->getSubjects();                     // string[]
$meta->getPublisher();
$meta->getLanguage();
$meta->getIsbn();
$meta->getDescription();
$meta->getPublishDate();                  // EbookReader\Model\Meta\PublishDate (getYear/getMonth/getDay/isEmpty)

$cover = $book->getCover();               // ?EbookReader\Model\Cover\EbookCoverInterface
if (null !== $cover) {
    file_put_contents('cover', $cover->getData());
    $cover->getMime();                    // e.g. "image/jpeg"
}
```

#### Content

A book is a list of **sections**. Each section is self-contained markup whose
asset references are normalized to `Resource::getPath()`. All text — content,
titles and metadata — is returned as UTF-8:

```php
foreach ($book->getSections() as $section) {   // EbookReader\Model\Section\EbookSectionInterface
    $section->getId();                         // stable id, e.g. "EPUB/text/ch01.xhtml"
    $section->getTitle();                      // title of the section, or null
    $section->getMediaType();                  // "application/xhtml+xml" | "text/html" | "text/plain"
    $section->getContent();                    // self-contained markup

    // assets referenced by this section (images, fonts, stylesheets, ...)
    foreach ($section->getResources() as $resource) {
        // EbookReader\Model\Resource\EbookResourceInterface
        $resource->getPath();                  // matches the reference inside getContent()
        $resource->getType();                  // EbookReader\Model\Resource\ResourceType
        $resource->getMime();
        $resource->getData();
    }
}
```

#### Assets

Every asset of the book at once, de-duplicated by path:

```php
foreach ($book->getResources() as $resource) { // EbookReader\Model\Resource\EbookResourceInterface[]
    $dir = $resource->getType()->value;        // "image", "font", "style", "audio", "video", "other"
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/'.basename($resource->getPath()), $resource->getData());
}
```

#### Table of contents

```php
foreach ($book->getTableOfContents() as $entry) { // EbookReader\Model\TableOfContents\TableOfContents[]
    echo str_repeat('  ', $entry->getLevel() - 1).$entry->getTitle();

    $entry->getSectionIndex();   // index into getSections(), or null
    $entry->getAnchor();         // element id inside the section (EPUB), or null
    $entry->getPosition();       // byte offset inside the section (MOBI/KF8), or null
}
```

#### Drivers and errors

`EbookReaderFactory::create()` throws `UnsupportedFormatException` for an unknown
format and `FileNotReadableException` for an unreadable file:

```php
use EbookReader\Driver\Epub3Driver;

$driver = new Epub3Driver('book.epub');
$driver->isValid();                            // true if the file is a valid EPUB; never throws

EbookReaderFactory::create('unknown.bin');     // throws UnsupportedFormatException
```


#### Encodings

All text is returned as UTF-8. Formats that declare their charset (MOBI/KF8, EPUB, FB2) are decoded automatically; for the rest (TXT, HTML) the decoder is configurable:

```php
use EbookReader\Text\TextDecoder;

EbookReaderFactory::create('book.txt');                                        // detect the charset (default)
EbookReaderFactory::create('book.txt', textDecoder: TextDecoder::strict());     // reject undeclared non-UTF-8
EbookReaderFactory::create('book.txt', textDecoder: TextDecoder::fallback('Windows-1252'));
```

Detection picks the most plausible candidate (Cyrillic, Latin and Chinese legacy encodings, GB18030 as the CJK catch-all) and refuses binary data rather than guessing. Declare other CJK charsets (Big5, Shift_JIS, EUC-KR) explicitly via `fallback()`.

### Limitations:
- DRM-protected books are not supported.
- HTML: a single file, or an "htmlz" ZIP (the index document is read, its images/fonts/stylesheets are exposed as resources, and title/author/cover come from a `metadata.opf` when present); the table of contents is derived from the document headings (h1..h3) — HTML book layout is not standardised.
- TXT: chapters are detected with a conservative keyword heuristic (English/Russian), and become sections and table-of-contents entries; a ZIP ("txtz") is read as all of its text files joined, with title/author/cover taken from `metadata.opf` when present.
- AZW3 supports standalone and joint MOBI6 + KF8 files.
- Internal `kindle:pos:` (KF8) file-position links are not converted to anchors; EPUB `@import` chains in stylesheets are not resolved.
