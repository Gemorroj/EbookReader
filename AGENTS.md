# AGENTS.md

PHP library for reading e-books (EPUB, MOBI, FB2, TXT).

## Requirements

- PHP >= 8.4 with ext-zip, ext-dom, ext-xmlreader, ext-libxml, ext-mbstring

## Structure

- `src/` — library code, PSR-4 `EbookReader\`
- `tests/` — PHPUnit tests, PSR-4 `EbookReader\Tests\`

## Commands

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix
```

## Style

- Follow existing code style; run php-cs-fixer before committing.
- Keep changes minimal, add tests for new behavior.
