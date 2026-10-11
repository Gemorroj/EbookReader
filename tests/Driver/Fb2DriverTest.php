<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Data\Fb2Data;
use EbookReader\Data\Fb2DataEpigraph;
use EbookReader\Driver\Fb2Driver;
use EbookReader\Exception\ParserException;
use EbookReader\Format;
use EbookReader\Resource\Style;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Fb2DriverTest extends TestCase
{
    public function testGetFormat(): void
    {
        self::assertSame(Format::FB2, new Fb2Driver('file.fb2')->getFormat());
    }

    #[DataProvider('filesProvider')]
    public function testIsValid(string $file, mixed ...$args): void
    {
        $driver = new Fb2Driver($file);
        $result = $driver->isValid();
        self::assertTrue($result);
    }

    #[DataProvider('filesProviderFake')]
    public function testIsValidFake(string $file): void
    {
        $driver = new Fb2Driver($file);
        $result = $driver->isValid();
        self::assertFalse($result);
    }

    #[DataProvider('filesProvider')]
    public function testGetMeta(
        string $file,
        string $expectedTitle,
        ?string $expectedAuthor,
        ?string $expectedPublisher,
        ?string $expectedIsbn,
        ?string $expectedDescription,
        ?string $expectedLanguage,
        ?string $expectedLicense,
        ?int $expectedPublishYear,
        ?int $expectedPublishMonth,
        ?int $expectedPublishDay
    ): void {
        $driver = new Fb2Driver($file);
        $meta = $driver->getMeta();
        self::assertSame($expectedTitle, $meta->getTitle());
        self::assertSame($expectedAuthor, $meta->getAuthor());
        self::assertSame($expectedPublisher, $meta->getPublisher());
        self::assertSame($expectedIsbn, $meta->getIsbn());
        self::assertSame($expectedDescription, $meta->getDescription());
        self::assertSame($expectedLanguage, $meta->getLanguage());
        self::assertSame($expectedLicense, $meta->getLicense());
        $publishDate = $meta->getPublishDate();
        self::assertSame($expectedPublishYear, $publishDate->getYear());
        self::assertSame($expectedPublishMonth, $publishDate->getMonth());
        self::assertSame($expectedPublishDay, $publishDate->getDay());
    }

    #[DataProvider('filesProviderFake')]
    public function testGetMetaFake(string $file): void
    {
        $driver = new Fb2Driver($file);
        $this->expectException(ParserException::class);
        $driver->getMeta();
    }

    public function testGetMetaEdgeCases(): void
    {
        $driver = new Fb2Driver(__DIR__.'/../fixtures/fb2/fb2-edge-meta.fb2');
        self::assertTrue($driver->isValid());

        $meta = $driver->getMeta();
        self::assertSame('Edge Cases', $meta->getTitle());
        self::assertNull($meta->getAuthor());
        self::assertNull($meta->getPublisher());
        self::assertNull($meta->getIsbn());
        self::assertTrue($meta->getPublishDate()->isEmpty());
    }

    public function testGetCoverEdgeCases(): void
    {
        $driver = new Fb2Driver(__DIR__.'/../fixtures/fb2/fb2-edge-meta.fb2');
        self::assertNull($driver->getCover());
    }

    public function testTableOfContents(): void
    {
        $contents = new Fb2Driver(__DIR__.'/../fixtures/fb2/fb2-epigraph.zip')->getTableOfContents();

        self::assertNotEmpty($contents);
        self::assertSame('Глава первая', $contents[1]->getTitle());
        self::assertSame(1, $contents[1]->getLevel());
        self::assertSame(2, $contents[2]->getLevel());
        self::assertNull($contents[0]->getSectionIndex());
        self::assertSame(1, $contents[1]->getSectionIndex());
    }

    public function testMetaAuthorsAndSubjects(): void
    {
        $meta = new Fb2Driver(__DIR__.'/../fixtures/fb2/fb2-cyrillic.fb2')->getMeta();

        self::assertSame(['Владимир Владимирович Маяковский'], $meta->getAuthors());
        self::assertSame(['literature_20', 'prose_su_classics', 'poetry'], $meta->getSubjects());
    }

    public function testInlineImageResources(): void
    {
        $driver = new Fb2Driver(__DIR__.'/../fixtures/fb2/fb2-inline-image.fb2');
        self::assertTrue($driver->isValid());

        $data = $driver->getData();
        self::assertCount(1, $data);

        $resources = $data[0]->getResources();
        self::assertCount(1, $resources);
        self::assertSame('#pic', $resources[0]->getPath());
        self::assertSame('image/png', $resources[0]->getMime());
        self::assertNotSame('', $resources[0]->getData());
    }

    public static function filesProviderFake(): \Generator
    {
        yield [__DIR__.'/../fixtures/fake.xml'];
        yield [__DIR__.'/../fixtures/fake.zip'];
    }

    /**
     * @param Style[]           $expectedStyles
     * @param Fb2DataEpigraph[] $expectedEpigraphs
     */
    #[DataProvider('filesDataProvider')]
    public function testGetData(
        string $file,
        int $expectedCount,
        ?string $expectedTitle,
        array $expectedStyles,
        string $expectedText,
        ?string $expectedAnnotation,
        array $expectedEpigraphs,
    ): void {
        $driver = new Fb2Driver($file);
        $data = $driver->getData();

        self::assertCount($expectedCount, $data);

        /** @var Fb2Data $firstData */
        $firstData = $data[0];

        self::assertSame($expectedTitle, $firstData->getTitle(), $file);
        self::assertSame($expectedText, $firstData->getText(), $file);
        self::assertCount(\count($expectedStyles), $firstData->getStyles(), $file);
        foreach ($expectedStyles as $expectedStyle) {
            $styleExists = false;
            foreach ($firstData->getStyles() as $style) {
                if ($style->getType() === $expectedStyle->getType() && $style->getData() === $expectedStyle->getData()) {
                    $styleExists = true;
                    break;
                }
            }

            self::assertTrue($styleExists, 'Not found expected style');
        }

        self::assertSame($expectedAnnotation, $firstData->getAnnotation(), $file);
        self::assertCount(\count($expectedEpigraphs), $firstData->getEpigraphs(), $file);
        for ($i = 0, $l = \count($expectedEpigraphs); $i < $l; ++$i) {
            self::assertSame($expectedEpigraphs[$i]->getText(), $firstData->getEpigraphs()[$i]->getText(), $file);
            self::assertSame($expectedEpigraphs[$i]->getAuthor(), $firstData->getEpigraphs()[$i]->getAuthor(), $file);
        }
    }

    #[DataProvider('filesCoverProvider')]
    public function testGetCover(
        string $file,
        ?string $expectedMime,
    ): void {
        $driver = new Fb2Driver($file);
        $cover = $driver->getCover();

        if ($expectedMime) {
            self::assertSame($expectedMime, $cover->getMime());
            self::assertNotEmpty($cover->getData());
        } else {
            self::assertNull($cover);
        }
    }

    public static function filesCoverProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/fb2/fb2-epigraph.zip',
            'image/jpeg',
        ];
        yield [
            __DIR__.'/../fixtures/fb2/fb2-cyrillic.fb2',
            'image/jpeg',
        ];
    }

    public static function filesDataProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/fb2/fb2-cyrillic.fb2',
            42,
            null,
            [],
            '<p>© Салтыков М.М., ил. на обл., 2021</p><p>© ООО «Издательство АСТ», 2021</p>',
            null,
            [],
        ];
        yield [
            __DIR__.'/../fixtures/fb2/fb2-epigraph.zip',
            381,
            null,
            [],
            '<p>He мысля гордый свет забавить,</p><p>Вниманье дружбы возлюбя,</p><p>Хотел бы я тебе представить</p><p>Залог достойнее тебя,</p><p>Достойнее души прекрасной,</p><p>Святой исполненной мечты,</p><p>Поэзии живой и ясной,</p><p>Высоких дум и простоты;</p><p>Но так и быть – рукой пристрастной</p><p>Прими собранье пестрых глав,</p><p>Полусмешных, полупечальных,</p><p>Простонародных, идеальных,</p><p>Небрежный плод моих забав,</p><p>Бессонниц, легких вдохновений,</p><p>Незрелых и увядших лет,</p><p>Ума холодных наблюдений</p><p>И сердца горестных замет.</p>',
            null,
            [new Fb2DataEpigraph('<p>Pétri de vanité il avait encore plus de cette espèce d’orgueil qui fait avouer avec la même
                    indifférence les bonnes comme les mauvaises actions, suite d’un sentiment de supériorité, peut-être
                    imaginaire.
                </p>', ['<emphasis>Tiré d’une lettre particulière</emphasis>'])],
        ];
    }

    public static function filesProvider(): \Generator
    {
        yield [
            __DIR__.'/../fixtures/fb2/fb2-cyrillic.fb2',
            'Во весь голос. Стихотворения и поэмы', // title
            'Владимир Владимирович Маяковский', // author
            'АСТ', // publisher
            '978-5-17-136765-7, 978-5-17-136763-3', // isbn
            "<p>Владимир Владимирович Маяковский (1893–1930)\u{a0}– один из крупнейших советских поэтов, новаторское творчество которого имело огромное значение для всей поэзии ХХ века.</p><p>На формирование мировоззрения поэта особенно повлияли демократическая атмосфера, царившая в семье, и первая русская революция. В. Маяковский решил посвятить своё творчество борьбе с существовавшим тогда строем.</p><p>Главной задачей поэт считал – с помощью своих стихов приближать будущее. Назначение поэта – нести свет людям: свет звёзд и свет солнца («Послушайте!», «Необычайное происшествие…»).</p><p>Маяковский – оратор и лирик. Его агитационные и лирические стихи обращены к широким массам и лично к каждому. Поэту необходим задушевный разговор с читателем. («А вы могли бы?», «Скрипка и немножко нервно»).</p><p>В сборник вошли стихотворения и поэмы, написанные в разные годы жизни поэта.</p>", // description
            'ru', // language
            null, // license
            2021, // publish year
            null, // publish month
            null, // publish day
        ];
    }
}
