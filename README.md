# E-book reader

### In progress...
- Epub: https://www.w3.org/publishing/epub3/epub-spec.html
- Mobi: https://wiki.mobileread.com/wiki/MOBI
- Fb2: https://wiki.mobileread.com/wiki/FB2
- ...


### Architect like that
```
EbookReaderFactory::create - returns driver

EbookDriverInterface->read - returns meta
Driver\Epub
Driver\Mobi
Driver\Fb2

EbookMetaInterface->getTitle, getSize etc...
Meta\Epub
Meta\Mobi
Meta\Fb2
```
