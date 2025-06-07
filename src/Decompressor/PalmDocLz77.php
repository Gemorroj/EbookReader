<?php

declare(strict_types=1);

namespace EbookReader\Decompressor;

final class PalmDocLz77 implements DecompressorInterface
{
    /**
     * @var int[]
     */
    private array $data;
    private int $offset = 0;
    /**
     * @var int[]
     */
    private array $buffer;

    public function decompress(string $data): string
    {
        $this->data = \array_values(\unpack('C*', $data));
        $this->offset = 0;
        $this->buffer = [];

        $length = \count($this->data);
        while ($this->offset < $length) {
            $char = $this->readByte();

            if (0 === $char) {
                $this->buffer[] = $char;
            } elseif ($char <= 8) {
                $this->writeLiteral($char);
            } elseif ($char <= 0x7F) {
                $this->buffer[] = $char;
            } elseif ($char <= 0xBF) {
                $this->writeBackReference($char);
            } else {
                $this->writeSpecial($char);
            }
        }

        return \pack('C*', ...$this->buffer);
    }

    private function readByte(): int
    {
        return $this->data[$this->offset++];
    }

    private function writeLiteral(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->buffer[] = $this->readByte();
        }
    }

    private function writeBackReference(int $char): void
    {
        $next = $this->readByte();
        $distance = (($char << 8 | $next) >> 3) & 0x7FF;
        $lzLength = ($next & 0x7) + 3;

        $bufferSize = \count($this->buffer);
        for ($i = 0; $i < $lzLength; ++$i) {
            $this->buffer[] = $this->buffer[$bufferSize - $distance + $i];
        }
    }

    private function writeSpecial(int $char): void
    {
        $this->buffer[] = 32;
        $this->buffer[] = $char ^ 0x80;
    }
}
