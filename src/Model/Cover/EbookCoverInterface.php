<?php

declare(strict_types=1);

namespace EbookReader\Model\Cover;

/**
 * The book cover image.
 *
 * The interface allows alternative implementations (e.g. a lazily loaded cover)
 * to be returned by EbookDriverInterface::getCover().
 */
interface EbookCoverInterface
{
    public function getData(): string;

    public function getMime(): string;
}
