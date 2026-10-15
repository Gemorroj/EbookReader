<?php

declare(strict_types=1);

namespace EbookReader\Driver\Zip;

use EbookReader\Exception\ParserException;

/**
 * Read-only access to a book stored as a ZIP archive (EPUB-like containers,
 * Calibre HTMLZ/TXTZ, plain "txtz", ...).
 *
 * It centralises the repetitive ZipArchive handling shared by the drivers that
 * support zipped books: opening, listing entries, and reading a whole entry or
 * its leading bytes. Entries are read lazily; every method opens the archive
 * already opened by {@see self::open()}.
 */
final readonly class Reader
{
    private function __construct(
        private string $file,
        private \ZipArchive $zip,
    ) {
    }

    /**
     * Opens $file as a ZIP archive, or returns null when it is not one.
     */
    public static function open(string $file): ?self
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($file, \ZipArchive::RDONLY)) {
            return null;
        }

        return new self($file, $zip);
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /**
     * File entry names whose extension is one of $extensions, naturally sorted
     * so "chapter2" precedes "chapter10".
     *
     * @param list<string> $extensions lower-case, without the dot
     *
     * @return list<string>
     */
    public function entries(array $extensions): array
    {
        $names = [];
        foreach ($this->allEntries() as $name) {
            if (\in_array(\strtolower(\pathinfo($name, \PATHINFO_EXTENSION)), $extensions, true)) {
                $names[] = $name;
            }
        }
        \usort($names, static fn (string $a, string $b): int => \strnatcasecmp($a, $b));

        return $names;
    }

    /**
     * All file entry names in archive order; directory entries are skipped.
     *
     * @return list<string>
     */
    public function allEntries(): array
    {
        $names = [];
        for ($i = 0; $i < $this->zip->numFiles; ++$i) {
            $name = $this->zip->getNameIndex($i);
            if (false === $name || \str_ends_with($name, '/')) {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @throws ParserException when the entry is absent
     */
    public function read(string $entry): string
    {
        $data = $this->tryRead($entry);
        if (null === $data) {
            throw new ParserException();
        }

        return $data;
    }

    /**
     * @return string|null null when the entry is absent
     */
    public function tryRead(string $entry): ?string
    {
        $data = $this->zip->getFromName($entry);

        return false === $data ? null : $data;
    }

    /**
     * The leading $size bytes of $entry (fewer when the entry is shorter).
     *
     * @throws ParserException when the entry cannot be read
     */
    public function head(string $entry, int $size): string
    {
        $stream = @\fopen('zip://'.$this->file.'#'.$entry, 'r');
        if (false === $stream) {
            throw new ParserException();
        }

        try {
            $head = \fread($stream, \max(1, $size));
        } finally {
            \fclose($stream);
        }

        if (false === $head) {
            throw new ParserException();
        }

        return $head;
    }
}
