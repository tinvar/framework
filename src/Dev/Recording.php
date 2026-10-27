<?php

declare(strict_types=1);

namespace Tinvar\Dev;

use Tinvar\Codec\Bin;
use Tinvar\Http\Request;
use Tinvar\Orm\LazyLoad;
use Tinvar\Orm\RawStatement;
use Tinvar\Render\FrameTap;
use Tinvar\Render\RenderFailure;
use Tinvar\Render\RenderResult;

/**
 * One request, as the dev toolbar shows it: what it was, the frames each of
 * its renders sent and received -- decoded, so the render frame is the props
 * the renderer was given -- their timings, and the render failure, if any.
 *
 * Everything is kept as plain JSON-ready data, with secrets taken out on
 * the way in. The toolbar is the developer's own, on loopback, behind a
 * token bound to their session; the recording is still a file on disk and a
 * response body, so what would let someone act as the visitor -- the CSRF
 * token, the route token, the history key, minted fragment tokens, the
 * policy's nonce, a password typed into a form -- is never written at all.
 * Loader data stays: the spec's rule is that props "are readable in page
 * source and must not contain secrets", so they show nothing the page does
 * not.
 *
 * The renderer writes some of those secrets back: the nonce on every script,
 * the CSRF token in a form's hidden field, the route token and history key
 * in the page metadata, a minted token on its boundary. So every value taken
 * out of a frame PHP sent is remembered -- here, never in what is kept --
 * and taken out of everything that comes back too.
 *
 * Bounded, since a render can be large and the renderer -- third-party npm
 * code -- chooses what it sends: so many frames, so many bytes of each, so
 * many in all, and a preview of an output chunk rather than the chunk.
 */
final class Recording
{
    /** Frames kept of one request; past it they are counted, not kept. */
    public const int MAX_FRAMES = 400;

    /** JSON bytes of one frame's body: past it the body is replaced by its size. */
    public const int MAX_FRAME_BYTES = 524_288;

    /** JSON bytes of every frame of one request together. */
    public const int MAX_BYTES = 2_097_152;

    /** Bytes of an output chunk shown as text. */
    public const int PREVIEW_BYTES = 2048;

    /** Bytes of the path a summary keeps, so the summary stays one short line (RecordingStore). */
    public const int MAX_PATH_BYTES = 2048;

    /** What a redacted value is replaced by. */
    public const string REDACTED = '[redacted]';

    /**
     * Keys whose string values are never kept, wherever they appear: the
     * render frame's `nonce` and `csrf`, its page's `routeToken` and
     * `historyKey`, a minted frame's `token`s, and the same names in loader
     * data, query arguments, flashed input and the URL's query; and the
     * names the log's Redactor holds a credential under -- a DSN, a private
     * or access key, a session id, a one-time code -- since a browser
     * extension reads what the toolbar does (docs/devtools-protocol.md).
     */
    private const string SECRET_KEYS = '/token|nonce|csrf|xsrf|password|passwd|passphrase|secret|historykey|signature|cookie|authorization|bearer'
        . '|credential|dsn|(?:api|app|private|access)[_-]?key|session[_-]?id|sessid|^otp|otp\z|jwt|pwd|^pass\z|^auth\z/i';

    /**
     * Shapes cut out of every string kept, whatever its key: a renderer's
     * error message, a chunk of what it drew, raw SQL -- free text, which
     * quotes what it was given. The credentials of a URL (a DSN in a
     * connection error), a Bearer or Basic credential, a JWT and the
     * framework's own signed tokens. Not addresses or long identifiers, as
     * the log's Redactor takes: props are the page's own, and an email the
     * page shows is no secret from the developer reading it.
     *
     * @var array<string, string> pattern => replacement
     */
    private const array SECRET_VALUES = [
        // The password is everything up to the last @, as the Redactor reads
        // it: a password may hold an @ or a / of its own.
        '~\b([a-z][a-z0-9+.\-]*://[^\s/:@]*):\S*@~i' => '$1:' . self::REDACTED . '@',
        '/\b(Bearer)\s+[A-Za-z0-9._~+\/=\-]{8,}/i' => '$1 ' . self::REDACTED,
        '/\b((?i:basic))\s+(?=[A-Za-z0-9+\/]*[0-9+\/=]|[A-Za-z0-9+\/]+[A-Z])[A-Za-z0-9+\/]{8,}={0,2}/' => '$1 ' . self::REDACTED,
        '/\beyJ[A-Za-z0-9_\-]*\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]*/' => '[jwt]',
        '/\bv1\.[A-Za-z0-9_\-]{16,}\.[A-Za-z0-9_\-]{16,}/' => '[token]',
    ];

