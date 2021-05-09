# E-book reader

[![License](https://poser.pugx.org/gemorroj/ebook-reader/license)](https://packagist.org/packages/gemorroj/ebook-reader)
[![Latest Stable Version](https://poser.pugx.org/gemorroj/ebook-reader/v/stable)](https://packagist.org/packages/gemorroj/ebook-reader)
[![Continuous Integration](https://github.com/Gemorroj/EbookReader/workflows/Continuous%20Integration/badge.svg?branch=master)](https://github.com/Gemorroj/EbookReader/actions?query=workflow%3A%22Continuous+Integration%22)


### In progress...
- Use https://www.php.net/xmlreader to save memory


### Formats:
- EPUB
- MOBI
- FB2, FB2-ZIP


### Requirements:
- PHP >= 7.4
- ext-zip
- ext-dom

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
$meta = $ebookReader->getMeta();
print_r($meta); // EbookMetaInterface object


$ebookReader = EbookReaderFactory::create('fake.file'); // throws UnsupportedFormatException exception


$driver = new Epub3Driver('fake.file');
var_dump($driver->isValid()); // false
```
