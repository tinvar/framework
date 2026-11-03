<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

use Tinvar\Storage\Storage;
use Tinvar\Storage\StorageKey;

/**
 * Large files fetched from a URL into storage, each held to the digest it
 * was asked for: tinvar-spec.md's "ISO downloads", and any other image,
 * archive or release the application is told to fetch.
 *
 *     $file = $files->download($url, sha256: $published, prefix: 'images', extension: 'iso');
 *
 * Through OutboundHttp, so the destination is resolved once, vetted against
 * the policy and pinned, and each redirect -- a mirror network sends a few
 * -- vetted again. https only: a download is fetched over a connection
 * whose certificate was checked, as well as against its digest.
 *
 * The body is never held in memory, nor gathered in the temporary
 * directory first. It is written to the store as it arrives -- through a
 * StorageWriter, which keeps nothing at the key until it is committed: a
 * file beside its destination on disk, a multipart upload on an object
 * store -- and hashed on the way, within its size cap and its time -- an
 * hour at most, the transfer stopped if it stalls. It is committed only
 * when the digest is the one asked for. The digest is not optional: a file
 * whose bytes nobody vouched for is not one to boot a machine from, and a
 * URL alone vouches for nothing once a mirror or a name changes hands. A
 * file that fails is never kept: what was written is given up.
 *
 * The key is the prefix asked for, the tenant it was stored for
 * (StorageKey::tenantSegment()), a name drawn at random, and the extension
 * asked for: nothing the URL or the server said reaches it.
 */
final readonly class RemoteFiles
{
    /** The default cap: 16 GiB, larger than any installer image. */
    public const int MAX_BYTES = 17_179_869_184;

    /** The default time a download may take. */
    public const float TIMEOUT = OutboundRequest::MAX_STREAM_TIMEOUT;

    public function __construct(private OutboundHttp $http, private Storage $storage) {}

    /**
     * Fetches $url, checks it against $sha256, and stores it.
     *
     * @param string $sha256 the file's SHA-256 in hex, as its publisher gives it
     * @param string $prefix a storage prefix (StorageKey::PREFIX)
     * @param string $extension the stored file's extension: lowercase letters and digits
     * @param string $tenant the tenant it is stored for, whose segment its key carries; '' for none
     * @throws OutboundRefused for a URL or destination that is refused
     * @throws OutboundFailed when the transfer did not complete, or was over the cap
     * @throws RemoteContentRefused for a status other than a 2xx, or a digest that does not match
     */
    public function download(
        string $url,
        string $sha256,
        string $prefix,
        string $extension,
        int $maxBytes = self::MAX_BYTES,
        float $timeout = self::TIMEOUT,
        string $tenant = '',
    ): RemoteFile {
        $sha256 = strtolower($sha256);
        if (preg_match('/^[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new \InvalidArgumentException('A download is checked against a SHA-256 digest: 64 hex digits.');
        }
        if (!StorageKey::isValidPrefix($prefix)) {
            throw new \InvalidArgumentException("'{$prefix}' is not a storage prefix.");
        }
        if (preg_match('/^[a-z0-9]{1,8}\z/', $extension) !== 1) {
            throw new \InvalidArgumentException("'{$extension}' is not an extension: up to 8 lowercase letters and digits.");
        }
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('A download\'s cap is at least a byte.');
        }

        $target = Target::parse($url);
        if ($target->scheme !== 'https') {
            throw new OutboundRefused('scheme', "{$target->origin()} is not https; a download is fetched over a connection whose certificate was checked.");
        }

        $key = StorageKey::generate($prefix, $extension, $tenant);
        $file = $this->storage->writer($key);

        try {
            $hash = hash_init('sha256');
            $bytes = 0;
            $response = $this->http->send(new OutboundRequest(
                'GET',
                $url,
                ['accept' => '*/*'],
                timeout: $timeout,
                maxResponseBytes: $maxBytes,
                maxRedirects: OutboundRequest::MAX_REDIRECTS,
                sink: static function (string $chunk) use ($file, $hash, &$bytes): void {
                    $file->write($chunk);
                    hash_update($hash, $chunk);
                    $bytes += \strlen($chunk);
                },
            ));

            if (!$response->successful()) {
                throw new RemoteContentRefused(RemoteContentRefused::STATUS, "GET {$target->origin()} answered {$response->status}.", $response->status);
            }
            if (!hash_equals($sha256, hash_final($hash))) {
                throw new RemoteContentRefused(RemoteContentRefused::CHECKSUM, "The file from {$target->origin()} is not the one its digest names; it was not stored.", $response->status);
            }

            $file->commit();

            return new RemoteFile($key, $bytes, $sha256, $response->milliseconds);
        } finally {
            // Nothing after a commit; everything written before a failure.
            $file->abort();
        }
    }
}