    /**
     * And in the URL's query, the names the Redactor takes out of a query
     * too: a handoff's one-time `code`, a `session`, a `sig`. Not in frames,
     * where `code` is an error's and `key` a list item's.
     */
    private const string QUERY_SECRET_KEYS = '/token|nonce|csrf|xsrf|passw|secret|historykey|signature|sig|cookie|auth|credential|(?:api|app|private|access)[_-]?key|code|session|^otp|otp\z/i';

    /** A signed token (Render\SignedToken) -- a fragment's or a file's, in a path. */
    private const string SIGNED_TOKEN = '/\bv1\.[A-Za-z0-9_\-]{16,}\.[A-Za-z0-9_\-]{16,}/';

    private const int MAX_DEPTH = 32;

    public readonly string $id;

    private readonly int $startedNs;
    private readonly float $at;
    private readonly string $method;
    private readonly string $path;

    /** @var array<string, mixed> */
    private readonly array $query;

    private readonly string $kind;

    /** @var list<array<string, mixed>> */
    private array $frames = [];

    private int $frameBytes = 0;
    private int $dropped = 0;

    /** @var list<array<string, int|float>> */
    private array $renders = [];

    /** @var array<string, string>|null */
    private ?array $failure = null;

    /**
     * Raw SQL the request sent on tenant tables (RawStatement), flagged.
     *
     * @var list<array{sql: string, tables: list<string>, site: string, tenant: string, acrossTenants: bool}>
     */
    private array $rawSql = [];

    /** Raw statements flagged past MAX_RAW_SQL: counted, not kept. */
    private int $rawSqlDropped = 0;

    /** Raw statements kept of one request. */
    public const int MAX_RAW_SQL = 50;

    /** Lazy loads kept of one request; past it they are counted, not kept. */
    public const int MAX_LAZY_LOADS = 100;

    /** acrossTenants() calls kept of one request. */
    public const int MAX_ACROSS_TENANTS = 50;

    /**
     * Every lazy relation load the request ran, with the line that touched
     * the relation (Orm::flagLazyLoads()).
     *
     * @var list<array{relation: string, parents: int, batch: string, site: string}>
     */
    private array $lazyLoads = [];

    private int $lazyLoadsDropped = 0;

    /**
     * Where each acrossTenants() call was made (Orm::flagAcrossTenants()).
     *
     * @var list<array{site: string}>
     */
    private array $acrossTenants = [];

    private int $acrossTenantsDropped = 0;

    /**
     * Where each slot of the props came from: the loader that returned it,
     * the Data class, and its file:line (DevToolbar::loaded()).
     *
     * @var list<array{slot: string, kind: string, loader: string, data: string, site: string}>
     */
    private array $origins = [];

    /**
     * The secret values PHP sent the renderer, to take out of what it sends
     * back; never kept.
     *
     * @var array<string, true>
     */
    private array $secrets = [];

    /** Shorter than this, a value is not taken out of the renderer's output: it would take out everything. */
    private const int SHORTEST_SECRET = 8;

