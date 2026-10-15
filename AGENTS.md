# AGENTS.md

PHP library for reading e-books (EPUB, MOBI, AZW3/KF8, FB2, HTML, TXT). PHP >= 8.4 with ext-zip, ext-dom, ext-xmlreader, ext-libxml, ext-mbstring.

## Structure

- `src/` — PSR-4 `EbookReader\`
- `tests/` — PHPUnit `EbookReader\Tests\`; mirrors `src/`

## Commands

- `composer install`
- `vendor/bin/phpunit`
- `vendor/bin/phpstan analyse`
- `vendor/bin/php-cs-fixer fix`

## Conventions

- Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/).
- Symfony style is the reference (`.php-cs-fixer.dist.php` extends `@Symfony` / `@Symfony:risky`); don't deviate without reason.
- All text a driver returns (content, metadata, table of contents) must be UTF-8 — convert from the source charset, never leak raw non-UTF-8 bytes.
- Array shapes are closed: every key is always present; unknown values are null (never `key?: type`).
- Add tests for new behavior.
