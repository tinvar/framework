<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\Request;
use Tinvar\Http\RequestAttributes;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;
use Tinvar\Storage\FileTypes;
use Tinvar\Storage\StorageKey;

/**
 * URLs a page hands its viewer for the stored files it shows: an avatar,
 * a receipt.
 *
 * The loader that calls this has decided the viewer may see the file; the
 * URL carries that decision to /_file for this session alone, as this user
 * at this auth epoch, in the tenant and the panel the page ran in
 * (FileToken). It is not a public link, and a copy of it in another
 * browser, tenant or panel is a 404.
 *
 * Minted for windows of ten minutes, not for the second: the same file
 * shown to the same viewer within one window gets the same URL, so a page
 * drawn again -- after an action, on a client navigation that keeps the
 * header -- hands React the src it already has, and the image is not
 * fetched again.
 */
final readonly class FileUrls
{
    public const string PREFIX = '/_file/';

    /** Where a public file's URL is: under PREFIX, and never a session token, which holds no slash. */
    public const string PUBLIC_PREFIX = '/_file/public/';

    /** The width of the window a public URL is minted for: a day. */
    private const int PUBLIC_WINDOW = 86_400;

    /** The width of the window a URL is minted for, in seconds. */
    private const int WINDOW = 600;

    public function __construct(
        private Uploads $uploads,
        private AuthContext $auth,
        private ?Orm $orm = null,
        private ?PanelContext $panels = null,
    ) {}

    /**
     * The URL of the file at $key, of the type it was stored as, for the
     * viewer $request comes from; null when that request has no session.
     *
     * @param string $name what to offer a download as; the key's own name when ''
     */
    public function url(Request $request, string $key, string $type, string $name = '', int $ttl = FileToken::DEFAULT_TTL): ?string
    {
        StorageKey::assertValid($key);
        if (!FileTypes::known($type)) {
            throw new \InvalidArgumentException("'{$type}' is not a type /_file serves.");
        }
        if ($ttl < 1 || $ttl > FileToken::MAX_TTL - self::WINDOW) {
            throw new \InvalidArgumentException('A file URL lives between a second and a day.');
        }

        $session = $this->uploads->sessionOf($request);
        if ($session === '') {
            return null;
        }

        // The window's start, and an expiry at least $ttl from now whenever
        // in the window this runs.
        $issuedAt = intdiv($this->uploads->now(), self::WINDOW) * self::WINDOW;
        $token = new FileToken(
            key: $key,
            type: $type,
            name: UploadName::clean($name === '' ? basename($key) : $name),
            sessionHash: $this->uploads->sessionHash($session),
            authHash: $this->uploads->authHash($this->auth->id(), $this->auth->epoch()),
            expiresAt: $issuedAt + self::WINDOW + $ttl,
            issuedAt: $issuedAt,
            tenant: $this->orm === null ? TenantClaim::NONE : TenantClaim::current($this->orm),
            panel: $this->panels?->currentOrNull() ?? '',
            tenantSource: self::source($request),
        );

        return self::PREFIX . $token->encode($this->uploads->fileSigner);
    }

    /** The #[TenantFrom] source that placed the page, or '' for none (EnterTenant). */
    private static function source(Request $request): string
    {
        $source = $request->attribute(RequestAttributes::TENANT_SOURCE);

        return \is_string($source) ? $source : '';
    }

    /**
     * A URL anyone may fetch the file at $key by, until it runs out: for a
     * file the application means to show the world -- a product's photo --
     * and only under a prefix it named public (Uploads::$publicPrefixes).
     * Not bound to a session, so it works in a mail, a feed or another
     * site; and minted for windows of a day, so a page drawn again names
     * the same URL and caches keep it.
     *
     * @param string $name what to offer a download as; the key's own name when ''
     * @throws \LogicException for a key under no public prefix: a file the application did not opt in
     */
    public function publicUrl(string $key, string $type, string $name = '', int $ttl = PublicFileToken::DEFAULT_TTL): string
    {
        StorageKey::assertValid($key);
        if (!FileTypes::known($type)) {
            throw new \InvalidArgumentException("'{$type}' is not a type /_file serves.");
        }
        if (!$this->uploads->isPublic($key)) {
            throw new \LogicException('A public URL is minted only for a file under a prefix Uploads names public; this one is under none.');
        }
        if ($ttl < 1 || $ttl > PublicFileToken::MAX_TTL - self::PUBLIC_WINDOW) {
            throw new \InvalidArgumentException('A public file URL lives between a second and a month.');
        }

        $issuedAt = intdiv($this->uploads->now(), self::PUBLIC_WINDOW) * self::PUBLIC_WINDOW;
        $token = new PublicFileToken(
            key: $key,
            type: $type,
            name: UploadName::clean($name === '' ? basename($key) : $name),
            expiresAt: $issuedAt + self::PUBLIC_WINDOW + $ttl,
            issuedAt: $issuedAt,
        );

        return self::PUBLIC_PREFIX . $token->encode($this->uploads->fileSigner);
    }
}
