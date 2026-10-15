<?php

declare(strict_types=1);

namespace EbookReader\Exception;

class FileNotReadableException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(string $message = 'File is not readable', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
