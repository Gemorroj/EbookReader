<?php

declare(strict_types=1);

namespace EbookReader\Exception;

class UnsupportedCompressionException extends ParserException
{
    public function __construct(string $message = 'Unsupported compression', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
