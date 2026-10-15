<?php

declare(strict_types=1);

namespace EbookReader\Driver\Zip;

use EbookReader\MimeDetector\MimeDetectorInterface;
use EbookReader\Model\Cover\Cover;

/**
 * Finds the cover image of a zipped book: the entry declared by the
 * `metadata.opf` sidecar first, then an entry whose basename is one of the
 * conventional names, taking the first that looks like an image.
 */
final readonly class CoverFinder
{
    public function __construct(private MimeDetectorInterface $mimeDetector)
    {
    }

    /**
     * @param list<string> $filenames candidate basenames, without the extension
     */
    public function find(Reader $zip, array $filenames): ?Cover
    {
        $names = [];
        $coverHref = new Metadata($zip)->coverHref();
        if (null !== $coverHref) {
            $names[] = $coverHref;
        }
        foreach ($zip->allEntries() as $name) {
            if (\in_array(\pathinfo($name, \PATHINFO_FILENAME), $filenames, true)) {
                $names[] = $name;
            }
        }

        foreach ($names as $name) {
            $data = $zip->tryRead($name);
            if (null === $data) {
                continue;
            }
            $mime = $this->mimeDetector->detect($data);
            if ($mime) {
                return new Cover($data, $mime);
            }
        }

        return null;
    }
}
