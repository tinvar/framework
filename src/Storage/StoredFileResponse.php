<?php

declare(strict_types=1);

namespace Tinvar\Storage;

use Tinvar\Http\Response;

/**
 * A stored file sent as a response's body, as `/_file` and `/_download`
 * send one, without holding it whole anywhere on the way:
 *
 *   - from an object store, each chunk handed on as it arrives (readTo()),
 *     nothing gathered in memory or in the temporary directory first;
 *   - from this machine's disk, by its path, which the server can send
 *     itself with no worker writing it to a client that may stop reading
 *     (Sapi::forServer()), the file opened only for the stream that goes
 *     otherwise;
 *   - from anything else, read and streamed.
 *
 * A HEAD is the caller's: it sends the headers alone, and reads nothing.
 */
final class StoredFileResponse
{
    private const int CHUNK = 65_536;

    /**
     * A 200 with what is at $key as its body and $headers as its headers;
     * null when nothing is at $key now. A file gone once the body has begun
     * fails the stream, rather than ending it short of the length promised.
     *
     * @param array<string, string> $headers
     */
    public static function of(Storage $storage, string $key, array $headers): ?Response
    {
        if ($storage instanceof ObjectStore) {
            return Response::stream(static function (\Closure $sink) use ($storage, $key): void {
                if (!$storage->readTo($key, $sink)) {
                    throw new StorageException('A stored file was gone by the time it was sent.');
                }
            }, 200, $headers);
        }

        $path = $storage instanceof LocalStorage ? $storage->path($key) : null;
        $stream = null;
        if ($path === null) {
            $stream = $storage->read($key);
            if ($stream === null) {
                return null;
            }
        }

        $response = Response::stream(static function (\Closure $sink) use ($stream, $storage, $key): void {
            $stream ??= $storage->read($key) ?? throw new StorageException('A stored file was gone by the time it was sent.');
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

        return $path === null ? $response : $response->withFile($path);
    }
}
