# AGENTS.md

E-book reader library: EPUB, MOBI, AZW3/KF8, FB2, HTML/HTMLZ, Markdown, TXT/TXTZ. PHP >= 8.4 + ext-zip, ext-dom, ext-xmlreader, ext-libxml, ext-mbstring, ext-zlib.

## Commands

- `composer install`
- `vendor/bin/phpunit`
- `vendor/bin/phpstan analyse`
- `vendor/bin/php-cs-fixer fix`

## Conventions

- Conventional Commits.
- Symfony CS (`@Symfony` / `@Symfony:risky`).
- Match calibre semantics (metadata, TOC, cover, content).
- Driver output (content, metadata, TOC) always UTF-8.
- Sections self-contained: refs normalized to `Resource::getPath()`, no `<script>`/remote.
- Closed array shapes: all keys present, unknown = null.
- Tests for new behavior.
