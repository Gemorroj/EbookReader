<?php

declare(strict_types=1);

namespace EbookReader\Text;

use EbookReader\Exception\ParserException;

interface TextDecoderInterface
{
    /**
     * Decodes raw text bytes to UTF-8.
     *
     * @param string|null $declaredCharset charset declared by the format (e.g. an
     *                                     HTML <meta> tag), when available
     *
     * @throws ParserException when the charset cannot be determined
     */
    public function toUtf8(string $bytes, ?string $declaredCharset = null): string;
}
