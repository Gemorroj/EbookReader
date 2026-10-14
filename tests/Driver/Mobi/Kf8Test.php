<?php

declare(strict_types=1);

namespace EbookReader\Tests\Driver\Mobi;

use EbookReader\Driver\Mobi\Kf8;
use EbookReader\Exception\ParserException;
use PHPUnit\Framework\TestCase;

final class Kf8Test extends TestCase
{
    public function testFlowTableRejectsNonFdst(): void
    {
        self::assertSame([], Kf8::flowTable('USERDATA'));
    }

    public function testFlowTable(): void
    {
        $record = 'FDST'.\pack('N', 12).\pack('N', 2)
            .\pack('N', 0).\pack('N', 10)
            .\pack('N', 10).\pack('N', 20);

        self::assertSame([[0, 10], [10, 20]], Kf8::flowTable($record));
    }

    public function testFlowTableRejectsReversedRange(): void
    {
        $this->expectException(ParserException::class);

        Kf8::flowTable('FDST'.\pack('N', 12).\pack('N', 1).\pack('N', 10).\pack('N', 5));
    }

    public function testSplitFlowsWithoutTable(): void
    {
        self::assertSame(['abc'], Kf8::splitFlows('abc', []));
    }

    public function testSplitFlows(): void
    {
        self::assertSame(['ab', 'cd'], Kf8::splitFlows('abcd', [[0, 2], [2, 4]]));
    }

    public function testBuildPartsMergesFragments(): void
    {
        $text = 'AB<X>ZZCD';
        $skeletons = [
            ['A0', [1 => [1], 6 => [0, 5]]],
            ['A1', [1 => [0], 6 => [7, 2]]],
        ];
        $fragments = [
            ['2', [6 => [0, 2]]],
        ];

        $parts = Kf8::buildParts($text, $skeletons, $fragments);

        self::assertSame('ABZZ<X>', $parts[0]['html']);
        self::assertSame('CD', $parts[1]['html']);
    }

    public function testBuildPartsClampsInsertPosition(): void
    {
        $text = 'AB<X>Z';
        $skeletons = [['A0', [1 => [1], 6 => [0, 5]]]];
        $fragments = [['999', [6 => [0, 1]]]];

        $parts = Kf8::buildParts($text, $skeletons, $fragments);

        self::assertSame('AB<X>Z', $parts[0]['html']);
    }

    public function testBuildPartsHandlesExhaustedFragments(): void
    {
        $skeletons = [['A0', [1 => [2], 6 => [0, 2]]]];

        $parts = Kf8::buildParts('AB', $skeletons, []);

        self::assertSame('AB', $parts[0]['html']);
    }
}
