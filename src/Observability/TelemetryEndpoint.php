<?php

declare(strict_types=1);

namespace Tinvar\Observability;

use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Support\Str;

/**
 * POST /_vitals and POST /_errors: what a page's script says about how the
 * page loaded and what went wrong in it, reported by @tinvar/react/telemetry.
 *
 * tinvar-spec.md, "Endpoints": "`/_vitals`, `/_errors` ... | `throttle:telemetry`,
 * CSRF | 16 KB bodies; route names checked against the manifest". The Kernel
 * mounts this behind its global stack, the CSRF check every POST meets and
 * the telemetry throttle; what is left is the body, which is the browser's
 * say-so and nothing more, so nothing in it is believed that would cost
 * anything to believe:
 *
 * - **Labels come from the server.** A report names its page by route name,
 *   and a name the route registry does not have is refused with a 422 rather
 *   than counted: a client that could choose a label could make a series per
 *   request. Metric names and error kinds are fixed lists, refused likewise.
 * - **Values are bounded.** A vital that is not a finite number between zero
 *   and its ceiling refuses the whole report, which is recorded all or not
 *   at all; one hostile client can then move a histogram's sum by no more
 *   than a slow page would.
 * - **Errors keep only bounded fields.** A message cut short, the digest a
 *   server failure was shown under, the kind, a stack trimmed to a few
 *   frames, and the source file and line -- the file only when it is one
 *   of the build's assets, which a client cannot know apart from any other
 *   path, so it is dropped rather than refused. In the message and the
 *   stack every URL's query and fragment is cut, relative or not, and
 *   every value the request's own cookies and CSRF header carry is taken
 *   out: the client does both before it sends, and a client is not what
 *   this relies on. Each is then one line in the application's log, through
 *   its Logger and so its Redactor, under the request's id.
 * - **Nothing comes back.** 204, no body, no cookie; nothing is written to
 *   the session. A refusal is the framework's fixed answer for its status,
 *   never a word of what was sent.
 *
 * Problem reports -- the CSP violation reports a browser sends of its own
 * accord, with no CSRF token -- are not answered here, but by ProblemReports,
 * at /_reports and without a session (ADR 0093).
 */
final class TelemetryEndpoint
{
    public const string VITALS = '/_vitals';
    public const string ERRORS = '/_errors';

    /** "16 KB bodies", measured before a byte of it is parsed. */
    public const int MAX_BODY_BYTES = 16_384;

    /**
     * Deep enough for a report and no deeper: an object holding a list of
     * objects of scalars, as PHP counts it. A stack sent as anything but one
     * string does not decode.
     */
    public const int MAX_JSON_DEPTH = 4;

    /**
     * The vitals taken, each with the largest value believed: milliseconds
     * for the four that are times, a score for layout shift. A page whose
     * paint took two minutes was not measured, and a report saying so is
     * refused rather than counted.
     *
     * @var array<string, float>
     */
    public const array VITALS_CEILINGS = [
        'LCP' => 120_000.0,
        'INP' => 120_000.0,
        'CLS' => 10.0,
        'FCP' => 120_000.0,
        'TTFB' => 120_000.0,
    ];

    /**
     * Where an error was seen: a window `error` event, an unhandled
     * rejection, and the three hooks React's root takes.
     */
    public const array ERROR_KINDS = ['error', 'rejection', 'caught', 'uncaught', 'recoverable'];

    /** Errors in one report: what the client gathers before it sends. */
    public const int MAX_ERRORS = 10;

    /** Bytes kept of an error's message. */
    public const int MAX_MESSAGE = 512;

    /** Frames kept of a stack, and bytes of each. */
    public const int MAX_STACK_LINES = 10;
    public const int MAX_STACK_LINE = 240;

    /** Where the build's client assets are served from: Vite's `assetsDir`. */
    public const string ASSET_PATH = '/assets/';

    public const string VITAL_SECONDS = 'tinvar_web_vitals_seconds';
    public const string VITAL_SCORES = 'tinvar_web_vitals_score';
    public const string CLIENT_ERRORS = 'tinvar_client_errors_total';

