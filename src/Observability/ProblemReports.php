<?php

declare(strict_types=1);

namespace Tinvar\Observability;

use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * POST /_reports: what a browser reports of its own accord -- a Content
 * Security Policy violation, a deprecation or an intervention, a crash --
 * through the Reporting API (`Reporting-Endpoints`, `report-to`) or the
 * older `report-uri`.
 *
 * tinvar-spec.md, "Endpoints", puts problem reports beside /_vitals and
 * /_errors: "`throttle:telemetry`, CSRF | 16 KB bodies". A browser sends a
 * report with no CSRF token and no say in its headers, so that is the one
 * line this cannot keep (ADR 0093). What holds instead:
 *
 * - **No session.** The Kernel answers this ahead of its global stack, so no
 *   session is opened or read, no cookie is set, and nobody is signed in: a
 *   report is believed no more for coming from a signed-in browser, and a
 *   flood of them opens no session rows. It is held to the telemetry
 *   budget by address, in a bucket of its own.
 * - **Bounded.** 16 KB, refused before a byte is parsed; nested no deeper
 *   than a report; at most MAX_REPORTS reports, the rest dropped.
 * - **Untrusted.** Anyone can post here, and a page's own script can write
 *   what it likes into a violation's sample. So nothing in a report is a
 *   label but what a fixed list holds -- its type, the directive it broke,
 *   the disposition -- and anything else is `other`. What is logged is
 *   bounded fields, rebuilt: a URL down to its origin and path, its
 *   credentials, query and fragment cut; a keyword where a URL was one
 *   (`inline`, `eval`); numbers as numbers; free text cut short, invalid
 *   UTF-8 and every control and formatting character taken out -- a line
 *   break, a bidi override -- before the Logger, which writes JSON and
 *   redacts, ever sees it. The user agent and the report's age are dropped.
 * - **Nothing comes back.** 204, no body, no cookie. A refusal is a fixed
 *   answer for its status, never a word of what was sent.
 * - **Counted.** `tinvar_problem_reports_total` by type, directive and
 *   disposition; the request itself by the Kernel, as `_reports`.
 */
final class ProblemReports
{
    public const string PATH = '/_reports';

    /** The route a request here is counted under. */
    public const string ROUTE = '_reports';

    /**
     * The endpoint's name in `Reporting-Endpoints`. `default` because the
     * browser sends the reports nothing names an endpoint for --
     * deprecations, interventions, crashes -- to the endpoint of that name.
     */
    public const string ENDPOINT = 'default';

    /** "16 KB bodies", measured before a byte of it is parsed. */
    public const int MAX_BODY_BYTES = 16_384;

    /** A list of reports, each an object holding a body of scalars, and one level spare. */
    public const int MAX_JSON_DEPTH = 5;

    /** Reports taken from one request; a batch longer than this is cut. */
    public const int MAX_REPORTS = 20;

    /** Bytes kept of free text: a deprecation's message, a violation's sample. */
    public const int MAX_TEXT = 256;
    public const int MAX_SAMPLE = 64;

    /** Bytes kept of a URL's path. */
    public const int MAX_PATH = 256;

    public const string REPORTS = 'tinvar_problem_reports_total';

    /** The report types counted by name; any other is `other`. */
    public const array TYPES = [
        'csp-violation',
        'deprecation',
        'intervention',
        'crash',
        'coep',
        'coop',
        'permissions-policy-violation',
        'document-policy-violation',
        'integrity-violation',
        'network-error',
    ];

    /** The CSP directives a violation is counted under by name; any other is `other`. */
    public const array DIRECTIVES = [
        'default-src', 'script-src', 'script-src-elem', 'script-src-attr', 'style-src', 'style-src-elem',
        'style-src-attr', 'img-src', 'font-src', 'connect-src', 'media-src', 'object-src', 'frame-src',
        'child-src', 'worker-src', 'manifest-src', 'prefetch-src', 'form-action', 'frame-ancestors',
        'base-uri', 'navigate-to', 'require-trusted-types-for', 'trusted-types', 'sandbox',
        'upgrade-insecure-requests', 'webrtc', 'fenced-frame-src',
    ];

    /** What a violation was: blocked, or only reported. */
    public const array DISPOSITIONS = ['enforce', 'report'];

    /** What a violation's blocked URL may be in place of a URL. */
    private const string KEYWORD = '/^[a-z][a-z-]{0,31}\z/';

