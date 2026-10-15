<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function testRemovesScripts(): void
    {
        self::assertSame(
            '<p>a</p>',
            HtmlSanitizer::sanitize('<p>a</p><script>alert(1)</script>')
        );
        self::assertSame(
            '<p>a</p>',
            HtmlSanitizer::sanitize('<p>a</p><script src="x.js"/>')
        );
        self::assertSame(
            '<p>a</p>',
            HtmlSanitizer::sanitize("<p>a</p><script\n type=\"text/javascript\">\nx()\n</script>")
        );
    }

    public function testDropsRemoteAssetReferences(): void
    {
        self::assertSame(
            '<img/>',
            HtmlSanitizer::sanitize('<img src="https://cdn.example.com/x.png"/>')
        );
        self::assertSame(
            '<link rel="stylesheet"/>',
            HtmlSanitizer::sanitize('<link rel="stylesheet" href="//cdn.example.com/x.css"/>')
        );
        self::assertSame(
            '<img>',
            HtmlSanitizer::sanitize('<img src=https://cdn.example.com/x.png>')
        );
    }

    public function testKeepsLocalDataAndFragmentReferences(): void
    {
        $html = '<img src="EPUB/images/x.png"/><img src="data:image/png;base64,AAAA"/><img src="#anchor"/>';
        self::assertSame($html, HtmlSanitizer::sanitize($html));
    }

    public function testKeepsExternalLinks(): void
    {
        $html = '<a href="https://example.com">x</a>';
        self::assertSame($html, HtmlSanitizer::sanitize($html));
    }
}
