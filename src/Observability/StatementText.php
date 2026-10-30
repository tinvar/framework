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

    /** The systems whose SQL is read here; any other's text is not kept. */
    private const array SYSTEMS = ['postgresql', 'mysql', 'mariadb', 'sqlite'];

    /**
     * What a name may hold past its first character, on every system read
     * here -- a `$` on Postgres and MySQL, and any byte of a multibyte
     * character, as each takes one -- for the lookbehinds that keep a
     * literal or a dollar quote from beginning inside a name: `é$x$` is one.
     */
    private const string NAME_BYTES = 'A-Za-z0-9_$\x80-\xff';

    private const array OPERATIONS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'WITH', 'UPSERT', 'REPLACE', 'MERGE', 'CREATE', 'ALTER', 'DROP', 'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'SET', 'PRAGMA', 'VACUUM', 'TRUNCATE', 'SHOW', 'EXPLAIN'];

    /**
     * $sql with its comments, quoted strings and numbers replaced by `?`;
     * only `?` for a system not read here, whose lexer this cannot follow.
     *
     * On SQLite a double-quoted token is taken out too: SQLite reads `"x"`
     * as a string wherever no column is named x, so the text cannot tell a
     * quoted name from a value, and keeps neither.
     *
     * @param string $system OpenTelemetry's db.system.name: postgresql, mysql, mariadb or sqlite
     */
    public static function sanitize(string $sql, string $system): string
    {
        return self::scrub($sql, $system, quotedNames: false);
    }

    /**
     * The span's name for $sql: summary() of its text with SQLite's
     * double-quoted tokens left in, so a quoted table is named. Only the
     * operation and the identifier after FROM, INTO or UPDATE reach the
     * name, and a value cannot stand there.
     */
    public static function name(string $sql, string $system): string
    {
        return self::summary(self::scrub($sql, $system, quotedNames: true));
    }

    private static function scrub(string $sql, string $system, bool $quotedNames): string
    {
        if (!\in_array($system, self::SYSTEMS, true)) {
            return '?';
        }
        $mysql = $system === 'mysql' || $system === 'mariadb';
        $postgres = $system === 'postgresql';
        // Which double-quoted tokens are taken out as strings: MySQL's,
        // which are strings, and SQLite's, which may be.
        $doubleQuoted = $mysql || ($system === 'sqlite' && !$quotedNames);

        // One pattern, every comment and literal an alternative of it, read
        // left to right: whichever begins first is taken whole, as the
        // database's own lexer takes it. Taken kind by kind instead --
        // comments, then strings -- a `--` or `/*` inside a string reads as
        // a comment, eats that string's closing quote, and pairs the next
        // string's opening quote with a closing one, leaving the value
        // between them in the text.
        $alternatives = [
            // A block comment: nested on Postgres, which nests them, and to
            // the first `*/` elsewhere. Read as nested where the database
            // does not nest them, the `'` after that first `*/` opens a
            // string the database reads and this would not.
            $postgres ? '(?<block>/\*(?:(?&block)|[^*/]++|\*(?!/)|/(?!\*))*+(?:\*/|\z))' : '/\*.*?(?:\*/|\z)',
            // A line comment, and MySQL's # one.
            '--[^\n]*+' . ($mysql ? '|\#[^\n]*+' : ''),
            // Postgres's dollar-quoted strings, $$...$$ or $tag$...$tag$:
            // there alone, and never inside a name, where a `$` is the
            // name's (a$b$ is a column, on Postgres and MySQL both).
            $postgres ? '(?<![' . self::NAME_BYTES . '])\$(?<tag>[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*|)\$.*?(?:\$\k<tag>\$|\z)' : '(*FAIL)',
            // An escape string, E'...', where a backslash escapes the quote.
            '(?<![' . self::NAME_BYTES . "])[Ee]'(?:''|\\\\.|[^'\\\\])*+(?:'|\\z)",
            // Any other quoted string and its prefix -- X'', B'', N''. A
            // backslash escapes a quote on MySQL alone: elsewhere 'a\' is a
            // whole string, and reading it as escaped would end the literal
            // at the next string's opening quote and leave that one's value
            // in the text.
            '(?<![' . self::NAME_BYTES . '])' . ($mysql ? "[XxBbNn]?'(?:''|\\\\.|[^'\\\\])*+(?:'|\\z)" : "[XxBbNn]?'(?:''|[^'])*+(?:'|\\z)"),
            // A double-quoted string: MySQL's, where a backslash escapes,
            // and SQLite's, where only a doubled quote does.
            $doubleQuoted ? ($mysql ? '"(?:""|\\\\.|[^"\\\\])*+(?:"|\z)' : '"(?:""|[^"])*+(?:"|\z)') : '(*FAIL)',
            // A number standing alone -- not a placeholder's ($1), not part
            // of a name (t1): decimal, perhaps with a fraction, an exponent
            // or Postgres's underscores, or hex, binary or octal (0xCAFE).
            '(?<![.' . self::NAME_BYTES . '])-?(?:0[xX][0-9A-Fa-f_]+|0[bB][01_]+|0[oO][0-7_]+|(?:\d[\d_]*(?:\.[\d_]*)?|\.\d[\d_]*)(?:[eE][+-]?\d+)?)(?![A-Za-z0-9_])',
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
        if (!\is_string($out) || str_contains($out, "'") || ($doubleQuoted && str_contains($out, '"'))) {
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
