<?php

declare(strict_types=1);

namespace EbookReader\Exception;

class ParserException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(string $message = 'Failed to parse the book', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
