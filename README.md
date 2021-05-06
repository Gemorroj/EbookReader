# E-book reader

[![License](https://poser.pugx.org/gemorroj/ebookreader/license)](https://packagist.org/packages/gemorroj/ebookreader)
[![Latest Stable Version](https://poser.pugx.org/gemorroj/ebookreader/v/stable)](https://packagist.org/packages/gemorroj/ebookreader)
[![Continuous Integration](https://github.com/Gemorroj/EbookReader/workflows/Continuous%20Integration/badge.svg?branch=master)](https://github.com/Gemorroj/EbookReader/actions?query=workflow%3A%22Continuous+Integration%22)


### In progress...
- Epub: https://www.w3.org/publishing/epub3/epub-spec.html
- Mobi: https://wiki.mobileread.com/wiki/MOBI
- Fb2: https://wiki.mobileread.com/wiki/FB2
- ...

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

// $ebookReader = EbookReaderFactory::create('file.fb2');
$ebookReader = EbookReaderFactory::create('file.epub');
// or $ebookReader = new Epub3Driver('file.epub');

$meta = $ebookReader->read();
print_r($meta);
```
