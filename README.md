# E-book reader

[![License](https://poser.pugx.org/gemorroj/ebook-reader/license)](https://packagist.org/packages/gemorroj/ebook-reader)
[![Latest Stable Version](https://poser.pugx.org/gemorroj/ebook-reader/v/stable)](https://packagist.org/packages/gemorroj/ebook-reader)
[![Continuous Integration](https://github.com/Gemorroj/EbookReader/workflows/Continuous%20Integration/badge.svg)](https://github.com/Gemorroj/EbookReader/actions?query=workflow%3A%22Continuous+Integration%22)


### Formats:
| Format | Variants | Cover | Assets | Table of contents | Content |
| --- | --- | --- | --- | --- | --- |
| EPUB | 2 and 3 | yes | images, styles, fonts | yes (anchors) | self-contained XHTML |
| MOBI | uncompressed, PalmDOC, HUFF/CDIC | yes | images | yes (positions) | HTML |
| AZW3 | standalone and joint KF8 | yes | images, fonts | yes (positions) | HTML (flows inlined) |
| FB2 | 2.x, plain or ZIP | yes | images | yes | HTML (styles inlined) |
| HTML | single file or ZIP (`htmlz`) | ZIP only | images, styles, fonts (ZIP) | headings | HTML |
| Markdown | `.md`/`.markdown`, or ZIP (`txtz`) | ZIP only | images, styles, fonts (ZIP) | headings | Markdown source |
| TXT | plain or ZIP (`txtz`) | ZIP only | - | chapters | plain text |

Every format is read through the same interface. `EbookReaderFactory::create()` detects the format automatically; the returned reader exposes metadata, cover, table of contents, content sections and their assets.

A book can be read from a file path, from in-memory content, or from any `BookSource`. In-memory content is materialised into a temporary file, because every driver needs random access to the content; the temporary file lives for as long as the returned reader and is removed with it.


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

// From content held in memory (download, stream, database blob, ...):
$book = EbookReaderFactory::createFromContent($bytes);
```

A custom source implements `EbookReader\Source\BookSource`:

```php
use EbookReader\EbookReaderFactory;
use EbookReader\Source\FileSource;
use EbookReader\Source\StringSource;

EbookReaderFactory::createFromSource(new FileSource('book.epub'));
EbookReaderFactory::createFromSource(new StringSource($bytes));
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
foreach ($book->getTableOfContents() as $entry) { // EbookReader\Model\TableOfContents\TableOfContentsEntry[]
    echo str_repeat('  ', $entry->getLevel() - 1).$entry->getTitle();

    $entry->getSectionIndex();   // index into getSections(), or null
    $entry->getAnchor();         // element id inside the section (EPUB, HTML), or null
    $entry->getPosition();       // byte offset inside the section (MOBI/KF8), or null
}
```

#### Drivers and errors

Every factory method throws `UnsupportedFormatException` for an unknown format;
`create()` additionally throws `FileNotReadableException` for an unreadable file,
and `createFromContent()` throws `SourceException` when the content cannot be
materialised into a temporary file:

```php
use EbookReader\Driver\Epub3Driver;

$driver = new Epub3Driver('book.epub');
$driver->isValid();                            // true if the file is a valid EPUB; never throws

EbookReaderFactory::create('unknown.bin');     // throws UnsupportedFormatException
```


#### Encodings

All text is returned as UTF-8. Formats that declare a charset (MOBI/KF8, EPUB, FB2) are decoded automatically; for TXT, Markdown and HTML the decoder is configurable (detected by default):

```php
use EbookReader\Encoding\TextDecoder;

EbookReaderFactory::create('book.txt');                                        // detect the charset (default)
EbookReaderFactory::create('book.txt', textDecoder: TextDecoder::strict());     // reject undeclared non-UTF-8
EbookReaderFactory::create('book.txt', textDecoder: TextDecoder::fallback('Windows-1252'));
```

Detection covers legacy Cyrillic/Latin and Chinese (GB18030) and refuses binary data; other CJK charsets (Big5, Shift_JIS, EUC-KR) must be declared with `fallback()`.

### Limitations:
- DRM-protected books are not supported.
- HTML layout is not standardised: only the index document of a ZIP is read, and the table of contents is guessed from headings (h1..h3).
- TXT has no markup: chapters are guessed from keywords (English/Russian).
- Markdown is exposed as its source (`text/markdown`), not converted to HTML; the table of contents comes from ATX/Setext headings, and detection is heuristic (a document needs a heading, a fenced code block, a link/image or a table, so prose-only or Setext-only files read as TXT).
- AZW3 supports standalone and joint MOBI6 + KF8 files.
- KF8 `kindle:pos:` links are not converted to anchors; EPUB `@import` chains are not resolved.
