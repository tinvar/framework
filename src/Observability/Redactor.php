<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * What every log line and span attribute passes through before it is
 * written: secrets and personal data taken out, and everything else made
 * into plain, bounded values.
 *
 * Two passes, because neither alone is enough. By key: a context entry
 * named like a secret -- a password, a token, a cookie, a session, an email
 * -- is replaced whatever its value looks like, since a password can be any
 * string at all. By value: every string, the message included, has what is
 * recognisably a secret or an address cut out of it, since an exception
 * message is free text that quotes whatever it was given -- a mail server's
 * URL with its password, the email a lookup failed on, a signed token.
 *
 * Over-redaction is the failure this prefers: a log line missing a value is
 * an inconvenience, and a log line holding a session token is a breach that
 * every log shipper and every operator with read access now shares.
 *
 * Objects are never serialized. A model or a Data object reaching a log
 * would write whatever it holds; it is written as its class name alone, so
 * the encoder's rule -- nothing but Data crosses the boundary -- has no
 * back door through the log.
 */
final class Redactor
{
    public const string REDACTED = '[redacted]';

    /** The longest string kept, in bytes; what is cut is counted. */
    public const int MAX_STRING = 4096;

    /** How deep a context array is followed. */
    public const int MAX_DEPTH = 6;

    /** Entries kept from one array; the rest are counted. */
    public const int MAX_ITEMS = 50;

    /** Stack frames kept from one exception. */
    public const int MAX_FRAMES = 20;

    /**
     * Key names whose values are never written, matched anywhere in the
     * name. Broad on purpose -- `auth` takes `author` with it -- since a key
     * left out is a secret written.
     */
    private const string KEYS = 'passw|passphrase|^pass$|_pass$|secret|token|api.?key|app.?key|private.?key|access.?key'
        . '|auth|cookie|session|csrf|xsrf|signature|credential|dsn|otp|cvv|cvc|iban|ssn|e.?mail|phone';

    /**
     * Patterns cut out of every string, in this order: the URL's password
     * before the address pattern can take `password@host` for an address.
     *
     * @var array<string, string> pattern => replacement
     */
    private const array VALUES = [
        ...self::SECRETS,
        // A query parameter named like a secret, wherever a URL is quoted.
        '/([?&;][a-z0-9_\-]*(?:token|secret|passw|signature|sig|key|code|session|auth)[a-z0-9_\-]*=)[^&\s#"\']*/i' => '$1' . self::REDACTED,
        // Email addresses: the commonest personal data in an exception message.
        '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/' => '[email]',
        // A long run of key material -- a MAC, a hash, a base64 secret, a
        // session cookie's signature -- with letters and digits both, so a
        // request id (32 characters) and an identifier (no digits) survive.
        '/(?<![A-Za-z0-9_\-])(?=[A-Za-z0-9_\-]*[0-9])(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{40,}(?![A-Za-z0-9_\-])/' => self::REDACTED,
    ];

    /**
     * The shapes of a credential in free text, whatever key it is under:
     * the first of VALUES, and every one the dev toolbar's Recording cuts
     * out -- one list, so the two cannot drift apart.
     *
     * @var array<string, string> pattern => replacement
     */
    public const array SECRETS = [
        // user:password@ in a URL: an SMTP or database URL quoted in a
        // message. The user may be empty (`redis://:secret@`), and the
        // password is everything up to the last @ -- a / an @ or a : in it
        // included -- since a password is any string, whatever URLs allow.
        // A URL whose path holds an @ has its port and path taken with it.
        '~\b([a-z][a-z0-9+.\-]*://[^\s/:]*):\S*@~i' => '$1:' . self::REDACTED . '@',
        // An Authorization header's credentials: a bearer token, or Basic's
        // base64, the scheme in any case. The base64 has to look like it --
        // a digit, `+`, `/` or `=`, or a capital after its first letter, as
        // `dXNlcjpwYXNz` (user:pass) has -- or "basic" in a sentence would
        // take the next word with it. Only the scheme ignores case: a case-
        // insensitive capital is no test at all.
        '/\b(Bearer)\s+[A-Za-z0-9._~+\/=\-]{8,}/i' => '$1 ' . self::REDACTED,
        '/\b((?i:basic))\s+(?=[A-Za-z0-9+\/]*[0-9+\/=]|[A-Za-z0-9+\/]+[A-Z])[A-Za-z0-9+\/]{8,}={0,2}/' => '$1 ' . self::REDACTED,
        // JWTs, the live hub's access tokens among them.
        '/\beyJ[A-Za-z0-9_\-]*\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]*/' => '[jwt]',
        // The framework's signed tokens (SignedToken): route, fragment, live.
        '/\bv1\.[A-Za-z0-9_\-]{16,}\.[A-Za-z0-9_\-]{16,}/' => '[token]',
        // An API token (ADR 0158), wherever it is quoted -- a body, a URL a
        // script put it in by mistake -- not only after "Bearer".
        '/\btvr_[0-9a-f]{32}_[0-9A-Za-z]{43}/' => '[api-token]',
    ];

    private readonly string $keys;

