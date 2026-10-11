<?php

declare(strict_types=1);

namespace EbookReader\Meta;

/**
 * Publication date with per-format precision (year only, year+month or full date).
 */
final readonly class PublishDate
{
    public function __construct(
        private ?int $year = null,
        private ?int $month = null,
        private ?int $day = null,
    ) {
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function getMonth(): ?int
    {
        return $this->month;
    }

    public function getDay(): ?int
    {
        return $this->day;
    }

    public function isEmpty(): bool
    {
        return null === $this->year && null === $this->month && null === $this->day;
    }

    /**
     * Missing month/day default to 1, so a year-only date does not lose information.
     */
    public function toDateTimeImmutable(): ?\DateTimeImmutable
    {
        if (null === $this->year) {
            return null;
        }

        try {
            return new \DateTimeImmutable(
                \sprintf('%04d-%02d-%02d', $this->year, $this->month ?? 1, $this->day ?? 1),
                new \DateTimeZone('UTC'),
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
