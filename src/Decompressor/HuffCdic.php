<?php

declare(strict_types=1);

namespace EbookReader\Decompressor;

use EbookReader\Exception\ParserException;

/**
 * HUFF/CDIC decompressor for high-compression (type 17480) MOBI files.
 *
 * Format reverse engineered by the MobileRead community (darkninja, igorsk).
 *
 * @see https://wiki.mobileread.com/wiki/MOBI#PalmDOC_Compression
 */
final class HuffCdic implements DecompressorInterface
{
    /**
     * @var array<int, array{codelen: int, term: int, maxcode: int}>
     */
    private array $dict1 = [];

    /**
     * @var array<int, int>
     */
    private array $mincode = [];

    /**
     * @var array<int, int>
     */
    private array $maxcode = [];

    /**
     * @var array<int, array{0: string, 1: int}|null>
     */
    private array $dictionary = [];

    /**
     * @var array<int, true>
     */
    private array $expanding = [];

    /**
     * @param string[] $records first record is HUFF, the rest are CDIC
     */
    public function __construct(array $records)
    {
        if (!isset($records[0])) {
            throw new ParserException();
        }

        $this->loadHuff($records[0]);
        for ($i = 1, $count = \count($records); $i < $count; ++$i) {
            $this->loadCdic($records[$i]);
        }
    }

    public function decompress(string $data): string
    {
        return $this->unpack($data);
    }

    private function loadHuff(string $huff): void
    {
        if (!\str_starts_with($huff, "HUFF\x00\x00\x00\x18")) {
            throw new ParserException();
        }

        $off1 = $this->uint32($huff, 8);
        $off2 = $this->uint32($huff, 12);

        $this->dict1 = [];
        for ($i = 0; $i < 256; ++$i) {
            $v = $this->uint32($huff, $off1 + $i * 4);
            $codelen = $v & 0x1F;
            if (0 === $codelen) {
                throw new ParserException();
            }
            $maxcode = ($v >> 8) & 0xFFFFFF;
            $this->dict1[$i] = [
                'codelen' => $codelen,
                'term' => $v & 0x80,
                'maxcode' => (($maxcode + 1) << (32 - $codelen)) - 1,
            ];
        }

        $this->mincode = [];
        $this->maxcode = [];
        for ($codelen = 0; $codelen <= 32; ++$codelen) {
            if (0 === $codelen) {
                $this->mincode[0] = 0;
                $this->maxcode[0] = 0;
                continue;
            }

            $min = $this->uint32($huff, $off2 + ($codelen - 1) * 8);
            $max = $this->uint32($huff, $off2 + ($codelen - 1) * 8 + 4);
            $this->mincode[$codelen] = $min << (32 - $codelen);
            $this->maxcode[$codelen] = (($max + 1) << (32 - $codelen)) - 1;
        }
    }

    private function loadCdic(string $cdic): void
    {
        if (!\str_starts_with($cdic, "CDIC\x00\x00\x00\x10")) {
            throw new ParserException();
        }

        $phrases = $this->uint32($cdic, 8);
        $bits = $this->uint32($cdic, 12);
        if ($bits > 31) {
            throw new ParserException();
        }

        $n = \min(1 << $bits, $phrases - \count($this->dictionary));
        for ($i = 0; $i < $n; ++$i) {
            $off = $this->uint16($cdic, 16 + $i * 2);
            $blen = $this->uint16($cdic, 16 + $off);
            $slice = \substr($cdic, 18 + $off, $blen & 0x7FFF);
            $this->dictionary[] = [$slice, $blen & 0x8000];
        }
    }

    private function unpack(string $data): string
    {
        $length = \strlen($data);
        if (0 === $length) {
            return '';
        }

        $data .= "\x00\x00\x00\x00\x00\x00\x00\x00";
        $bitsleft = $length * 8;
        $bitpos = 0;
        $out = '';

        while (true) {
            $code = $this->window($data, $bitpos);

            $entry = $this->dict1[$code >> 24];
            $codelen = $entry['codelen'];
            $maxcode = $entry['maxcode'];
            if (0 === $entry['term']) {
                while ($code < $this->mincode[$codelen]) {
                    if (++$codelen > 32) {
                        throw new ParserException();
                    }
                }
                $maxcode = $this->maxcode[$codelen];
            }

            $bitpos += $codelen;
            $bitsleft -= $codelen;
            if ($bitsleft < 0) {
                break;
            }

            $r = ($maxcode - $code) >> (32 - $codelen);
            if (!isset($this->dictionary[$r])) {
                throw new ParserException();
            }

            [$slice, $flag] = $this->dictionary[$r];
            if (0 === $flag) {
                if (isset($this->expanding[$r])) {
                    throw new ParserException();
                }
                $this->expanding[$r] = true;
                $slice = $this->unpack($slice);
                unset($this->expanding[$r]);
                $this->dictionary[$r] = [$slice, 1];
            }

            $out .= $slice;
        }

        return $out;
    }

    /**
     * Reads the 32-bit MSB-first window starting at $bitpos.
     */
    private function window(string $data, int $bitpos): int
    {
        $byte = $bitpos >> 3;
        $offset = $bitpos & 7;

        $value = (\ord($data[$byte]) << 32)
            | (\ord($data[$byte + 1]) << 24)
            | (\ord($data[$byte + 2]) << 16)
            | (\ord($data[$byte + 3]) << 8)
            | \ord($data[$byte + 4]);

        return ($value >> (8 - $offset)) & 0xFFFFFFFF;
    }

    private function uint16(string $data, int $offset): int
    {
        $value = \unpack('n', \substr($data, $offset, 2));
        if (false === $value) {
            throw new ParserException();
        }

        return $value[1];
    }

    private function uint32(string $data, int $offset): int
    {
        $value = \unpack('N', \substr($data, $offset, 4));
        if (false === $value) {
            throw new ParserException();
        }

        return $value[1];
    }
}