    /** A deprecation's or intervention's id. */
    private const string ID = '/^[A-Za-z0-9_.-]{1,64}\z/';

    /** The content types a report arrives as. */
    private const array CONTENT_TYPES = ['application/reports+json', 'application/csp-report', 'application/json'];

    public function __construct(
        private readonly Logger $logger,
        private readonly Metrics $metrics,
    ) {
        $metrics->counter(self::REPORTS, 'Problem reports browsers sent, by type, CSP directive and disposition.', ['type', 'directive', 'disposition']);
    }

    /**
     * The `Reporting-Endpoints` header's value, for a document's response:
     * where its browser sends the reports it makes.
     */
    public static function reportingEndpoints(): string
    {
        return self::ENDPOINT . '="' . self::PATH . '"';
    }

    /**
     * The directives a document's policy adds to send its violations here:
     * `report-to` for a browser with the Reporting API, `report-uri` for one
     * without, which a browser with it ignores. Not for a policy in a meta
     * element, where both are ignored.
     */
    public static function cspDirectives(): string
    {
        return 'report-uri ' . self::PATH . '; report-to ' . self::ENDPOINT;
    }

    public function matches(Request $request): bool
    {
        return $request->path === self::PATH;
    }

    /** POST only: a report is sent, never fetched. */
    public static function assertMethod(Request $request): void
    {
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'Problem reports are POST only.', 'method_not_allowed', ['allow' => 'POST']);
        }
    }

    public function handle(Request $request, string $rawBody): Response
    {
        self::assertMethod($request);

        foreach (self::reports(self::decode($request, $rawBody)) as $report) {
            $kept = $this->kept($report);
            $this->metrics->increment(self::REPORTS, [
                'type' => $kept['type'],
                'directive' => $kept['directive'] ?? 'none',
                'disposition' => $kept['disposition'] ?? 'none',
            ]);
            // At notice: the browser's account, unverified, and a page that
            // trips its own policy on every load would otherwise page whoever
            // watches warnings. The metric is what to alert on.
            $this->logger->log('notice', 'problem report', $kept);
        }

        return Response::make(204, '', ['cache-control' => 'no-store']);
    }

    /**
     * The body, refused before it is parsed when it is over the limit or
     * not a report's type, and refused when it nests deeper than a report.
     */
    private static function decode(Request $request, string $rawBody): mixed
    {
        if (\strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Problem report is too large.', 'body_too_large');
        }

        $type = strtolower(trim(explode(';', $request->header('content-type') ?? '', 2)[0]));
        if (!\in_array($type, self::CONTENT_TYPES, true)) {
            throw new HttpException(415, 'Send a problem report as application/reports+json or application/csp-report.', 'unsupported_media_type');
        }

        try {
            return json_decode($rawBody, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // The message is not forwarded: it can quote the body.
            throw new HttpException(400, 'Problem report is not valid JSON within the depth limit.', 'bad_json');
        }
    }

    /**
     * Each report as its type and its body: a Reporting API batch's, or the
     * one a `report-uri` post carries under `csp-report`. At most MAX_REPORTS.
     *
     * @return list<array{type: string, body: array<array-key, mixed>}>
     */
    private static function reports(mixed $decoded): array
    {
        if (\is_array($decoded) && !array_is_list($decoded) && \is_array($decoded['csp-report'] ?? null)) {
            return [['type' => 'csp-violation', 'body' => $decoded['csp-report']]];
        }

        if (!\is_array($decoded) || !array_is_list($decoded) || $decoded === []) {
            throw new HttpException(422, 'A problem report is a list of reports, or a csp-report object.', 'bad_reports');
        }

        $reports = [];
        foreach (\array_slice($decoded, 0, self::MAX_REPORTS) as $report) {
            if (!\is_array($report) || !\is_string($report['type'] ?? null)) {
                throw new HttpException(422, 'Each problem report is an object with a type.', 'bad_reports');
            }
            $body = $report['body'] ?? [];
            $reports[] = ['type' => $report['type'], 'body' => \is_array($body) ? $body : []];
        }

        return $reports;
    }

    /**
     * What is kept of one report: labels from fixed lists, and bounded
     * fields rebuilt from the body. Both a Reporting API body's names
     * (`effectiveDirective`) and `report-uri`'s (`effective-directive`) are
     * read.
     *
     * @param array{type: string, body: array<array-key, mixed>} $report
     * @return array<string, string|int>
     */
    private function kept(array $report): array
    {
        $type = \in_array($report['type'], self::TYPES, true) ? $report['type'] : 'other';
        $body = $report['body'];
        $field = static fn (string ...$names): mixed => array_find(
            array_map(static fn (string $name): mixed => $body[$name] ?? null, $names),
            static fn (mixed $value): bool => $value !== null,
        );

        $kept = ['type' => $type];

        if ($type === 'csp-violation') {
            $kept['directive'] = self::directive($field('effectiveDirective', 'effective-directive', 'violated-directive'));
            $disposition = $field('disposition');
            $kept['disposition'] = \is_string($disposition) && \in_array($disposition, self::DISPOSITIONS, true) ? $disposition : 'other';
            self::put($kept, 'blocked', self::blocked($field('blockedURL', 'blocked-uri')));
            self::put($kept, 'sample', self::text($field('sample', 'script-sample'), self::MAX_SAMPLE));
        } else {
            $id = $field('id');
            self::put($kept, 'id', \is_string($id) && preg_match(self::ID, $id) === 1 ? $id : null);
            // Not `message`: the log line's own field, which a context entry never replaces.
            self::put($kept, 'detail', self::text($field('message', 'reason'), self::MAX_TEXT));
        }

        self::put($kept, 'document', self::url($field('documentURL', 'document-uri')));
        self::put($kept, 'source_file', self::url($field('sourceFile', 'source-file')));
        self::put($kept, 'line', self::number($field('lineNumber', 'line-number')));
        self::put($kept, 'column', self::number($field('columnNumber', 'column-number')));
        self::put($kept, 'status', self::number($field('statusCode', 'status-code'), 999));

        return $kept;
    }

    /** @param array<string, string|int> $kept */
    private static function put(array &$kept, string $name, string|int|null $value): void
    {
        if ($value !== null && $value !== '') {
            $kept[$name] = $value;
        }
    }

    /**
     * The directive a violation broke, from the list or `other`: the first
     * word of `violated-directive`, which older browsers send with its value.
     */
    private static function directive(mixed $value): string
    {
        if (!\is_string($value)) {
            return 'other';
        }
        $name = strtolower(explode(' ', trim($value), 2)[0]);

        return \in_array($name, self::DIRECTIVES, true) ? $name : 'other';
    }

    /** A blocked URL as a URL is kept, or the keyword a browser sends in its place. */
    private static function blocked(mixed $value): ?string
    {
        if (\is_string($value) && preg_match(self::KEYWORD, $value) === 1) {
            return $value;
        }

        return self::url($value);
    }

    /**
     * A URL down to its scheme, host, port and path -- no credentials, no
     * query, no fragment, where a token in an address would be -- with the
     * path cut short and made plain. A scheme that is not the web's, such
     * as an extension's, is kept as its scheme alone: it says where the
     * violation came from, and its path is no one's business here.
     */
    private static function url(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '' || \strlen($value) > 4096) {
            return null;
        }

        $parts = parse_url($value);
        $scheme = \is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        if (preg_match('/^[a-z][a-z0-9+.-]{0,31}\z/', $scheme) !== 1) {
            return null;
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            return $scheme;
        }

        $host = strtolower($parts['host'] ?? '');
        if (preg_match('/^[a-z0-9.-]{1,253}\z|^\[[0-9a-f:.]{2,45}\]\z/', $host) !== 1) {
            return null;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = self::text($parts['path'] ?? '/', self::MAX_PATH) ?? '/';

        return "{$scheme}://{$host}{$port}" . str_replace(' ', '%20', $path);
    }

    /**
     * Free text made plain: valid UTF-8 only, every control and formatting
     * character -- line breaks, bidi overrides, zero-width marks -- gone,
     * and at most $bytes of it, never splitting a character.
     */
    private static function text(mixed $value, int $bytes): ?string
    {
        if (!\is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $plain = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', '', $value);
        if (!\is_string($plain)) {
            return null;
        }
        $plain = trim($plain);

        return \strlen($plain) <= $bytes ? $plain : mb_strcut($plain, 0, $bytes, 'UTF-8');
    }

    /** A whole number from zero to $max, or nothing. */
    private static function number(mixed $value, int $max = 10_000_000): ?int
    {
        return \is_int($value) && $value >= 0 && $value <= $max ? $value : null;
    }
}