    /**
     * The times' buckets, in seconds: each vital's "good" and "poor"
     * thresholds among them -- INP's 0.2 and 0.5, TTFB's 0.8 and 1.8, FCP's
     * 1.8 and 3, LCP's 2.5 and 4 -- so the share of each that was good is
     * read off a bucket exactly rather than interpolated.
     */
    public const array SECONDS_BUCKETS = [0.025, 0.05, 0.1, 0.2, 0.3, 0.5, 0.8, 1.0, 1.8, 2.5, 3.0, 4.0, 6.0, 10.0, 20.0, 60.0];

    /** Layout shift's, with its thresholds of 0.1 and 0.25 among them. */
    public const array SCORE_BUCKETS = [0.01, 0.025, 0.05, 0.1, 0.15, 0.25, 0.5, 1.0, 2.5];

    /** The shape of a digest a failure is shown under: the framework's, or React's. */
    private const string DIGEST = '/^[A-Za-z0-9_-]{1,64}\z/';

    /**
     * @param string|null $assets the directory the build's client assets are
     *        served from at ASSET_PATH: a reported source file is kept only
     *        when it names a file there. Without one, none is.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly Metrics $metrics,
        private readonly RouteRegistry $routes,
        private readonly ?string $assets = null,
    ) {
        $metrics
            ->histogram(self::VITAL_SECONDS, 'Web vitals measured in seconds by the browser, by route and metric: LCP, INP, FCP, TTFB.', ['route', 'metric'], self::SECONDS_BUCKETS)
            ->histogram(self::VITAL_SCORES, 'Web vitals that are scores, by route and metric: CLS.', ['route', 'metric'], self::SCORE_BUCKETS)
            ->counter(self::CLIENT_ERRORS, 'Errors pages reported from the browser, by route and kind.', ['route', 'kind']);
    }

    public function matches(Request $request): bool
    {
        return $request->path === self::VITALS || $request->path === self::ERRORS;
    }

    /** What a request is counted under: `_vitals` or `_errors`, never its path. */
    public static function endpoint(Request $request): string
    {
        return $request->path === self::VITALS ? '_vitals' : '_errors';
    }

