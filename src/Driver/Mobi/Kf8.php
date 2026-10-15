<?php

declare(strict_types=1);

namespace EbookReader\Driver\Mobi;

use EbookReader\Exception\ParserException;

/**
 * KF8 (AZW3) content reconstruction.
 *
 * KF8 keeps the MOBI/Palm container but stores text differently: the raw text
 * stream is split into "flows" by the FDST record. The first flow holds a set
 * of HTML skeletons followed by the content fragments; the SKEL index locates
 * each skeleton and the DIV index locates each fragment together with the
 * offset at which it is inserted back into its skeleton.
 *
 * Format reverse engineered by the MobileRead community (KindleUnpack, calibre).
 *
 * @see https://wiki.mobileread.com/wiki/KF8
 */
final class Kf8
{
    private function __construct()
    {
    }

    /**
     * Parses the FDST record: the start/end offsets of every text flow.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function flowTable(string $record): array
    {
        if (!\str_starts_with($record, 'FDST')) {
            return [];
        }

        $tableOffset = self::uint32($record, 4);
        $count = self::uint32($record, 8);

        $flows = [];
        for ($i = 0; $i < $count; ++$i) {
            $start = self::uint32($record, $tableOffset + $i * 8);
            $end = self::uint32($record, $tableOffset + $i * 8 + 4);
            if ($end < $start) {
                throw new ParserException();
            }
            $flows[] = [$start, $end];
        }

        return $flows;
    }

    /**
     * @param list<array{0: int, 1: int}> $table
     *
     * @return list<string>
     */
    public static function splitFlows(string $text, array $table): array
    {
        if (!$table) {
            return [$text];
        }

        $flows = [];
        foreach ($table as [$start, $end]) {
            $flows[] = \substr($text, $start, $end - $start);
        }

        return $flows;
    }

    /**
     * Merges the skeleton and fragment indexes back into standalone documents.
     *
     * @param list<array{0: string, 1: array<int, list<int>>}> $skeletons
     * @param list<array{0: string, 1: array<int, list<int>>}> $fragments
     *
     * @return list<array{name: string, html: string, start: int, end: int}>
     */
    public static function buildParts(string $text, array $skeletons, array $fragments): array
    {
        $parts = [];
        $fragmentPointer = 0;

        foreach ($skeletons as [$name, $tags]) {
            $fragmentCount = $tags[1][0] ?? 0;
            $start = $tags[6][0] ?? 0;
            $length = $tags[6][1] ?? 0;
            $base = $start + $length;
            $html = \substr($text, $start, $length);

            for ($i = 0; $i < $fragmentCount; ++$i) {
                if (!isset($fragments[$fragmentPointer])) {
                    break;
                }
                [$identifier, $fragmentTags] = $fragments[$fragmentPointer];
                $insertAt = (int) $identifier - $start;
                $fragmentLength = $fragmentTags[6][1] ?? 0;
                $fragment = \substr($text, $base, $fragmentLength);

                if ($insertAt < 0 || $insertAt > \strlen($html)) {
                    $insertAt = \strlen($html);
                }
                $html = \substr($html, 0, $insertAt).$fragment.\substr($html, $insertAt);

                $base += $fragmentLength;
                ++$fragmentPointer;
            }

            $parts[] = ['name' => $name, 'html' => $html, 'start' => $start, 'end' => $base];
        }

        return $parts;
    }

    private static function uint32(string $data, int $offset): int
    {
        $value = \unpack('N', \substr($data, $offset, 4));
        if (false === $value) {
            throw new ParserException();
        }

        return $value[1];
    }
}
