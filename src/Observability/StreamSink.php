<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Lines appended to a file, or written to standard error.
 *
 * A file is opened for appending, so every writer's line lands at the end
 * whichever process or worker thread wrote it, and each line is written
 * under an exclusive lock, in one write: two FrankenPHP threads -- or the
 * server and its queue worker, sharing one file -- never interleave half
 * lines. Rotate it by copying and truncating (logrotate's `copytruncate`);
 * a writer keeps the file it opened.
 *
 * Opened on the first line, not when built: a worker that never logs never
 * touches the file. Created readable by its owner and group alone, from
 * its first byte, since even redacted, a log says who did what when.
 */
final class StreamSink implements LogSink
{
    /** @var resource|null */
    private $stream = null;

    private function __construct(
        private readonly string $target,
        private readonly bool $isFile,
    ) {}

    public static function file(string $path): self
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('#^[a-z][a-z0-9+.\-]*://#i', $path) === 1) {
            throw new \InvalidArgumentException('A log file is a path on this machine.');
        }

        return new self($path, true);
    }

    public static function stderr(): self
    {
        return new self('php://stderr', false);
    }

    public function write(string $line): void
    {
        $stream = $this->open();

        if (!$this->isFile) {
            fwrite($stream, $line . "\n");

            return;
        }

        flock($stream, LOCK_EX);
        try {
            if (fwrite($stream, $line . "\n") === false) {
                throw new \RuntimeException("Could not write to the log at {$this->target}.");
            }
            fflush($stream);
        } finally {
            flock($stream, LOCK_UN);
        }
    }

    /** @return resource */
    private function open()
    {
        if ($this->stream !== null) {
            return $this->stream;
        }

        if (!$this->isFile) {
            $stream = @fopen($this->target, 'wb');
            if ($stream === false) {
                throw new \RuntimeException("Could not open the log at {$this->target}.");
            }

            return $this->stream = $stream;
        }

        // Created under a umask that denies everyone else, not narrowed by
        // chmod() after: fopen() made it 0666 less the umask, and for the
        // moment before the chmod() another user could open it and keep
        // reading every line written after. The umask is the process's, so
        // it is only ever narrowed here -- a thread creating a file at the
        // same moment gets one no wider than it would have.
        $created = !file_exists($this->target);
        $previous = umask();
        umask($previous | 0o027);
        try {
            $stream = @fopen($this->target, 'ab');
        } finally {
            umask($previous);
        }
        if ($stream === false) {
            throw new \RuntimeException("Could not open the log at {$this->target}.");
        }
        // And then exactly 0640, whatever the umask: a stricter one would
        // keep the group out of a log it is meant to read.
        if ($created) {
            @chmod($this->target, 0640);
        }

        return $this->stream = $stream;
    }
}
