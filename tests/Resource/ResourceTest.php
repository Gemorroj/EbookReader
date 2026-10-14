<?php

declare(strict_types=1);

namespace EbookReader\Tests\Resource;

use EbookReader\Resource\Resource;
use EbookReader\Resource\ResourceType;
use PHPUnit\Framework\TestCase;

final class ResourceTest extends TestCase
{
    public function testTypeIsDerivedFromMime(): void
    {
        self::assertSame(ResourceType::IMAGE, new Resource('a.jpg', 'x', 'image/jpeg')->getType());
        self::assertSame(ResourceType::FONT, new Resource('a.ttf', 'x', 'font/ttf')->getType());
        self::assertSame(ResourceType::FONT, new Resource('a.otf', 'x', 'application/vnd.ms-opentype')->getType());
        self::assertSame(ResourceType::STYLE, new Resource('a.css', 'x', 'text/css')->getType());
        self::assertSame(ResourceType::AUDIO, new Resource('a.mp3', 'x', 'audio/mpeg')->getType());
        self::assertSame(ResourceType::VIDEO, new Resource('a.mp4', 'x', 'video/mp4')->getType());
        self::assertSame(ResourceType::OTHER, new Resource('a.bin', 'x', 'application/octet-stream')->getType());
    }

    public function testAccessors(): void
    {
        $resource = new Resource('images/a.png', 'data', 'image/png');

        self::assertSame('images/a.png', $resource->getPath());
        self::assertSame('data', $resource->getData());
        self::assertSame('image/png', $resource->getMime());
    }
}
