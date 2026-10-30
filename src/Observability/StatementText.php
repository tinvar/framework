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
        // Comments first, to nothing: what is in one is anyone's.
        $sql = preg_replace(['~/\*.*?(\*/|\z)~s', '~--[^\n]*~'], ' ', $sql);
        if (!\is_string($sql)) {
            return '?';
        }
        $patterns = [
            // Postgres's dollar-quoted strings, $$...$$ or $tag$...$tag$.
            '~\$([A-Za-z_][A-Za-z0-9_]*)?\$.*?(\$\1\$|\z)~s',
            // A quoted string, with '' or \' inside it, and its prefix: E'', X'', B'', N''.
            "~(?<![A-Za-z0-9_])[EeXxBbNn]?'(?:''|\\\\.|[^'\\\\])*('|\\z)~s",
        ];
        if ($system === 'mysql' || $system === 'mariadb') {
            $patterns[] = '~"(?:""|\\\\.|[^"\\\\])*("|\z)~s';
        }
        // A number standing alone: not a placeholder's ($1), not part of a name (t1).
        $patterns[] = '~(?<![A-Za-z0-9_$.])-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?(?![A-Za-z0-9_])~';

        $out = preg_replace($patterns, '?', $sql);
        if (!\is_string($out)) {
            // PCRE gave up on it: nothing of it is kept, rather than all.
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
