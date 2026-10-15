<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Driver\Fb2Driver;
use EbookReader\Exception\ParserException;
use EbookReader\Model\Format;
use EbookReader\Model\Section\Fb2Epigraph;
use EbookReader\Model\Section\Fb2Section;
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

    public function testMetaSeries(): void
    {
        $meta = new Fb2Driver(__DIR__.'/../fixtures/fb2/fb2-cyrillic.fb2')->getMeta();

        self::assertSame('Классика для школьников', $meta->getSeries());
        self::assertNull($meta->getSeriesIndex());
    }

    #[DataProvider('seriesProvider')]
    public function testMetaSeriesIndex(string $sequence, ?string $expectedSeries, ?float $expectedIndex): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<FictionBook xmlns="http://www.gribuser.ru/xml/fictionbook/2.0" xmlns:l="http://www.w3.org/1999/xlink">'
            .'<description><title-info><book-title>T</book-title>'
            .'<author><first-name>A</first-name><last-name>B</last-name></author><lang>en</lang>'
            .$sequence.'</title-info></description>'
            .'<body><section><p>x</p></section></body></FictionBook>';

        $file = \tempnam(\sys_get_temp_dir(), 'fb2');
        self::assertNotFalse($file);
        \file_put_contents($file, $xml);

        try {
            $meta = new Fb2Driver($file)->getMeta();
            self::assertSame($expectedSeries, $meta->getSeries());
            self::assertSame($expectedIndex, $meta->getSeriesIndex());
        } finally {
            \unlink($file);
        }
    }

    public static function seriesProvider(): \Generator
    {
        yield 'name and number' => ['<sequence name="Saga" number="2.5"/>', 'Saga', 2.5];
        yield 'name only' => ['<sequence name="Saga"/>', 'Saga', null];
        yield 'number without name' => ['<sequence number="2"/>', null, null];
        yield 'non-numeric number' => ['<sequence name="Saga" number="abc"/>', 'Saga', null];
        yield 'absent' => ['', null, null];
    }

    public function testInlineImageResources(): void
    {
        $driver = new Fb2Driver(__DIR__.'/../fixtures/fb2/fb2-inline-image.fb2');
        self::assertTrue($driver->isValid());

        $data = $driver->getSections();
        self::assertCount(1, $data);

        $resources = $data[0]->getResources();
        self::assertCount(1, $resources);
        self::assertSame('#pic', $resources[0]->getPath());
        self::assertSame('image/png', $resources[0]->getMime());
        self::assertNotSame('', $resources[0]->getData());

        // The FB2 <image xlink:href> reference is rewritten to a plain HTML
        // <img src> that points at the resource path exposed above.
        $content = $data[0]->getContent();
        self::assertStringContainsString('<img src="#pic"/>', $content);
        self::assertStringNotContainsString('<image', $content);
        self::assertStringNotContainsString('xlink', $content);
    }

    public function testNormalizesMarkup(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<FictionBook xmlns="http://www.gribuser.ru/xml/fictionbook/2.0" xmlns:l="http://www.w3.org/1999/xlink">'
            .'<description><title-info><book-title>T</book-title>'
            .'<author><first-name>A</first-name><last-name>B</last-name></author>'
            .'<lang>en</lang></title-info></description>'
            .'<body><section id="s1"><title><p>Chapter</p></title>'
            .'<subtitle><p>Sub</p></subtitle>'
            .'<p>Hello <emphasis>world</emphasis> <a l:href="#n1" type="note">[1]</a></p>'
            .'<empty-line/><image l:href="#img"/></section></body>'
            .'<binary id="img" content-type="image/png">iVBORw0KGgo=</binary>'
            .'</FictionBook>';

        $file = \tempnam(\sys_get_temp_dir(), 'fb2');
        self::assertNotFalse($file);
        \file_put_contents($file, $xml);

        try {
            $driver = new Fb2Driver($file);
            self::assertTrue($driver->isValid());

            $sections = $driver->getSections();
            self::assertCount(1, $sections);
            self::assertSame('Chapter', $sections[0]->getTitle());
            self::assertSame(
                '<h2>Chapter</h2><h3>Sub</h3>'
                .'<p>Hello <em>world</em> <a href="#n1">[1]</a></p>'
                .'<br/><img src="#img"/>',
                $sections[0]->getContent()
            );
            self::assertSame(['#img'], \array_map(
                static fn ($resource): string => $resource->getPath(),
                $sections[0]->getResources()
            ));
        } finally {
            \unlink($file);
        }
    }

    public function testNormalizesInlineAndBlockMarkup(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<FictionBook xmlns="http://www.gribuser.ru/xml/fictionbook/2.0" xmlns:l="http://www.w3.org/1999/xlink">'
            .'<description><title-info><book-title>T</book-title>'
            .'<author><first-name>A</first-name><last-name>B</last-name></author>'
            .'<lang>en</lang></title-info></description>'
            .'<body><section id="outer"><title><p>Outer</p></title>'
            .'<p>A &amp; B &lt; C &gt; D</p>'
            .'<cite><p>quoted</p></cite>'
            .'<table><tr><td>c1</td><th>c2</th></tr></table>'
            .'<p><strong>bold</strong> <strikethrough>st</strikethrough> <sub>sub</sub> <sup>sup</sup>'
            .' <code>co</code> <style name="hl">styled</style></p>'
            .'<p><unknown>anon</unknown><image/><a>plain</a></p>'
            .'<section id="inner"><title><p>Inner</p></title>'
            .'<p>nested text</p><image l:href="#inner-img"/></section>'
            .'</section></body>'
            .'<binary id="inner-img" content-type="image/png">iVBORw0KGgo=</binary>'
            .'</FictionBook>';

        $file = \tempnam(\sys_get_temp_dir(), 'fb2');
        self::assertNotFalse($file);
        \file_put_contents($file, $xml);

        try {
            $sections = new Fb2Driver($file)->getSections();
            self::assertCount(2, $sections);

            $outer = $sections[0]->getContent();
            self::assertStringContainsString('<h2>Outer</h2>', $outer);
            self::assertStringContainsString('<p>A &amp; B &lt; C &gt; D</p>', $outer);
            self::assertStringContainsString('<blockquote><p>quoted</p></blockquote>', $outer);
            self::assertStringContainsString('<table><tr><td>c1</td><th>c2</th></tr></table>', $outer);
            self::assertStringContainsString('<strong>bold</strong>', $outer);
            self::assertStringContainsString('<s>st</s>', $outer);
            self::assertStringContainsString('<sub>sub</sub>', $outer);
            self::assertStringContainsString('<sup>sup</sup>', $outer);
            self::assertStringContainsString('<code>co</code>', $outer);
            self::assertStringContainsString('<span class="hl">styled</span>', $outer);

            // Unknown tags are unwrapped, an image without href is dropped and
            // a link without href keeps its text.
            self::assertStringContainsString('<p>anon<a>plain</a></p>', $outer);

            // A nested section is emitted on its own and must not leak into the
            // parent content (nor its resources into the parent section).
            self::assertStringNotContainsString('nested text', $outer);
            self::assertSame([], $sections[0]->getResources());

            self::assertSame('Inner', $sections[1]->getTitle());
            self::assertStringContainsString('<h2>Inner</h2><p>nested text</p><img src="#inner-img"/>', $sections[1]->getContent());
            self::assertSame(['#inner-img'], \array_map(
                static fn ($resource): string => $resource->getPath(),
                $sections[1]->getResources()
            ));
        } finally {
            \unlink($file);
        }
    }

    public static function filesProviderFake(): \Generator
    {
        yield [__DIR__.'/../fixtures/fake.xml'];
        yield [__DIR__.'/../fixtures/fake.zip'];
    }

    /**
     * @param list<Fb2Epigraph> $expectedEpigraphs
     */
    #[DataProvider('filesDataProvider')]
    public function testGetData(
        string $file,
        int $expectedCount,
        ?string $expectedTitle,
        string $expectedText,
        ?string $expectedAnnotation,
        array $expectedEpigraphs,
    ): void {
        $driver = new Fb2Driver($file);
        $data = $driver->getSections();

        self::assertCount($expectedCount, $data);

        /** @var Fb2Section $firstData */
        $firstData = $data[0];

        self::assertSame($expectedTitle, $firstData->getTitle(), $file);
        self::assertSame($expectedText, $firstData->getContent(), $file);
        self::assertSame($expectedAnnotation, $firstData->getAnnotation(), $file);
        self::assertCount(\count($expectedEpigraphs), $firstData->getEpigraphs(), $file);
        for ($i = 0, $l = \count($expectedEpigraphs); $i < $l; ++$i) {
            self::assertSame($expectedEpigraphs[$i]->getContent(), $firstData->getEpigraphs()[$i]->getContent(), $file);
            self::assertSame($expectedEpigraphs[$i]->getAuthors(), $firstData->getEpigraphs()[$i]->getAuthors(), $file);
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
            '<p>© Салтыков М.М., ил. на обл., 2021</p><p>© ООО «Издательство АСТ», 2021</p><h3>* * *</h3>',
            null,
            [],
        ];
        yield [
            __DIR__.'/../fixtures/fb2/fb2-epigraph.zip',
            381,
            null,
            '<div class="poem"><div class="stanza"><p>He мысля гордый свет забавить,</p><p>Вниманье дружбы возлюбя,</p><p>Хотел бы я тебе представить</p><p>Залог достойнее тебя,</p><p>Достойнее души прекрасной,</p><p>Святой исполненной мечты,</p><p>Поэзии живой и ясной,</p><p>Высоких дум и простоты;</p><p>Но так и быть – рукой пристрастной</p><p>Прими собранье пестрых глав,</p><p>Полусмешных, полупечальных,</p><p>Простонародных, идеальных,</p><p>Небрежный плод моих забав,</p><p>Бессонниц, легких вдохновений,</p><p>Незрелых и увядших лет,</p><p>Ума холодных наблюдений</p><p>И сердца горестных замет.</p></div></div>',
            null,
            [new Fb2Epigraph('<p>Pétri de vanité il avait encore plus de cette espèce d’orgueil qui fait avouer avec la même
                    indifférence les bonnes comme les mauvaises actions, suite d’un sentiment de supériorité, peut-être
                    imaginaire.
                </p>', ['<em>Tiré d’une lettre particulière</em><a href="#n_1">[1]</a>'])],
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
