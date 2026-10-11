# E-book reader

[![License](https://poser.pugx.org/gemorroj/ebook-reader/license)](https://packagist.org/packages/gemorroj/ebook-reader)
[![Latest Stable Version](https://poser.pugx.org/gemorroj/ebook-reader/v/stable)](https://packagist.org/packages/gemorroj/ebook-reader)
[![Continuous Integration](https://github.com/Gemorroj/EbookReader/workflows/Continuous%20Integration/badge.svg)](https://github.com/Gemorroj/EbookReader/actions?query=workflow%3A%22Continuous+Integration%22)


### Formats:
| Format | Versions | Cover | Content |
| --- | --- | --- | --- |
| EPUB | 2 and 3 | yes | HTML fragments + styles |
| MOBI | uncompressed, PalmDOC, HUFF/CDIC | no | HTML |
| FB2 | 2.x, plain or ZIP | yes | HTML fragments |
| TXT | plain or ZIP | ZIP only | plain text |

Format detection is automatic (`EbookReaderFactory::create()`).


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

### Example:
```php
<?php
use EbookReader\EbookReaderFactory;
use EbookReader\Driver\Epub3Driver;

$ebookReader = EbookReaderFactory::create('file.epub');

$meta = $ebookReader->getMeta();          // EbookMetaInterface
echo $meta->getTitle();
echo $meta->getAuthor();
$publishDate = $meta->getPublishDate();   // EbookReader\Meta\PublishDate
var_dump($publishDate->getYear(), $publishDate->getMonth(), $publishDate->getDay());

$data = $ebookReader->getData();          // EbookDataInterface[]
foreach ($data as $section) {
    echo $section->getTitle();
    echo $section->getText();             // markup as-is (usually HTML)
}

$cover = $ebookReader->getCover();        // ?EbookCoverInterface
if ($cover) {
    file_put_contents('cover', $cover->getData());
}

$ebookReader = EbookReaderFactory::create('fake.file'); // throws UnsupportedFormatException

$driver = new Epub3Driver('fake.file');
var_dump($driver->isValid());             // false
```

### Limitations:
- DRM-protected books are not supported.
