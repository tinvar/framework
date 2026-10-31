<?php

declare(strict_types=1);

namespace Tinvar\Generators;

/**
 * A file of the application's a generator edits in place -- the renderer's
 * route table -- read only where it is a plain file, and written through a
 * file beside it, renamed over it, its mode kept. A symbolic link is never
 * read or written through.
 */
final class ProjectFile
{
    /** The source of $file, which must be a plain file. */
    public static function read(string $file): string
    {
        if (is_link($file) || !is_file($file)) {
            throw new RegistrationRefused(is_link($file) ? 'it is a symbolic link' : 'there is no such file');
        }
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RegistrationRefused('it could not be read');
        }

        return $source;
    }

    /** Writes $source over $file through a file beside it, keeping its mode. */
    public static function write(string $file, string $source): void
    {
        $temporary = \dirname($file) . '/.' . basename($file) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new RegistrationRefused('a file beside it could not be made to write through');
        }
        $ok = fwrite($handle, $source) === \strlen($source);
        $ok = fclose($handle) && $ok;
        $mode = fileperms($file);
        if ($ok && $mode !== false) {
            $ok = chmod($temporary, $mode & 0o7777);
        }
        if (!$ok || is_link($file) || !rename($temporary, $file)) {
            @unlink($temporary);

            throw new RegistrationRefused('it could not be written');
        }
    }
}
