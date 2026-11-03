<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\EntryContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;
use Tinvar\Render\TokenError;
use Tinvar\Storage\FileTypes;
use Tinvar\Storage\StorageKey;
use Tinvar\Storage\ObjectStore;
use Tinvar\Storage\StoredFileResponse;

/**
 * GET /_file/{token}
 *
 * Sends one stored file to the session a page minted its URL for
 * (FileUrls). The token is verified before anything is read: its
 * signature and its expiry. Then the request is put where the page was --
 * the tenant, by the #[TenantFrom] source the token names, and the panel,
 * with its guard, membership check and stack, as EntryContext puts any
 * entry point there -- and the token held to it: the tenant claim, the
 * panel, the session and the user with their auth epoch. Any failure is a
 * 404, the same for every reason -- a URL copied into another browser, kept
 * past a sign-out, or opened in another tenant or panel is a file that is
 * not there for its holder.
 *
 * The file goes out as the type it was sniffed as and nothing else, with
 * what keeps a browser from reading it as anything more: `nosniff`, a
 * sandboxing Content-Security-Policy that allows nothing to run, and an
 * image inline or anything else as a download. A file that is both a valid
 * image and something a browser could run -- a polyglot -- is still only
 * ever an image here.
 *
 * A file in an object store is streamed from it, chunk by chunk, under the
 * same headers -- or, where Uploads says FileDelivery::Redirect, sent there
 * by a URL presigned for a minute that names the type and disposition.
 *
 * Behind the global stack, for the session, and the `files` throttle. Not
 * behind authentication: the token's user binding is exact, signed-out
 * included, so it already says who may use it.
 */
