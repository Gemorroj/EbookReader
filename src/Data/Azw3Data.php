<?php

declare(strict_types=1);

namespace EbookReader\Data;

/**
 * KF8 (AZW3) content shares the MOBI content model: HTML plus inline
 * resources, so the section type is a thin specialization of {@see MobiData}.
 */
final readonly class Azw3Data extends MobiData
{
}
