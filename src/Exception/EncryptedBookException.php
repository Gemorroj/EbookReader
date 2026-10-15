<?php

declare(strict_types=1);

namespace EbookReader\Exception;

class EncryptedBookException extends ParserException
{
    public function __construct(string $message = 'Encrypted books are not supported', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