final readonly class FileEndpoint
{
    /** A token is a path-sized string. */
    private const int MAX_TOKEN_BYTES = 4096;

    public function __construct(
        private Uploads $uploads,
        private AuthContext $auth,
        private ?EntryContext $entry = null,
        private ?Orm $orm = null,
        private ?PanelContext $panels = null,
    ) {}

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, FileUrls::PREFIX);
    }

    public static function assertMethod(Request $request): void
    {
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            throw new HttpException(405, 'Files are fetched with GET.', 'method_not_allowed', ['allow' => 'GET, HEAD']);
        }
    }

    public function handle(Request $request): Response
    {
        self::assertMethod($request);
        if (str_starts_with($request->path, FileUrls::PUBLIC_PREFIX)) {
            return $this->handlePublic($request);
        }

        $token = substr($request->path, \strlen(FileUrls::PREFIX));
        if ($token === '' || \strlen($token) > self::MAX_TOKEN_BYTES || str_contains($token, '/')) {
            throw self::missing();
        }

        try {
            $file = FileToken::decode($token, $this->uploads->fileSigner, $this->uploads->now());
        } catch (TokenError) {
            throw self::missing();
        }

        // Where the page was: its tenant by the source it was read by, its
        // panel with everything the panel puts in front of its pages.
        $stack = [];
        if ($this->entry !== null) {
            try {
                $stack = $this->entry->stackFor(
                    $file->tenantSource === '' ? null : $file->tenantSource,
                    $file->panel === '' ? null : $this->entry->panelNamed($file->panel),
                );
            } catch (\LogicException) {
                // A source or a panel this application no longer has.
                throw self::missing();
            }
        }

        return new Pipeline($stack)->run($request, function (Request $inner) use ($file): Response {
            $this->assertBinding($inner, $file);

            return $this->send($inner, $file);
        });
    }

    /**
     * A file anyone may fetch, by a URL FileUrls::publicUrl() minted: its
     * signature, its expiry, and its key still under a prefix the
     * application serves publicly. No session, tenant or panel is asked;
     * every header that keeps a browser from reading the file as anything
     * more still is.
     */
    private function handlePublic(Request $request): Response
    {
        $token = substr($request->path, \strlen(FileUrls::PUBLIC_PREFIX));
        if ($token === '' || \strlen($token) > self::MAX_TOKEN_BYTES || str_contains($token, '/')) {
            throw self::missing();
        }

        $now = $this->uploads->now();
        try {
            $public = PublicFileToken::decode($token, $this->uploads->fileSigner, $now);
        } catch (TokenError) {
            throw self::missing();
        }
        if (!$this->uploads->isPublic($public->key)) {
            throw self::missing();
        }

        // Kept by caches for an hour at most, and never past the URL: a
        // prefix taken out of the public list, or a file removed, stops
        // being served within it.
        $maxAge = max(0, min(3600, $public->expiresAt - $now));

        return $this->send($request, new FileToken($public->key, $public->type, $public->name, '', '', $public->expiresAt, $public->issuedAt), $maxAge);
    }

    /** The token agrees with where the request is, and who it comes from; it can put it nowhere. */
    private function assertBinding(Request $request, FileToken $file): void
    {
        $session = $this->uploads->sessionOf($request);
        if ($session === ''
            || ($this->orm === null ? TenantClaim::NONE : TenantClaim::current($this->orm)) !== $file->tenant
            // A key written for a tenant is that tenant's alone, whatever
            // signed it; one written before keys named one is held by the
            // token above, as it always was.
            || !StorageKey::belongsTo($file->key, $file->tenant)
            || ($this->panels?->currentOrNull() ?? '') !== $file->panel
            || !hash_equals($file->sessionHash, $this->uploads->sessionHash($session))
            || !hash_equals($file->authHash, $this->uploads->authHash($this->auth->id(), $this->auth->epoch()))) {
            throw self::missing();
        }
    }

    /** @param int|null $publicFor seconds a public file may be cached for; null for a private one */
    private function send(Request $request, FileToken $file, ?int $publicFor = null): Response
    {
        $storage = $this->uploads->storage;
        $disposition = self::disposition($file);
        $cache = $publicFor === null ? 'private, no-store' : "public, max-age={$publicFor}";

        if ($storage instanceof ObjectStore && $this->uploads->delivery === FileDelivery::Redirect) {
            if (!$storage->exists($file->key)) {
                throw self::missing();
            }

            $redirect = Response::location($storage->presignedGet($file->key, FileDelivery::REDIRECT_TTL, [
                'content-type' => $file->type,
                'content-disposition' => $disposition,
                'cache-control' => $cache,
            ]), 302)->withHeader('cache-control', 'private, no-store')
                ->withHeader('referrer-policy', 'no-referrer');

            return $publicFor === null ? $redirect->withHeader('vary', 'Cookie') : $redirect;
        }

        $size = $storage->size($file->key);
        if ($size === null) {
            throw self::missing();
        }

        $headers = [
            'content-type' => $file->type,
            'content-length' => (string) $size,
            'content-disposition' => $disposition,
            'x-content-type-options' => 'nosniff',
            'content-security-policy' => "default-src 'none'; sandbox",
            // A public file is one other sites may show; a private one is
            // this origin's alone.
            'cross-origin-resource-policy' => $publicFor === null ? 'same-origin' : 'cross-origin',
            // A private file kept by no cache, the browser's included, as
            // every authenticated response is: client-held data is cleared
            // on sign-out, and a browser's HTTP cache is nothing a sign-out
            // can clear.
            'cache-control' => $cache,
            ...($publicFor === null ? ['vary' => 'Cookie'] : []),
        ];

        if ($request->method === 'HEAD') {
            return Response::make(200, '', $headers);
        }

        return StoredFileResponse::of($storage, $file->key, $headers) ?? throw self::missing();
    }

    /**
     * Inline for an image a browser shows; a download for anything else,
     * under the cleaned name -- ASCII in `filename`, the whole name in
     * `filename*` -- so no character in it can end the header early.
     */
    private static function disposition(FileToken $file): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $file->name);

        return \sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            FileTypes::isImage($file->type) ? 'inline' : 'attachment',
            $ascii === '' ? UploadName::FALLBACK : $ascii,
            rawurlencode($file->name),
        );
    }

    private static function missing(): HttpException
    {
        return new HttpException(404, 'No file for this URL.', 'not_found');
    }
}