    public function __construct(Request $request)
    {
        $this->id = bin2hex(random_bytes(8));
        $this->startedNs = hrtime(true);
        $this->at = microtime(true);
        $this->method = $request->method;

        // A fragment's or a file's token is in its path, and a reset link's
        // in its query: kept out of the summary and the query shown, and --
        // remembered, as the secrets of a frame PHP sent are -- out of every
        // frame, where the render frame's page url and whatever the renderer
        // drew with it carry them whole.
        $found = [];
        preg_match_all(self::SIGNED_TOKEN, $request->path, $tokens);
        array_push($found, ...$tokens[0]);
        $this->path = self::text(substr((string) preg_replace(self::SIGNED_TOKEN, '[token]', $request->path), 0, self::MAX_PATH_BYTES));
        $query = self::walk($request->query, null, 0, $found, self::QUERY_SECRET_KEYS);
        $this->query = \is_array($query) ? $query : [];
        foreach ($found as $secret) {
            if (\strlen($secret) >= self::SHORTEST_SECRET) {
                $this->secrets[$secret] = true;
            }
        }
        $this->kind = self::kindOf($request);
    }

    /**
     * What a request is, as the toolbar groups them: a document starts a
     * visit, and what follows it -- a client navigation's payload, a
     * fragment, an action, a query -- is shown under it.
     */
    public static function kindOf(Request $request): string
    {
        return match (true) {
            str_starts_with($request->path, '/_action/') => 'action',
            str_starts_with($request->path, '/_query/') => 'query',
            str_starts_with($request->path, '/_fragment/') => 'fragment',
            str_starts_with($request->path, '/_bootstrap') => 'bootstrap',
            str_starts_with($request->path, '/_live/') => 'live',
            $request->wantsRscPayload() => 'payload',
            \in_array($request->method, ['GET', 'HEAD'], true) && str_contains($request->header('accept') ?? '', 'text/html') => 'document',
            default => 'other',
        };
    }

    /**
     * @param FrameTap::TO_RENDERER|FrameTap::FROM_RENDERER $direction
     * @param array<string, mixed> $frame
     */
    public function frame(string $direction, array $frame): void
    {
        if (\count($this->frames) >= self::MAX_FRAMES) {
            $this->dropped++;

            return;
        }

        $type = \is_string($frame['t'] ?? null) ? self::text($frame['t']) : '?';
        $entry = [
            'dir' => $direction,
            't' => $type,
            'atMs' => round((hrtime(true) - $this->startedNs) / 1e6, 3),
        ];

        if ($type === 'chunk' && \is_string($frame['b'] ?? null)) {
            // Output bytes -- HTML or payload rows, and not necessarily
            // UTF-8 -- as their size and the start of them as text.
            $bytes = $frame['b'];
            $entry['bytes'] = \strlen($bytes);
            $body = ['t' => 'chunk', 'b' => ['bytes' => \strlen($bytes), 'preview' => self::preview($bytes)]];
        } else {
            $found = [];
            $body = self::walk($frame, null, 0, $found);
            if ($direction === FrameTap::TO_RENDERER) {
                foreach ($found as $secret) {
                    if (\strlen($secret) >= self::SHORTEST_SECRET) {
                        $this->secrets[$secret] = true;
                    }
                }
            }
        }
        $body = $this->scrubbed($body);

        $json = self::json($body);
        if (\strlen($json) > self::MAX_FRAME_BYTES) {
            $body = ['t' => $type, 'omitted' => \strlen($json) . ' bytes of JSON, more than the toolbar keeps of one frame'];
            $json = self::json($body);
        }
        if ($this->frameBytes + \strlen($json) > self::MAX_BYTES) {
            $this->dropped++;

            return;
        }

        $this->frameBytes += \strlen($json);
        $entry['body'] = $body;
        $this->frames[] = $entry;
    }

    public function rendered(RenderResult $result): void
    {
        $this->renders[] = [
            'totalMs' => round($result->totalMs, 3),
            'firstByteMs' => round($result->timeToFirstByteMs, 3),
            'waitingOnRendererMs' => round($result->waitingOnRendererMs, 3),
            'queryMs' => round($result->queryMs, 3),
            'streamingMs' => round($result->streamingMs, 3),
            'queryCalls' => $result->queryCalls,
            'queryBatches' => $result->queryBatches,
            'bytes' => $result->bytes,
            'chunks' => $result->chunks,
        ];
    }

