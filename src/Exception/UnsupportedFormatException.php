<?php

declare(strict_types=1);

namespace EbookReader\Exception;

class UnsupportedFormatException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(string $message = 'Unsupported book format', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