    /** POST only: a report is sent, never fetched. */
    public static function assertMethod(Request $request): void
    {
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'Telemetry reports are POST only.', 'method_not_allowed', ['allow' => 'POST']);
        }
    }

    public function handle(Request $request, string $rawBody): Response
    {
        self::assertMethod($request);

        $body = self::decode($request, $rawBody);
        $route = $this->route($body['route'] ?? null);

        if ($request->path === self::VITALS) {
            self::fields($body, ['route', 'metrics']);
            $this->recordVitals($route, $this->vitals($body['metrics'] ?? null));
        } else {
            self::fields($body, ['route', 'errors']);
            $this->recordErrors($route, $this->errors($body['errors'] ?? null, self::secretsOf($request)));
        }

        return Response::make(204, '', ['cache-control' => 'no-store']);
    }

    /**
     * The body as a JSON object: refused before it is parsed when it is over
     * the limit or not JSON, and refused when it nests deeper than a report.
     *
     * @return array<array-key, mixed>
     */
    private static function decode(Request $request, string $rawBody): array
    {
        if (\strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Telemetry report is too large.', 'body_too_large');
        }

        $type = strtolower(trim(explode(';', $request->header('content-type') ?? '', 2)[0]));
        if ($type !== 'application/json') {
            throw new HttpException(415, 'Send a telemetry report as application/json.', 'unsupported_media_type');
        }

        try {
            $decoded = json_decode($rawBody, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // The message is not forwarded: it can quote the body.
            throw new HttpException(400, 'Telemetry report is not valid JSON within the depth limit.', 'bad_json');
        }

        if (!\is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new HttpException(400, 'Telemetry report must be a JSON object.', 'bad_json');
        }

        return $decoded;
    }

    /**
     * The page's route name, when the registry has a page by that name.
     * "Route names checked against the manifest": the registry is the list
     * of every page this build has, and the only names a label may hold.
     */
    private function route(mixed $name): string
    {
        if (!\is_string($name) || \strlen($name) > 128 || !Str::isDottedIdentifier($name)) {
            throw new HttpException(422, 'A telemetry report names its page by route name.', 'unknown_route');
        }

        try {
            $this->routes->named($name);
        } catch (RoutingException) {
            throw new HttpException(422, 'A telemetry report must name one of the application\'s pages.', 'unknown_route');
        }

        return $name;
    }

    /**
     * @param array<array-key, mixed> $object
     * @param list<string> $allowed
     */
    private static function fields(array $object, array $allowed): void
    {
        if (array_diff_key($object, array_flip($allowed)) !== []) {
            throw new HttpException(422, 'A telemetry report carries only the fields it is documented to.', 'unexpected_field');
        }
    }

    /**
     * Each vital named, checked against its ceiling -- every one before any
     * is recorded.
     *
     * @return array<string, float>
     */
    private function vitals(mixed $metrics): array
    {
        if (!\is_array($metrics) || $metrics === [] || array_is_list($metrics)) {
            throw new HttpException(422, 'A vitals report carries its metrics as an object.', 'bad_metrics');
        }

        $vitals = [];
        foreach ($metrics as $name => $value) {
            if (!\is_string($name) || !isset(self::VITALS_CEILINGS[$name])) {
                throw new HttpException(422, 'A vitals report names only LCP, INP, CLS, FCP and TTFB.', 'unknown_metric');
            }
            if ((!\is_int($value) && !\is_float($value)) || !is_finite((float) $value)
                || $value < 0 || $value > self::VITALS_CEILINGS[$name]) {
                throw new HttpException(422, 'A vital is a finite number within its bounds.', 'bad_value');
            }
            $vitals[$name] = (float) $value;
        }

        return $vitals;
    }

    /** @param array<string, float> $vitals */
    private function recordVitals(string $route, array $vitals): void
    {
        foreach ($vitals as $name => $value) {
            $labels = ['route' => $route, 'metric' => $name];
            if ($name === 'CLS') {
                $this->metrics->observe(self::VITAL_SCORES, $value, $labels);
            } else {
                // The browser measures in milliseconds; Prometheus's unit is
                // the second.
                $this->metrics->observe(self::VITAL_SECONDS, $value / 1000, $labels);
            }
        }
    }

    /**
     * Each error, reduced to the fields kept -- every one checked before any
     * is recorded.
     *
     * @param list<string> $secrets what the request's cookies and CSRF header carry
     * @return list<array{kind: string, error: array<string, mixed>}>
     */
    private function errors(mixed $errors, array $secrets): array
    {
        if (!\is_array($errors) || $errors === [] || !array_is_list($errors) || \count($errors) > self::MAX_ERRORS) {
            throw new HttpException(422, 'An errors report carries a list of up to ' . self::MAX_ERRORS . ' errors.', 'bad_errors');
        }

        $kept = [];
        foreach ($errors as $error) {
            if (!\is_array($error) || ($error !== [] && array_is_list($error))) {
                throw new HttpException(422, 'Each reported error is an object.', 'bad_errors');
            }
            self::fields($error, ['kind', 'message', 'digest', 'file', 'line', 'stack']);
            $kept[] = $this->error($error, $secrets);
        }

        return $kept;
    }

    /**
     * @param array<array-key, mixed> $error
     * @param list<string> $secrets
     * @return array{kind: string, error: array<string, mixed>}
     */
    private function error(array $error, array $secrets): array
    {
        $kind = $error['kind'] ?? null;
        if (!\is_string($kind) || !\in_array($kind, self::ERROR_KINDS, true)) {
            throw new HttpException(422, 'A reported error\'s kind is one of ' . implode(', ', self::ERROR_KINDS) . '.', 'unknown_kind');
        }

        $message = $error['message'] ?? null;
        $digest = $error['digest'] ?? null;
        $file = $error['file'] ?? null;
        $line = $error['line'] ?? null;
        $stack = $error['stack'] ?? null;
        if (!\is_string($message)
            || ($digest !== null && (!\is_string($digest) || preg_match(self::DIGEST, $digest) !== 1))
            || ($file !== null && (!\is_string($file) || \strlen($file) > 512))
            || ($line !== null && (!\is_int($line) || $line < 1 || $line > 10_000_000))
            || ($stack !== null && !\is_string($stack))) {
            throw new HttpException(422, 'A reported error has a message, and its other fields are of their documented shapes.', 'bad_errors');
        }

        $kept = ['message' => self::cut(self::clean($message, $secrets), self::MAX_MESSAGE)];
        if (\is_string($digest)) {
            $kept['digest'] = $digest;
        }
        // The line only beside the file it is a line of.
        $asset = $this->asset($file);
        if ($asset !== null) {
            $kept['file'] = $asset;
            if ($line !== null) {
                $kept['line'] = $line;
            }
        }
        $frames = $stack === null ? [] : self::stack($stack, $secrets);
        if ($frames !== []) {
            $kept['stack'] = $frames;
        }

        return ['kind' => $kind, 'error' => $kept];
    }

    /** @param list<array{kind: string, error: array<string, mixed>}> $errors */
    private function recordErrors(string $route, array $errors): void
    {
        foreach ($errors as ['kind' => $kind, 'error' => $error]) {
            $this->metrics->increment(self::CLIENT_ERRORS, ['route' => $route, 'kind' => $kind]);
            // At warning: it went wrong for a visitor, so someone should look,
            // but it is the browser's account of it, unverified -- and at
            // error, anyone with a session could set off whatever pages an
            // operator on the server's errors.
            $this->logger->warning('client error', ['route' => $route, 'kind' => $kind, 'error' => $error]);
        }
    }

    /**
     * $file when it is one of the build's assets: a path under ASSET_PATH,
     * naming one script file there -- no directory, so no way out of it --
     * that is on disk now. Null for anything else: a dev server's module, a
     * third party's script, or a path made up.
     */
    private function asset(mixed $file): ?string
    {
        if ($this->assets === null || !\is_string($file) || !str_starts_with($file, self::ASSET_PATH)) {
            return null;
        }

        $name = substr($file, \strlen(self::ASSET_PATH));
        if (preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,200}\.m?js\z/', $name) !== 1) {
            return null;
        }

        return is_file($this->assets . \DIRECTORY_SEPARATOR . $name) ? $file : null;
    }

    /**
     * A stack as a few lines: control characters gone, cleaned as a message
     * is, each line cut short, and no more than MAX_STACK_LINES.
     *
     * @param list<string> $secrets
     * @return list<string>
     */
    private static function stack(string $stack, array $secrets): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $stack) ?: [] as $line) {
            $line = trim((string) preg_replace('/[\x00-\x1f\x7f]/', '', $line));
            if ($line === '') {
                continue;
            }
            $lines[] = self::cut(self::clean($line, $secrets), self::MAX_STACK_LINE);
            if (\count($lines) === self::MAX_STACK_LINES) {
                break;
            }
        }

        return $lines;
    }

    /**
     * $text without the request's own secrets, and with every query and
     * fragment cut -- where a token in an address would be: an absolute
     * URL's, and a relative or path-only one's (`/reset?token=...`,
     * `./x#access_token=...`) alike. A `?` or `#` in prose goes with them,
     * which costs a message little; the Redactor's patterns, which follow,
     * know a query parameter only by a name like a secret's.
     *
     * @param list<string> $secrets
     */
    private static function clean(string $text, array $secrets): string
    {
        $text = str_replace($secrets, Redactor::REDACTED, $text);
        $cut = preg_replace('/[?#][^\s():\'"]*/', '', $text);

        // A pattern that could not run to the end leaves nothing, rather
        // than the text it did not finish with.
        return \is_string($cut) ? $cut : Redactor::REDACTED;
    }

    /**
     * What the request carries that a page's script could have quoted into
     * an error: the values of its cookies -- the session's among them, which
     * script cannot read but a server-made message can still hold -- and its
     * CSRF header. Short values are left: they are no secret, and taking
     * them out would take every word they match with them.
     *
     * @return list<string>
     */
    private static function secretsOf(Request $request): array
    {
        $values = [...array_values($request->cookies), $request->header(VerifyCsrfToken::HEADER) ?? ''];
        $secrets = array_values(array_unique(array_filter($values, static fn (string $v): bool => \strlen($v) >= 8)));
        // Longest first, so one that contains another is taken out whole.
        usort($secrets, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $secrets;
    }

    /** At most $bytes of $text, never splitting a UTF-8 character. */
    private static function cut(string $text, int $bytes): string
    {
        if (\strlen($text) <= $bytes) {
            return $text;
        }

        return \function_exists('mb_strcut') ? mb_strcut($text, 0, $bytes, 'UTF-8') : substr($text, 0, $bytes);
    }
}
