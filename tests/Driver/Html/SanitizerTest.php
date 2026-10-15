<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver\Html;

use EbookReader\Driver\Html\Sanitizer;
use PHPUnit\Framework\TestCase;

final class SanitizerTest extends TestCase
{
    public function testRemovesScripts(): void
    {
        self::assertSame(
            '<p>a</p>',
            Sanitizer::sanitize('<p>a</p><script>alert(1)</script>')
        );
        self::assertSame(
            '<p>a</p>',
            Sanitizer::sanitize('<p>a</p><script src="x.js"/>')
        );
        self::assertSame(
            '<p>a</p>',
            Sanitizer::sanitize("<p>a</p><script\n type=\"text/javascript\">\nx()\n</script>")
        );
    }

    public function testDropsRemoteAssetReferences(): void
    {
        self::assertSame(
            '<img/>',
            Sanitizer::sanitize('<img src="https://cdn.example.com/x.png"/>')
        );
        self::assertSame(
            '<link rel="stylesheet"/>',
            Sanitizer::sanitize('<link rel="stylesheet" href="//cdn.example.com/x.css"/>')
        );
        self::assertSame(
            '<img>',
            Sanitizer::sanitize('<img src=https://cdn.example.com/x.png>')
        );
        self::assertSame(
            '<iframe></iframe>',
            Sanitizer::sanitize('<iframe src="https://cdn.example.com/x"></iframe>')
        );
        self::assertSame(
            '<video></video>',
            Sanitizer::sanitize('<video poster="https://cdn.example.com/x.jpg"></video>')
        );
    }

    public function testKeepsLocalDataAndFragmentReferences(): void
    {
        $html = '<img src="EPUB/images/x.png"/><img src="data:image/png;base64,AAAA"/><img src="#anchor"/>';
        self::assertSame($html, Sanitizer::sanitize($html));
    }

    public function testKeepsExternalLinks(): void
    {
        $html = '<a href="https://example.com">x</a>';
        self::assertSame($html, Sanitizer::sanitize($html));
    }
}
