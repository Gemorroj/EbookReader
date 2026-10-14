<?php

declare(strict_types=1);

namespace EbookReader\Text;

use EbookReader\Exception\ParserException;

/**
 * Decodes raw text to UTF-8.
 *
 * Bytes are returned as-is when they are valid UTF-8 or carry a BOM; a charset
 * declared by the format is honored next. Only bytes that are none of the above
 * reach the configured fallback (strict by default).
 *
 * The DETECT fallback ranks candidate single-byte encodings by how plausible
 * their decoded text looks and picks the best one above a threshold, so a wrong
 * charset is not applied confidently (the idea is borrowed from
 * Gemorroj/Archive7z).
 */
final readonly class TextDecoder implements TextDecoderInterface
{
    /**
     * Candidate encodings tried by {@see TextDecoding::DETECT}, in preference order.
     */
    public const array DETECT_CANDIDATES = ['Windows-1251', 'CP866', 'KOI8-R', 'ISO-8859-5', 'Windows-1252'];

    /**
     * Minimum plausibility score for a detected encoding to be trusted.
     */
    private const float DETECT_MIN_SCORE = 0.3;

    /**
     * Detection only samples the beginning of the text; the winning encoding is
     * then applied to the whole input.
     */
    private const int DETECT_SAMPLE_SIZE = 16384;

    /**
     * @param string[]|null $detectCandidates candidate encodings for DETECT, in preference order
     */
    public function __construct(
        private TextDecoding $strategy = TextDecoding::STRICT,
        private ?string $encoding = null,
        private ?\Closure $callback = null,
        private ?array $detectCandidates = null,
    ) {
    }

    /**
     * Reject bytes that are not UTF-8 and carry no declared charset (default).
     */
    public static function strict(): self
    {
        return new self();
    }

    /**
     * Guess the charset by ranking the candidates, then decode with the winner.
     *
     * @param string[]|null $candidates encodings to try, in preference order
     *
     * @throws ParserException when a candidate is not a known encoding
     */
    public static function detect(?array $candidates = null): self
    {
        $candidates = null === $candidates || [] === $candidates ? null : \array_values($candidates);
        foreach ($candidates ?? [] as $candidate) {
            self::assertKnownEncoding($candidate);
        }

        return new self(TextDecoding::DETECT, detectCandidates: $candidates);
    }

    /**
     * Decode with a fixed encoding.
     *
     * @throws ParserException when the encoding is unknown
     */
    public static function fallback(string $encoding): self
    {
        self::assertKnownEncoding($encoding);

        return new self(TextDecoding::ENCODING, encoding: $encoding);
    }

    /**
     * Decode with a user supplied callable.
     *
     * @param callable(string): string $decode raw bytes -> UTF-8
     */
    public static function callback(callable $decode): self
    {
        return new self(TextDecoding::CALLBACK, callback: \Closure::fromCallable($decode));
    }

    /**
     * @throws ParserException when the charset cannot be determined
     */
    #[\Override]
    public function toUtf8(string $bytes, ?string $declaredCharset = null): string
    {
        if ('' === $bytes) {
            return '';
        }

        $bom = self::stripBom($bytes);
        if (null !== $bom) {
            [$encoding, $bytes] = $bom;

            return self::convert($bytes, $encoding);
        }

        if (null !== $declaredCharset) {
            return self::convert($bytes, $declaredCharset);
        }

        if (\mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        return match ($this->strategy) {
            TextDecoding::STRICT => throw new ParserException('Cannot decode text: not valid UTF-8 and no encoding declared'),
            TextDecoding::ENCODING => self::convert($bytes, self::requireString($this->encoding, 'encoding')),
            TextDecoding::CALLBACK => $this->requireCallback()($bytes),
            TextDecoding::DETECT => $this->detectEncoding($bytes),
        };
    }

    /**
     * @throws ParserException when no candidate encoding is plausible enough
     */
    private function detectEncoding(string $bytes): string
    {
        $sample = \substr($bytes, 0, self::DETECT_SAMPLE_SIZE);
        $best = null;
        $bestScore = self::DETECT_MIN_SCORE;
        foreach ($this->detectCandidates ?? self::DETECT_CANDIDATES as $encoding) {
            $score = self::score($encoding, $sample);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $encoding;
            }
        }

        if (null === $best) {
            throw new ParserException('Cannot detect text encoding: no candidate is plausible enough');
        }

        return self::convert($bytes, $best);
    }

    /**
     * Average plausibility of decoding the sample with the encoding.
     *
     * @return float 0.0..1.0, or -INF when the encoding cannot represent the bytes
     */
    private static function score(string $encoding, string $sample): float
    {
        $sum = 0.0;
        $lines = 0;
        foreach (\explode("\n", $sample) as $line) {
            if ('' === $line) {
                continue;
            }
            $decoded = self::tryConvert($line, $encoding);
            if (null === $decoded) {
                return -\INF;
            }
            $sum += self::scoreText($decoded);
            ++$lines;
        }

        return $lines > 0 ? $sum / $lines : 0.0;
    }

    /**
     * Plausibility of a decoded text: letters and digits score well, control and
     * unassigned characters score poorly.
     *
     * @return float 0.0..1.0
     */
    private static function scoreText(string $text): float
    {
        $length = \mb_strlen($text, 'UTF-8');
        if (0 === $length) {
            return 0.0;
        }

        $good = (int) \preg_match_all('/[\p{L}\p{N}]/u', $text);
        $neutral = (int) \preg_match_all('/[\p{Zs}\p{P}\p{S}]/u', $text);
        $bad = (int) \preg_match_all('/[\x{00}-\x{08}\x{0B}\x{0C}\x{0E}-\x{1F}\x{7F}-\x{9F}\x{FFFD}\p{Cn}\p{Co}\p{Cs}]/u', $text);

        return ($good + 0.5 * $neutral - 2.0 * $bad) / $length;
    }

    /**
     * @throws ParserException when the encoding is unknown or cannot represent the bytes
     */
    private static function convert(string $bytes, string $encoding): string
    {
        $converted = self::tryConvert($bytes, $encoding);
        if (null === $converted) {
            throw new ParserException(\sprintf('Cannot decode text using "%s"', $encoding));
        }

        return $converted;
    }

    /**
     * @return string|null null when the encoding is unknown or cannot represent the bytes
     */
    private static function tryConvert(string $bytes, string $encoding): ?string
    {
        try {
            $converted = \mb_convert_encoding($bytes, 'UTF-8', $encoding);
        } catch (\ValueError) {
            return null;
        }

        return false === $converted ? null : $converted;
    }

    /**
     * @return array{0: string, 1: string}|null [encoding, remaining bytes]
     */
    private static function stripBom(string $bytes): ?array
    {
        if (\str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return ['UTF-8', \substr($bytes, 3)];
        }
        if (\str_starts_with($bytes, "\xFF\xFE\x00\x00")) {
            return ['UTF-32LE', \substr($bytes, 4)];
        }
        if (\str_starts_with($bytes, "\x00\x00\xFE\xFF")) {
            return ['UTF-32BE', \substr($bytes, 4)];
        }
        if (\str_starts_with($bytes, "\xFF\xFE")) {
            return ['UTF-16LE', \substr($bytes, 2)];
        }
        if (\str_starts_with($bytes, "\xFE\xFF")) {
            return ['UTF-16BE', \substr($bytes, 2)];
        }

        return null;
    }

    /**
     * @throws ParserException when no callback is configured
     *
     * @return \Closure(string): string
     */
    private function requireCallback(): \Closure
    {
        if (null === $this->callback) {
            throw new ParserException('No fallback text decoder configured');
        }

        return $this->callback;
    }

    /**
     * @throws ParserException when the value is null
     */
    private static function requireString(?string $value, string $name): string
    {
        if (null === $value) {
            throw new ParserException(\sprintf('No fallback %s configured', $name));
        }

        return $value;
    }

    /**
     * @throws ParserException when the encoding is not supported by mbstring
     */
    private static function assertKnownEncoding(string $encoding): void
    {
        try {
            \mb_convert_encoding('', 'UTF-8', $encoding);
        } catch (\ValueError) {
            throw new ParserException(\sprintf('Unknown encoding "%s"', $encoding));
        }
    }
}