    /**
     * Raw SQL sent on a tenant table: tinvar-spec.md, "Tenancy" -- "Raw SQL
     * ... on tenant tables are flagged ... in the dev toolbar". Kept as the
     * ORM handed it over, its literals already taken out (RawSql), and the
     * secrets this request's frames carried taken out too.
     */
    public function rawStatement(RawStatement $statement): void
    {
        if (\count($this->rawSql) >= self::MAX_RAW_SQL) {
            $this->rawSqlDropped++;

            return;
        }

        $sql = $this->scrubbed(self::values(self::text($statement->sql)));
        $this->rawSql[] = [
            'sql' => \is_string($sql) ? $sql : '',
            'tables' => array_map(self::text(...), $statement->tables),
            'site' => self::text($statement->site),
            'tenant' => self::text($statement->tenant),
            'acrossTenants' => $statement->acrossTenants,
        ];
    }

    /**
     * A lazy relation load: tinvar-spec.md, "Observability (dev toolbar)" --
     * "Every lazy relation load with its trigger site". One that ran for one
     * parent alone is the 1+N the batching exists to prevent.
     */
    public function lazyLoad(LazyLoad $load): void
    {
        if (\count($this->lazyLoads) >= self::MAX_LAZY_LOADS) {
            $this->lazyLoadsDropped++;

            return;
        }

        $this->lazyLoads[] = [
            'relation' => self::text($load->relation),
            'parents' => $load->parents,
            'batch' => self::text($load->batch),
            'site' => self::text($load->site),
        ];
    }

    /** An acrossTenants() call: "`acrossTenants()` calls flagged". */
    public function acrossTenants(string $site): void
    {
        if (\count($this->acrossTenants) >= self::MAX_ACROSS_TENANTS) {
            $this->acrossTenantsDropped++;

            return;
        }

        $this->acrossTenants[] = ['site' => self::text($site)];
    }

    /**
     * Where the props of a render came from, slot by slot: the badges the
     * Props panel draws ("per-request props with origin badges and
     * `file:line` links"). The last render's, for a request that rendered
     * more than one.
     *
     * @param list<array{slot: string, kind: string, loader: string, data: string, site: string}> $origins
     */
    public function origins(array $origins): void
    {
        $this->origins = array_map(
            static fn (array $origin): array => array_map(self::text(...), $origin),
            $origins,
        );
    }

    /** The render failed: its classification, the framework's hint for it, and the reference the visitor was shown. */
    public function failed(RenderFailure $failure, string $digest): void
    {
        $this->failure = [
            'code' => $failure->value,
            'title' => $failure->title(),
            'hint' => $failure->hint(),
            'digest' => self::text($digest),
        ];
    }

    /**
     * The recording, finished: the summary the list shows, and the whole.
     * The binding it is kept under goes in the summary, which the store
     * reads and never serves.
     *
     * @return array{summary: array<string, mixed>, detail: array<string, mixed>}
     */
    public function finish(int $status, string $sessionHash, string $authHash): array
    {
        $ms = round((hrtime(true) - $this->startedNs) / 1e6, 3);
        $summary = [
            'id' => $this->id,
            'at' => $this->at,
            'method' => $this->method,
            'path' => $this->path,
            'kind' => $this->kind,
            'status' => $status,
            'ms' => $ms,
            'renders' => \count($this->renders),
            'failure' => $this->failure['code'] ?? null,
            'rawSql' => \count($this->rawSql) + $this->rawSqlDropped,
            'lazyLoads' => \count($this->lazyLoads) + $this->lazyLoadsDropped,
            'acrossTenants' => \count($this->acrossTenants) + $this->acrossTenantsDropped,
        ];

        return [
            'summary' => [...$summary, 'session' => $sessionHash, 'auth' => $authHash],
            'detail' => [
                ...$summary,
                'query' => $this->query,
                'timings' => $this->renders,
                'frames' => $this->frames,
                'framesDropped' => $this->dropped,
                'failure' => $this->failure,
                'rawSql' => $this->rawSql,
                'rawSqlDropped' => $this->rawSqlDropped,
                'lazyLoads' => $this->lazyLoads,
                'lazyLoadsDropped' => $this->lazyLoadsDropped,
                'acrossTenants' => $this->acrossTenants,
                'acrossTenantsDropped' => $this->acrossTenantsDropped,
                'origins' => $this->origins,
            ],
        ];
    }

