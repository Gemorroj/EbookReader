<?php

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\Epub3Driver;
use EbookReader\Exception\ParserException;
use PHPUnit\Framework\TestCase;

class Epub3DriverTest extends TestCase
{
    /**
     * @dataProvider filesProvider
     */
    public function testIsValid(string $file): void
    {
        $result = Epub3Driver::isValid($file);
        self::assertTrue($result);
    }

    /**
     * @dataProvider filesProviderFake
     */
    public function testIsValidFake(string $file): void
    {
        $result = Epub3Driver::isValid($file);
        self::assertFalse($result);
    }

    /**
     * @dataProvider filesProvider
     */
    public function testRead(string $file, string $expectedTitle): void
    {
        $driver = new Epub3Driver($file);
        $meta = $driver->read();
        self::assertSame($expectedTitle, $meta->getTitle());
    }

    /**
     * @dataProvider filesProviderFake
     */
    public function testReadFake(string $file): void
    {
        $driver = new Epub3Driver($file);
        $this->expectException(ParserException::class);
        $driver->read();
    }

    public function filesProviderFake(): array
    {
        return [
            [__DIR__.'/../fixtures/fake.xml'],
            [__DIR__.'/../fixtures/fake.zip'],
        ];
    }

    public function filesProvider(): array
    {
        return [
            [__DIR__.'/../fixtures/epub/epub3.epub', 'The Geography of Bliss: One Grump\'s Search for the Happiest Places in the World'],
        ];
    }
}
