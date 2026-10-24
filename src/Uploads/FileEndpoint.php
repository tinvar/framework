<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Render\TokenError;

/**
 * GET /_file/{token}
 *
 * Sends one stored file to the session a page minted its URL for
 * (FileUrls). The token is verified before anything is read: its
 * signature, its expiry, then the session and the user with their auth
 * epoch, each against this request. Any failure is a 404, the same for
 * every reason -- a URL copied into another browser, or kept past a
 * sign-out, is a file that is not there for its holder.
 *
 * The file goes out as the type it was sniffed as and nothing else, with
 * what keeps a browser from reading it as anything more: `nosniff`, a
 * sandboxing Content-Security-Policy that allows nothing to run, and an
 * image inline or anything else as a download. A file that is both a valid
 * image and something a browser could run -- a polyglot -- is still only
 * ever an image here.
 *
 * Behind the global stack, for the session, and the `files` throttle. Not
 * behind authentication: the token's user binding is exact, signed-out
 * included, so it already says who may use it.
 */
final readonly class FileEndpoint
{
    /** How much of a file is read at a time while it is sent. */
    private const int CHUNK = 65_536;

    /** A token is a path-sized string. */
    private const int MAX_TOKEN_BYTES = 4096;

    public function __construct(
        private Uploads $uploads,
        private AuthContext $auth,
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

        $token = substr($request->path, \strlen(FileUrls::PREFIX));
        if ($token === '' || \strlen($token) > self::MAX_TOKEN_BYTES || str_contains($token, '/')) {
            throw self::missing();
        }

        try {
            $file = FileToken::decode($token, $this->uploads->fileSigner, $this->uploads->now());
        } catch (TokenError) {
            throw self::missing();
        }

        $session = $this->uploads->sessionOf($request);
        if ($session === ''
            || !hash_equals($file->sessionHash, $this->uploads->sessionHash($session))
            || !hash_equals($file->authHash, $this->uploads->authHash($this->auth->id(), $this->auth->epoch()))) {
            throw self::missing();
        }

        $size = $this->uploads->storage->size($file->key);
        if ($size === null) {
            throw self::missing();
        }
        $stream = $this->uploads->storage->read($file->key);
        if ($stream === null) {
            throw self::missing();
        }

        $headers = [
            'content-type' => $file->type,
            'content-length' => (string) $size,
            'content-disposition' => self::disposition($file),
            'x-content-type-options' => 'nosniff',
            'content-security-policy' => "default-src 'none'; sandbox",
            'cross-origin-resource-policy' => 'same-origin',
            // Kept by no cache, the browser's included, as every
            // authenticated response is: client-held data is cleared on
            // sign-out, and a browser's HTTP cache is nothing a sign-out
            // can clear.
            'cache-control' => 'private, no-store',
            'vary' => 'Cookie',
        ];

        if ($request->method === 'HEAD') {
            fclose($stream);

            return Response::make(200, '', $headers);
        }

        return Response::stream(static function (\Closure $sink) use ($stream): void {
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
