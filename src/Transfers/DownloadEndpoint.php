<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Attributes\Export;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\EntryContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\EntryGates;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;
use Tinvar\Render\TokenError;
use Tinvar\Routing\HostMatch;
use Tinvar\Storage\LocalStorage;
use Tinvar\Storage\StorageKey;

/**
 * GET /_download/{token}
 *
 * Sends the file an export wrote to the user its link was minted for
 * (DownloadToken). In order, every refusal the same 404:
 *
 *   1. The token's signature and expiry, before anything else is read.
 *   2. The export it names, as the registry has it now; then that export's
 *      own context, as starting it would set it -- its tenant source, its
 *      panel's guard, membership check and stack, its own middleware -- so
 *      a link opened in a browser not signed in meets the panel's guard,
 *      which sends its reader to sign in first.
 *   3. The token's binding against that context: the tenant claim, the
 *      panel, the signed-in user on their auth epoch, and the host the
 *      export was started on. A link from before a role change, forwarded
 *      to anyone else, or opened on another host, opens nothing.
 *   4. The record: this user's, here, finished, its file still kept, and
 *      the very file the token names.
 *
 * The file goes out as what it is and nothing more: CSV or JSON lines, as
 * an attachment, under `nosniff` and a sandboxing policy, kept by no cache.
 * Behind the global stack for the session, and the `files` throttle; no
 * CSRF, as a GET changes nothing.
 */
final readonly class DownloadEndpoint
{
    public const string PREFIX = '/_download/';

    /** How much of a file is read at a time while it is sent. */
    private const int CHUNK = 65_536;

    /** A token is a path-sized string. */
    private const int MAX_TOKEN_BYTES = 4096;

    private const array TYPES = [
        Export::CSV => 'text/csv; charset=utf-8',
        Export::JSONL => 'application/x-ndjson; charset=utf-8',
    ];

    /**
     * @param \Closure(string): Middleware $middlewareResolver builds an export's own middleware
     * @param list<class-string> $globalMiddleware already run around this endpoint
     */
    public function __construct(
        private Transfers $transfers,
        private EntryContext $entry,
        private AuthContext $auth,
        private Orm $orm,
        private \Closure $middlewareResolver,
        private ?PanelContext $panels = null,
        private array $globalMiddleware = [],
    ) {}

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, self::PREFIX);
    }

    public static function assertMethod(Request $request): void
    {
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            throw new HttpException(405, 'Downloads are fetched with GET.', 'method_not_allowed', ['allow' => 'GET, HEAD']);
        }
    }

    public function handle(Request $request): Response
    {
        self::assertMethod($request);

        $raw = substr($request->path, \strlen(self::PREFIX));
        if ($raw === '' || \strlen($raw) > self::MAX_TOKEN_BYTES || str_contains($raw, '/')) {
            throw self::missing();
        }

        try {
            $token = DownloadToken::decode($raw, $this->transfers->signer, $this->transfers->now());
        } catch (TokenError) {
            throw self::missing();
        }

        $export = $this->transfers->registry->export($token->name) ?? throw self::missing();
        $stack = [
            ...$this->entry->stackFor($export->tenantFrom, $this->entry->panelFor($export->panels, $export->class), $export->host ?? false),
            ...array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                array_values(array_diff($export->middleware, $this->globalMiddleware)),
            ),
        ];

        return new Pipeline($stack)->run($request, fn (Request $inner): Response => $this->send($inner, $token));
    }

    private function send(Request $request, DownloadToken $token): Response
    {
        // Checked, never applied: the token agrees with where the request
        // is, and can put it nowhere.
        $user = $this->auth->id();
        if ($user === null
            || !hash_equals($token->host, HostMatch::hostOf($request))
            || TenantClaim::current($this->orm) !== $token->tenant
            || ($this->panels?->currentOrNull() ?? '') !== $token->panel
            || !hash_equals($token->authHash, $this->transfers->authHash($user, $this->auth->epoch()))) {
            throw self::missing();
        }
        // The export's gates (#[RequiresPlan]), once the token holds: a
        // caller it does not hold for learns nothing of them (ADR 0150).
        EntryGates::enforce($request);

        $record = $this->transfers->store->find($token->transfer, $token->tenant, $token->panel, $user);
        if ($record === null
            || !$record->isDownloadable($this->transfers->now())
            || !hash_equals($record->key, $token->key)
            || !StorageKey::belongsTo($record->key, $token->tenant)
            || $record->host !== $token->host
            || $record->name !== $token->name) {
            throw self::missing();
        }

        $size = $this->transfers->storage->size($record->key);
        $stream = $size === null ? null : $this->transfers->storage->read($record->key);
        if ($size === null || $stream === null) {
            throw self::missing();
        }

        $headers = [
            'content-type' => self::TYPES[$record->format] ?? 'application/octet-stream',
            'content-length' => (string) $size,
            'content-disposition' => self::disposition($record->fileName),
            'x-content-type-options' => 'nosniff',
            'content-security-policy' => "default-src 'none'; sandbox",
            'cross-origin-resource-policy' => 'same-origin',
            'referrer-policy' => 'no-referrer',
            'cache-control' => 'private, no-store',
            'vary' => 'Cookie',
        ];

        if ($request->method === 'HEAD') {
            fclose($stream);

            return Response::make(200, '', $headers);
        }

        $response = Response::stream(static function (\Closure $sink) use ($stream): void {
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, self::CHUNK);
                    if ($chunk === false) {
                        break;
                    }
                    if ($chunk !== '') {
                        $sink($chunk);
                    }
                }
            } finally {
                fclose($stream);
            }
        }, 200, $headers);

        // A file on this machine's disk is one the server can send itself,
        // with no worker writing it to a client that may stop reading
        // (Sapi::forServer()). The stream above is what goes otherwise.
        $storage = $this->transfers->storage;
        $path = $storage instanceof LocalStorage ? $storage->path($record->key) : null;

        return $path === null ? $response : $response->withFile($path);
    }

    /** Always an attachment, under a name made only of what no header can be ended by. */
    private static function disposition(string $name): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return \sprintf('attachment; filename="%s"', $ascii === '' ? 'export' : $ascii);
    }

    private static function missing(): HttpException
    {
        return new HttpException(404, 'No file for this link.', 'not_found');
    }
}