    /**
     * @param list<string> $keys more key names to redact, as substrings: an
     *        application's own -- `iban`, `tax_id` -- beside the framework's
     * @param bool $traces whether an exception carries its stack, as
     *        `file:line Class->method()` frames, never their arguments
     */
    public function __construct(array $keys = [], private readonly bool $traces = true)
    {
        $extra = array_map(static fn (string $key): string => preg_quote($key, '/'), array_values(array_filter($keys, static fn (string $k): bool => $k !== '')));
        $this->keys = '/' . self::KEYS . ($extra === [] ? '' : '|' . implode('|', $extra)) . '/i';
    }

    /** Whether a value filed under $key is never written. */
    public function isSensitiveKey(string $key): bool
    {
        return preg_match($this->keys, $key) === 1;
    }

    /** $text with every recognisable secret and address cut out, and cut to MAX_STRING. */
    public function text(string $text): string
    {
        $length = \strlen($text);
        // Cut before the patterns run, so a long string costs bounded work --
        // and then back to the last whitespace or delimiter. A secret the cut
        // splits is too short for its pattern to recognise, and what the
        // patterns replace earlier in the window can shrink the text enough
        // to bring that fragment inside the final cut. Dropped whole, nothing
        // past a complete token in the window can reach the output.
        if ($length > self::MAX_STRING + 512) {
            $text = (string) preg_replace('/[^\s"\'<>()\[\]{},;]*\z/', '', self::cut($text, self::MAX_STRING + 512));
        }

        $redacted = preg_replace(array_keys(self::VALUES), array_values(self::VALUES), $text);
        // A string the patterns could not finish -- PCRE's backtracking
        // limit -- is not written at all rather than written unredacted.
        if (!\is_string($redacted)) {
            return self::REDACTED;
        }

        // Marked whenever the text was longer than what is kept, even where
        // redaction left it short enough: a cut line must not read as whole.
        $longest = max($length, \strlen($redacted));

        return $longest > self::MAX_STRING
            ? self::cut($redacted, self::MAX_STRING) . '...[+' . ($longest - self::MAX_STRING) . ' bytes]'
            : $redacted;
    }

    /**
     * A log line's context, redacted: string keys only, as JSON writes them.
     *
     * @param array<array-key, mixed> $context
     * @return array<string, mixed>
     */
    public function context(array $context): array
    {
        $clean = [];
        foreach ($this->entries($context, 0) as $key => $value) {
            $clean[(string) $key] = $value;
        }

        return $clean;
    }

    /** Any value, made plain and bounded, and redacted. */
    public function value(mixed $value, int $depth = 0): mixed
    {
        return match (true) {
            $value === null, \is_bool($value), \is_int($value) => $value,
            \is_float($value) => is_finite($value) ? $value : (string) $value,
            \is_string($value) => $this->text($value),
            \is_array($value) => $depth >= self::MAX_DEPTH ? '[depth]' : $this->entries($value, $depth + 1),
            $value instanceof \Throwable => $this->throwable($value),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \DateTimeInterface => $value->format(DATE_RFC3339_EXTENDED),
            \is_object($value) => '[object ' . $value::class . ']',
            default => '[' . get_debug_type($value) . ']',
        };
    }

    /**
     * An exception as a log line carries it: its class, its message with
     * secrets cut out, where it was thrown, its stack -- as frames, never
     * arguments, which hold whatever a function was passed -- and what it
     * wraps.
     *
     * @return array<string, mixed>
     */
    public function throwable(\Throwable $e, int $depth = 0): array
    {
        $out = [
            'class' => $e::class,
            'message' => $this->text($e->getMessage()),
            'at' => $e->getFile() . ':' . $e->getLine(),
        ];

        if ($this->traces) {
            $out['trace'] = self::frames($e);
        }

        $previous = $e->getPrevious();
        if ($previous !== null && $depth < 3) {
            $out['previous'] = $this->throwable($previous, $depth + 1);
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private function entries(array $values, int $depth): array
    {
        $clean = [];
        $kept = 0;
        foreach ($values as $key => $value) {
            if ($kept === self::MAX_ITEMS) {
                $clean['...'] = '+' . (\count($values) - self::MAX_ITEMS) . ' more';
                break;
            }
            $kept++;

            if (\is_string($key)) {
                if ($this->isSensitiveKey($key)) {
                    $clean[$this->text($key)] = self::REDACTED;
                    continue;
                }
                $key = $this->text($key);
            }

            $clean[$key] = $this->value($value, $depth);
        }

        return $clean;
    }

    /** @return list<string> */
    private static function frames(\Throwable $e): array
    {
        $frames = [];
        foreach (\array_slice($e->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            $where = isset($frame['file']) ? $frame['file'] . ':' . ($frame['line'] ?? 0) : '[internal]';
            $call = isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') . $frame['function'] : $frame['function'];
            $frames[] = "{$where} {$call}()";
        }

        return $frames;
    }

    /** At most $bytes of $text, never splitting a UTF-8 character. */
    private static function cut(string $text, int $bytes): string
    {
        return \function_exists('mb_strcut') ? mb_strcut($text, 0, $bytes, 'UTF-8') : substr($text, 0, $bytes);
    }
}
