<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver;

use EbookReader\Data\Fb2Data;
use EbookReader\Data\Fb2DataEpigraph;
use EbookReader\Driver\Fb2Driver;
use EbookReader\Exception\ParserException;
use EbookReader\Resource\Style;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Fb2DriverTest extends TestCase
{
    #[DataProvider('filesProvider')]
    public function testIsValid(string $file): void
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
        self::assertSame($expectedPublishYear, $meta->getPublishYear());
        self::assertSame($expectedPublishMonth, $meta->getPublishMonth());
        self::assertSame($expectedPublishDay, $meta->getPublishDay());
    }

    #[DataProvider('filesProviderFake')]
    public function testGetMetaFake(string $file): void
    {
        $driver = new Fb2Driver($file);
        $this->expectException(ParserException::class);
        $driver->getMeta();
    }

    /**
     * @return string[][]
     */
    public static function filesProviderFake(): array
    {
        return [
            [__DIR__.'/../fixtures/fake.xml'],
            [__DIR__.'/../fixtures/fake.zip'],
        ];
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

    public static function filesDataProvider(): array
    {
        return [
            [__DIR__.'/../fixtures/fb2/fb2.fb2',
                3,
                null,
                [],
                '<p>Copyright © 2008 by Eric Weiner</p><p>All rights reserved. Except as permitted under the U.S. Copyright Act of 1976, no part of this publication may be reproduced, distributed, or transmitted in any form or by any means, or stored in a database or retrieval system, without the prior written permission of the publisher.</p><p>Twelve</p><p>Hachette Book Group USA</p><p>237 Park Avenue</p><p>New York, NY 10017</p><p>Visit our Web site at www.HachetteBookGroupUSA.com.</p><p>Twelve is an imprint of Grand Central Publishing.</p><p>The Twelve name and logo is a trademark of Hachette Book Group USA, Inc.</p><p>First eBook Edition: January 2008</p><p>ISBN-13: 978-0-446-51107-0</p>',
                null,
                [],
            ],
            [__DIR__.'/../fixtures/fb2/fb2.zip',
                3,
                null,
                [],
                '<p>Copyright © 2008 by Eric Weiner</p><p>All rights reserved. Except as permitted under the U.S. Copyright Act of 1976, no part of this publication may be reproduced, distributed, or transmitted in any form or by any means, or stored in a database or retrieval system, without the prior written permission of the publisher.</p><p>Twelve</p><p>Hachette Book Group USA</p><p>237 Park Avenue</p><p>New York, NY 10017</p><p>Visit our Web site at www.HachetteBookGroupUSA.com.</p><p>Twelve is an imprint of Grand Central Publishing.</p><p>The Twelve name and logo is a trademark of Hachette Book Group USA, Inc.</p><p>First eBook Edition: January 2008</p><p>ISBN-13: 978-0-446-51107-0</p>',
                null,
                [],
            ],
            [__DIR__.'/../fixtures/fb2/mayakovskiy.fb2',
                42,
                null,
                [],
                '<p>© Салтыков М.М., ил. на обл., 2021</p><p>© ООО «Издательство АСТ», 2021</p>',
                null,
                [],
            ],
            [__DIR__.'/../fixtures/fb2/evgeniy-onegin.zip',
                381,
                null,
                [],
                '<p>He мысля гордый свет забавить,</p><p>Вниманье дружбы возлюбя,</p><p>Хотел бы я тебе представить</p><p>Залог достойнее тебя,</p><p>Достойнее души прекрасной,</p><p>Святой исполненной мечты,</p><p>Поэзии живой и ясной,</p><p>Высоких дум и простоты;</p><p>Но так и быть – рукой пристрастной</p><p>Прими собранье пестрых глав,</p><p>Полусмешных, полупечальных,</p><p>Простонародных, идеальных,</p><p>Небрежный плод моих забав,</p><p>Бессонниц, легких вдохновений,</p><p>Незрелых и увядших лет,</p><p>Ума холодных наблюдений</p><p>И сердца горестных замет.</p>',
                null,
                [new Fb2DataEpigraph('<p>Pétri de vanité il avait encore plus de cette espèce d’orgueil qui fait avouer avec la même
                    indifférence les bonnes comme les mauvaises actions, suite d’un sentiment de supériorité, peut-être
                    imaginaire.
                </p>', ['<emphasis>Tiré d’une lettre particulière</emphasis>'])],
            ],
        ];
    }

    public static function filesProvider(): array
    {
        return [
            [__DIR__.'/../fixtures/fb2/fb2.fb2',
                'The Geography of Bliss: One Grump\'s Search for the Happiest Places in the World', // title
                'Eric Weiner', // author
                'Twelve', // publisher
                '9780446511070', // isbn
                null, // description
                'en', // language
                null, // license
                2008, // publish year
                null, // publish month
                null, // publish day
            ],
            [__DIR__.'/../fixtures/fb2/fb2.zip',
                'The Geography of Bliss: One Grump\'s Search for the Happiest Places in the World', // title
                'Eric Weiner', // author
                'Twelve', // publisher
                '9780446511070', // isbn
                null, // description
                'en', // language
                null, // license
                2008, // publish year
                null, // publish month
                null, // publish day
            ],
            [__DIR__.'/../fixtures/fb2/mayakovskiy.fb2',
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
            ],
        ];
    }
}
