<?php

declare(strict_types=1);

namespace EbookReader\Model\Section;

/**
 * KF8 (AZW3) content shares the MOBI content model, so the section type is a
 * thin specialization of {@see MobiSection}.
 */
final readonly class Azw3Section extends MobiSection
{
}
