<?php

declare(strict_types=1);

namespace EbookReader\Text;

use EbookReader\Exception\ParserException;

/**
 * Decodes raw text to UTF-8.
 *
 * Bytes are returned as-is when they are valid UTF-8 or carry a BOM; a charset
 * declared by the format is honored next. Only bytes that are none of the above
 * reach the configured fallback (detect by default).
 *
 * The DETECT fallback decodes the sample with each candidate encoding and keeps
 * the most plausible one: it rewards a single dominant script, a letter
 * frequency profile that matches the script's language, and a clean multi-byte
 * decode, while rejecting binary and mixed-script decodes. Bytes that nothing
 * explains confidently are refused rather than guessed.
 * (the idea is borrowed from Gemorroj/Archive7z).
 */
final readonly class TextDecoder implements TextDecoderInterface
{
    /**
     * Candidate encodings tried by {@see TextDecoding::DETECT}, in preference order.
     * The plausibility score discriminates them, so the order only breaks ties.
     *
     * GB18030 is the only CJK candidate: it is a strict superset that can encode
     * the whole repertoire, while Big5/Shift_JIS/EUC cannot be told apart from it
     * (and from each other) by byte structure alone. Declare those explicitly if
     * needed.
     *
     * @var list<string>
     */
    public const array DETECT_CANDIDATES = [
        'Windows-1251',
        'KOI8-R',
        'CP866',
        'ISO-8859-5',
        'Windows-1252',
        'ISO-8859-1',
        'ISO-8859-15',
        'Windows-1250',
        'ISO-8859-2',
        'Windows-1253',
        'Windows-1254',
        'Windows-1255',
        'Windows-1256',
        'Windows-1257',
        'GB18030',
    ];

    /**
     * Minimum plausibility score for a detected encoding to be trusted.
     */
    private const float DETECT_MIN_SCORE = 0.45;

    /**
     * Detection only samples the beginning of the text; the winning encoding is
     * then applied to the whole input.
     */
    private const int DETECT_SAMPLE_SIZE = 16384;

    /**
     * Scripts considered when scoring a decode, as Unicode property names.
     *
     * @var list<string>
     */
    private const array SCRIPTS = ['Latin', 'Cyrillic', 'Greek', 'Hebrew', 'Arabic', 'Han', 'Hiragana', 'Katakana', 'Hangul', 'Thai'];

    /**
     * Multi-byte encodings: a decode that stays valid on high-byte-rich input is
     * a strong signal, since a single-byte guess would win on letter-likeness.
     *
     * @var list<string>
     */
    private const array MULTIBYTE_ENCODINGS = ['GB18030'];

    /**
     * Letter frequency profiles (lowercased) used to tell apart encodings that
     * decode to the same script, e.g. the Cyrillic single-byte family.
     *
     * @var array<string, array<string, float>>
     */
    private const array SCRIPT_FREQUENCIES = [
        'Cyrillic' => [
            'о' => 0.1097, 'е' => 0.0845, 'а' => 0.0801, 'и' => 0.0735, 'н' => 0.0670, 'т' => 0.0626,
            'с' => 0.0547, 'р' => 0.0473, 'в' => 0.0454, 'л' => 0.0440, 'к' => 0.0349, 'м' => 0.0321,
            'д' => 0.0298, 'п' => 0.0281, 'у' => 0.0262, 'я' => 0.0201, 'ы' => 0.0190, 'ь' => 0.0174,
            'г' => 0.0170, 'з' => 0.0165, 'б' => 0.0159, 'ч' => 0.0144, 'й' => 0.0121, 'х' => 0.0097,
            'ж' => 0.0094, 'ш' => 0.0073, 'ю' => 0.0064, 'ц' => 0.0048, 'щ' => 0.0036, 'э' => 0.0032,
            'ф' => 0.0026, 'ъ' => 0.0004, 'ё' => 0.0004,
        ],
        'Latin' => [
            'e' => 0.12702, 't' => 0.09056, 'a' => 0.08167, 'o' => 0.07507, 'i' => 0.06966, 'n' => 0.06749,
            's' => 0.06327, 'h' => 0.06094, 'r' => 0.05987, 'd' => 0.04253, 'l' => 0.04025, 'c' => 0.02782,
            'u' => 0.02758, 'm' => 0.02406, 'w' => 0.02360, 'f' => 0.02228, 'g' => 0.02015, 'y' => 0.01974,
            'p' => 0.01929, 'b' => 0.01492, 'v' => 0.00978, 'k' => 0.00772, 'j' => 0.00153, 'x' => 0.00150,
            'q' => 0.00095, 'z' => 0.00074,
        ],
    ];

    /**
     * @param list<string>|null $detectCandidates candidate encodings for DETECT, in preference order
     */
    public function __construct(
        private TextDecoding $strategy = TextDecoding::DETECT,
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
        return new self(TextDecoding::STRICT);
    }

    /**
     * Guess the charset by ranking the candidates, then decode with the winner.
     *
     * @param list<string>|null $candidates encodings to try, in preference order
     *
     * @throws ParserException when a candidate is not a known encoding
     */
    public static function detect(?array $candidates = null): self
    {
        $candidates = [] === $candidates ? null : $candidates;
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
     * Plausibility (0.0..1.0) of decoding the sample with the encoding, or -INF
     * when the result is binary, unreadable or otherwise not text.
     */
    private static function score(string $encoding, string $sample): float
    {
        $decoded = self::tryConvert($sample, $encoding);
        if (null === $decoded) {
            return -\INF;
        }

        $length = \mb_strlen($decoded, 'UTF-8');
        if (0 === $length) {
            return -\INF;
        }

        // Reject binary-ish decodes: control and unassigned/replacement characters.
        $control = (int) \preg_match_all('/[\x{00}-\x{08}\x{0B}\x{0C}\x{0E}-\x{1F}\x{7F}-\x{9F}]/u', $decoded);
        $broken = (int) \preg_match_all('/[\x{FFFD}\p{Cn}\p{Co}\p{Cs}]/u', $decoded);
        if (($control + $broken) > 0.02 * $length) {
            return -\INF;
        }

        $letters = (int) \preg_match_all('/\p{L}/u', $decoded);
        if (0 === $letters) {
            return -\INF;
        }

        // Dominant script and how pure the decode is (mixing scripts is suspicious).
        $dominant = '';
        $dominantCount = 0;
        foreach (self::SCRIPTS as $script) {
            $count = (int) \preg_match_all('/\p{'.$script.'}/u', $decoded);
            if ($count > $dominantCount) {
                $dominantCount = $count;
                $dominant = $script;
            }
        }

        $purity = $dominantCount / $letters;
        $score = 0.5 * $purity + 0.5 * self::frequencyMatch($dominant, $decoded);

        // A valid multi-byte decode of high-byte-rich input is a strong signal.
        if (\in_array($encoding, self::MULTIBYTE_ENCODINGS, true) && 0 === $broken) {
            $score += 0.5;
        }

        return $score;
    }

    /**
     * Cosine similarity (0.0..1.0) between the decoded letters and the script's
     * reference frequency profile; 0.5 when the script has no profile.
     */
    private static function frequencyMatch(string $script, string $decoded): float
    {
        $model = self::SCRIPT_FREQUENCIES[$script] ?? null;
        if (null === $model) {
            return 0.5;
        }

        $counts = [];
        $total = 0;
        \preg_match_all('/\p{'.$script.'}/u', $decoded, $matches);
        foreach ($matches[0] as $letter) {
            $letter = \mb_strtolower($letter, 'UTF-8');
            if (isset($model[$letter])) {
                $counts[$letter] = ($counts[$letter] ?? 0) + 1;
                ++$total;
            }
        }
        if (0 === $total) {
            return 0.0;
        }

        $dot = 0.0;
        $observedNorm = 0.0;
        $modelNorm = 0.0;
        foreach ($model as $letter => $weight) {
            $observed = ($counts[$letter] ?? 0) / $total;
            $dot += $observed * $weight;
            $observedNorm += $observed * $observed;
            $modelNorm += $weight * $weight;
        }

        return $observedNorm > 0.0 ? $dot / (\sqrt($observedNorm) * \sqrt($modelNorm)) : 0.0;
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
            \mb_substitute_character(0xFFFD);
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
