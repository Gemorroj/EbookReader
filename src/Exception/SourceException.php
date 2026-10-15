<?php

declare(strict_types=1);

namespace EbookReader\Exception;

class SourceException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(string $message = 'Failed to prepare the book source', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
