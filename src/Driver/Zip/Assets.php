<?php

declare(strict_types=1);

namespace EbookReader\Driver\Zip;

use EbookReader\MimeDetector\MimeDetectorInterface;
use EbookReader\Model\Resource\Resource as EbookResource;
use EbookReader\Model\Resource\ResourceType;

/**
 * Collects the assets of a zipped book (images, fonts, stylesheets, ...) as
 * resources, detecting each MIME type from its bytes, falling back to the entry
 * extension for types the content detector cannot recognise.
 */
final readonly class Assets
{
    public function __construct(private MimeDetectorInterface $mimeDetector)
    {
    }

    /**
     * @param list<string> $excludeNames      exact entry names to skip
     * @param list<string> $excludeExtensions lower-case, without the dot
     *
     * @return list<EbookResource>
     */
    public function collect(Reader $zip, array $excludeNames = [], array $excludeExtensions = []): array
    {
        $resources = [];
        foreach ($zip->allEntries() as $name) {
            if (\in_array($name, $excludeNames, true)) {
                continue;
            }
            if (\in_array(\strtolower(\pathinfo($name, \PATHINFO_EXTENSION)), $excludeExtensions, true)) {
                continue;
            }
            $data = $zip->tryRead($name);
            if (null === $data) {
                continue;
            }
            $mime = $this->mimeDetector->detect($data) ?? self::extensionMime(\pathinfo($name, \PATHINFO_EXTENSION));
            if (null === $mime || ResourceType::OTHER === ResourceType::fromMime($mime)) {
                continue;
            }
            $resources[] = new EbookResource($name, $data, $mime);
        }

        return $resources;
    }

    /**
     * MIME types the content detector cannot recognise, guessed from the entry
     * extension.
     */
    private static function extensionMime(string $extension): ?string
    {
        return match (\strtolower($extension)) {
            'css' => 'text/css',
            'svg' => 'image/svg+xml',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            default => null,
        };
    }
}
