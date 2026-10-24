<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * One file a multipart request carried, as PHP received it: written to a
 * temporary file before any of the application ran, and removed when the
 * request ends.
 *
 * tinvar-spec.md: "client filenames are metadata only." So is the type the
 * client sent. Both are kept here because PHP reports them, and nothing in
 * the framework decides anything by them: the bytes are sniffed for their
 * type (Uploads\FileTypes), and the storage key is drawn at random.
 */
final readonly class UploadedFile
{
    /**
     * @param string $path       the temporary file; '' when PHP kept none (an error)
     * @param string $clientName what the client called it -- metadata only
     * @param string $clientType what the client said it was -- ignored
     * @param int    $error      one of PHP's UPLOAD_ERR_* constants
     * @param int    $size       the size PHP reported, in bytes
     */
    public function __construct(
        public string $path,
        public string $clientName,
        public string $clientType,
        public int $error,
        public int $size,
    ) {}

    public function isOk(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->path !== '';
    }

    /**
     * The files of $_FILES, held to one shape: a single file under a plain
     * name. PHP builds nested arrays for `file[]` and `file[a][b]`, which
     * nothing in the framework reads and every reader would have to guard
     * against, so those are left out, as Sapi leaves nested query values out.
     *
     * A file reported as received must be one PHP received in THIS request:
     * is_uploaded_file() says so, unless the caller -- a test -- says how to
     * tell. Without the check, anything able to put a path into these
     * globals would have the endpoint read, sniff and store a file of its
     * choosing from the server's own disk.
     *
     * @param array<mixed> $files $_FILES
     * @param (\Closure(string): bool)|null $isUploaded is_uploaded_file() unless given
     * @return array<string, self>
     */
    public static function fromGlobals(array $files, ?\Closure $isUploaded = null): array
    {
        $isUploaded ??= static fn (string $path): bool => is_uploaded_file($path);

        $out = [];
        foreach ($files as $name => $file) {
            if (!\is_string($name) || !\is_array($file)) {
                continue;
            }

            $path = $file['tmp_name'] ?? null;
            $clientName = $file['name'] ?? null;
            $clientType = $file['type'] ?? '';
            $error = $file['error'] ?? null;
            $size = $file['size'] ?? 0;
            if (!\is_string($path) || !\is_string($clientName) || !\is_string($clientType) || !\is_int($error) || !\is_int($size)) {
                continue;
            }

            if ($error === UPLOAD_ERR_OK && ($path === '' || !$isUploaded($path))) {
                continue;
            }

            $out[$name] = new self($error === UPLOAD_ERR_OK ? $path : '', $clientName, $clientType, $error, max(0, $size));
        }

        return $out;
    }
}