    /**
     * $value with every secret-named string taken out, as plain data: a Bin
     * as its size, any other object as its type -- a frame holds neither,
     * and the toolbar is no place to start rendering one.
     */
    public static function redact(mixed $value): mixed
    {
        $found = [];

        return self::walk($value, null, 0, $found);
    }

    /**
     * redact(), collecting the values it took out into $found.
     *
     * @param list<string> $found
     */
    private static function walk(mixed $value, ?string $key, int $depth, array &$found, string $secretKeys = self::SECRET_KEYS): mixed
    {
        if ($key !== null && preg_match($secretKeys, $key) === 1) {
            if (\is_string($value) || \is_int($value)) {
                $found[] = (string) $value;

                return self::REDACTED;
            }
            // `?token[]=...` is a secret too, given as a list.
            if (\is_array($value) && $value !== [] && array_is_list($value)
                && array_filter($value, static fn (mixed $item): bool => \is_string($item) || \is_int($item)) === $value) {
                array_push($found, ...array_map(strval(...), $value));

                return self::REDACTED;
            }
        }

        if (\is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return '[nested deeper than the toolbar shows]';
            }
            $out = [];
            foreach ($value as $name => $item) {
                $out[$name] = self::walk($item, (string) $name, $depth + 1, $found, $secretKeys);
            }

            return $out;
        }

        return match (true) {
            \is_string($value) => self::values(self::text($value)),
            $value instanceof Bin => ['bin' => \strlen($value->bytes)],
            \is_object($value) => '[' . get_debug_type($value) . ']',
            // JSON has no word for these.
            \is_float($value) && is_nan($value) => 'NAN',
            \is_float($value) && is_infinite($value) => $value > 0 ? 'INF' : '-INF',
            default => $value,
        };
    }

    /**
     * $value with every secret PHP sent the renderer taken out of its
     * strings: as sent, as JSON writes it, `/` escaped -- the page metadata
     * is JSON in the HTML -- and as a URL writes it, a link's query.
     */
    private function scrubbed(mixed $value): mixed
    {
        if ($this->secrets === []) {
            return $value;
        }
        if (\is_array($value)) {
            return array_map($this->scrubbed(...), $value);
        }
        if (!\is_string($value)) {
            return $value;
        }

        $secrets = array_map(strval(...), array_keys($this->secrets));
        $forms = array_values(array_unique([
            ...$secrets,
            ...array_map(static fn (string $secret): string => str_replace('/', '\\/', $secret), $secrets),
            ...array_map(rawurlencode(...), $secrets),
            ...array_map(urlencode(...), $secrets),
        ]));

        return str_replace($forms, self::REDACTED, $value);
    }

    /**
     * $text with every SECRET_VALUES shape cut out; a string the patterns
     * could not finish -- PCRE's backtracking limit -- is not kept at all,
     * rather than kept whole.
     */
    private static function values(string $text): string
    {
        $redacted = preg_replace(array_keys(self::SECRET_VALUES), array_values(self::SECRET_VALUES), $text);

        return \is_string($redacted) ? $redacted : self::REDACTED;
    }

    /**
     * The start of an output chunk as text, its secrets cut out. Redacted
     * over more than is kept, so a secret the cut would split is whole when
     * the patterns read it; and when the chunk goes on past the cut, the
     * run of characters the cut ends in is dropped, since a secret cut there
     * would be too short for its pattern.
     */
    private static function preview(string $bytes): string
    {
        $redacted = self::values(self::text(substr($bytes, 0, self::PREVIEW_BYTES + 512)));
        if (\strlen($redacted) <= self::PREVIEW_BYTES) {
            return $redacted;
        }

        return (string) preg_replace('/[^\s"\'<>()\[\]{},;]*\z/', '', self::text(substr($redacted, 0, self::PREVIEW_BYTES)));
    }

    /** Valid UTF-8, whatever the bytes were: the renderer chose them. */
    private static function text(string $bytes): string
    {
        return mb_scrub($bytes, 'UTF-8');
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) ?: 'null';
    }
}
