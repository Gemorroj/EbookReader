<?php

declare(strict_types=1);

namespace EbookReader\Source;

/**
 * A book to read, independent of where its bytes come from.
 *
 * Every driver needs random access to the content (ZipArchive, SplFileObject,
 * the `zip://` stream wrapper), so a source always resolves to a local
 * filesystem path; in-memory content is materialised into a temporary file that
 * is removed when the source is released.
 *
 * A source must stay open for as long as the book is read: drivers open the
 * underlying file lazily in every method, not only when they are constructed.
 */
interface BookSource
{
    /**
     * Path to the book on the local filesystem.
     */
    public function getPath(): string;

    /**
     * The leading $length bytes of the book, or fewer when the book is shorter.
     */
    public function getHead(int $length): string;

    /**
     * Releases the underlying resources (removes a temporary file).
     */
    public function close(): void;
}
