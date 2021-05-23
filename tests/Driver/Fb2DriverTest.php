<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\Fb2Driver;
use EbookReader\Exception\ParserException;
use PHPUnit\Framework\TestCase;

class Fb2DriverTest extends TestCase
{
    /**
     * @dataProvider filesProvider
     */
    public function testIsValid(string $file): void
    {
        $driver = new Fb2Driver($file);
        $result = $driver->isValid();
        self::assertTrue($result);
    }

    /**
     * @dataProvider filesProviderFake
     */
    public function testIsValidFake(string $file): void
    {
        $driver = new Fb2Driver($file);
        $result = $driver->isValid();
        self::assertFalse($result);
    }

    /**
     * @dataProvider filesProvider
     */
    public function testGetMeta(string $file, string $expectedTitle, ?string $expectedAuthor): void
    {
        $driver = new Fb2Driver($file);
        $meta = $driver->getMeta();
        self::assertSame($expectedTitle, $meta->getTitle());
        self::assertSame($expectedAuthor, $meta->getAuthor());
    }

    /**
     * @dataProvider filesProviderFake
     */
    public function testGetMetaFake(string $file): void
    {
        $driver = new Fb2Driver($file);
        $this->expectException(ParserException::class);
        $driver->getMeta();
    }

    /**
     * @return string[][]
     */
    public function filesProviderFake(): array
    {
        return [
            [__DIR__.'/../fixtures/fake.xml'],
            [__DIR__.'/../fixtures/fake.zip'],
        ];
    }

    /**
     * @return string[][]
     */
    public function filesProvider(): array
    {
        return [
            [__DIR__.'/../fixtures/fb2/fb2.fb2',
                'The Geography of Bliss: One Grump\'s Search for the Happiest Places in the World',
                'Eric Weiner',
            ],
            [__DIR__.'/../fixtures/fb2/fb2.zip',
                'The Geography of Bliss: One Grump\'s Search for the Happiest Places in the World',
                'Eric Weiner',
            ],
        ];
    }
}
