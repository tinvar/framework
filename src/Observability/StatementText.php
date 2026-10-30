<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * What a statement's span may say of it: its text with every literal taken
 * out, and a short name.
 *
 * A span never carries a statement's bound values -- the observer is never
 * handed them -- but a statement's text can hold values of its own: raw
 * SQL with a literal in it, a migration's default, an email address someone
 * wrote into a `whereRaw()`. So every quoted string, every number and every
 * comment is replaced by `?` before the text goes anywhere, as
 * OpenTelemetry's database conventions ask of a sanitized query; what is
 * left is the statement's shape, which is the application's code. The
 * Tracer's Redactor pass still runs over it on the way out.
 *
 * Over-sanitizing is the failure chosen: a quoted identifier on MySQL,
 * whose double quotes are a string's unless ANSI_QUOTES is set, is taken
 * out as a literal would be.
 */
final class StatementText
{
    /** Longest name a statement's span is given. */
    private const int MAX_NAME = 64;

    private const array OPERATIONS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'WITH', 'UPSERT', 'REPLACE', 'MERGE', 'CREATE', 'ALTER', 'DROP', 'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'SET', 'PRAGMA', 'VACUUM', 'TRUNCATE', 'SHOW', 'EXPLAIN'];

    /** $sql with its comments, quoted strings and numbers replaced by `?`. */
    public static function sanitize(string $sql, string $system = ''): string
    {
        $mysql = $system === 'mysql' || $system === 'mariadb';

        // One pattern, every comment and literal an alternative of it, read
        // left to right: whichever begins first is taken whole, as the
        // database's own lexer takes it. Taken kind by kind instead --
        // comments, then strings -- a `--` or `/*` inside a string reads as
        // a comment, eats that string's closing quote, and pairs the next
        // string's opening quote with a closing one, leaving the value
        // between them in the text.
        $alternatives = [
            // A block comment. They nest, as Postgres has them; on MySQL,
            // whose do not, reading them as nested only takes out more.
            '(?<block>/\*(?:(?&block)|[^*/]++|\*(?!/)|/(?!\*))*+(?:\*/|\z))',
            // A line comment, and MySQL's # one.
            '--[^\n]*+' . ($mysql ? '|\#[^\n]*+' : ''),
            // Postgres's dollar-quoted strings, $$...$$ or $tag$...$tag$.
            '\$(?<tag>[A-Za-z_][A-Za-z0-9_]*|)\$.*?(?:\$\k<tag>\$|\z)',
            // An escape string, E'...', where a backslash escapes the quote.
            "(?<![A-Za-z0-9_])[Ee]'(?:''|\\\\.|[^'\\\\])*+(?:'|\\z)",
            // Any other quoted string and its prefix -- X'', B'', N''. A
            // backslash escapes a quote on MySQL alone: elsewhere 'a\' is a
            // whole string, and reading it as escaped would end the literal
            // at the next string's opening quote and leave that one's value
            // in the text.
            $mysql ? "(?<![A-Za-z0-9_])[XxBbNn]?'(?:''|\\\\.|[^'\\\\])*+(?:'|\\z)" : "(?<![A-Za-z0-9_])[XxBbNn]?'(?:''|[^'])*+(?:'|\\z)",
            // MySQL's double-quoted string.
            $mysql ? '"(?:""|\\\\.|[^"\\\\])*+(?:"|\z)' : '(*FAIL)',
            // A number standing alone: not a placeholder's ($1), not part of a name (t1).
            '(?<![A-Za-z0-9_$.])-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?(?![A-Za-z0-9_])',
        ];
        [$block, $line] = $alternatives;

        $out = preg_replace_callback(
            '~(?<comment>' . $block . '|' . $line . ')|' . implode('|', \array_slice($alternatives, 2)) . '~s',
            // A comment to a space, everything else to a placeholder.
            static fn (array $m): string => ($m['comment'] ?? '') !== '' ? ' ' : '?',
            $sql,
        );
        // PCRE gave up on it, or a quote is left that no pattern closed:
        // nothing of it is kept, rather than a value.
        if (!\is_string($out) || str_contains($out, "'") || ($mysql && str_contains($out, '"'))) {
            return '?';
        }

        return trim((string) preg_replace('/\s+/', ' ', $out));
    }

    /**
     * A span's name for a sanitized statement: its operation and the first
     * table it names -- `SELECT orders`, `INSERT order_lines` -- or its
     * operation alone.
     */
    public static function summary(string $sanitized): string
    {
        if (preg_match('/^\s*\(?\s*([A-Za-z]+)/', $sanitized, $m) !== 1) {
            return 'statement';
        }
        $operation = strtoupper($m[1]);
        if (!\in_array($operation, self::OPERATIONS, true)) {
            return 'statement';
        }

        $table = match ($operation) {
            'SELECT', 'DELETE', 'WITH' => '/\bFROM\s+([`"\[]?[A-Za-z_][A-Za-z0-9_.]*)/i',
            'INSERT', 'UPSERT', 'REPLACE', 'MERGE' => '/\bINTO\s+([`"\[]?[A-Za-z_][A-Za-z0-9_.]*)/i',
            'UPDATE' => '/^\s*UPDATE\s+(?:OR\s+[A-Z]+\s+)?([`"\[]?[A-Za-z_][A-Za-z0-9_.]*)/i',
            default => null,
        };
        $name = $operation;
        if ($table !== null && preg_match($table, $sanitized, $t) === 1) {
            $name .= ' ' . trim($t[1], '`"[');
        }

        return substr($name, 0, self::MAX_NAME);
    }
}
