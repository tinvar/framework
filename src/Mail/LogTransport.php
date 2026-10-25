<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * Appends every message to a file, one JSON object a line, instead of
 * sending it: for development, where nothing should leave the machine, and
 * for a browser test, which reads what was "sent" from the file.
 *
 * Appended under an exclusive lock, so two workers' messages never
 * interleave inside a line, to a file only its owner can read (open()).
 */
final readonly class LogTransport implements Transport
{
    public function __construct(private string $path) {}

    public function send(Message $message): void
    {
        $line = json_encode([
            'from' => $message->from->toHeader(),
            'to' => array_map(static fn (Address $a): string => $a->toHeader(), $message->to),
            'replyTo' => $message->replyTo?->toHeader(),
            'listUnsubscribe' => $message->unsubscribe?->headers()['List-Unsubscribe'] ?? null,
            'listUnsubscribePost' => $message->unsubscribe?->headers()['List-Unsubscribe-Post'] ?? null,
            'subject' => $message->subject,
            'html' => $message->html,
            'text' => $message->text,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $directory = \dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory} for the mail log.");
        }

        $handle = $this->open();
        try {
            flock($handle, LOCK_EX);
            if (fwrite($handle, $line . "\n") === false || !fflush($handle)) {
                throw new \RuntimeException("Cannot write the mail log at {$this->path}.");
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * The log, opened to append, and readable by this user alone.
     *
     * What it holds is every message whole: a password reset, a sign-in
     * link, a one-click unsubscribe token. So it is created under a 0077
     * umask, private from its first byte; an older one left readable is
     * closed up; and it is refused when the name is a link or the file is
     * another user's -- one planted at the log's name in a directory others
     * can write, such as the temporary one, to read the log from.
     *
     * @return resource
     */
    private function open()
    {
        $previous = umask(0o077);
        try {
            $handle = @fopen($this->path, 'ab');
        } finally {
            umask($previous);
        }
        if ($handle === false) {
            throw new \RuntimeException("Cannot open the mail log at {$this->path}.");
        }

        clearstatcache(true, $this->path);
        $opened = fstat($handle);
        $named = @lstat($this->path);
        if ($opened === false || $named === false
            || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']
            || (\function_exists('posix_geteuid') && $opened['uid'] !== posix_geteuid())) {
            fclose($handle);
            throw new \RuntimeException("The mail log at {$this->path} is a link, or another user's file; nothing is written to it.");
        }
        if (($opened['mode'] & 0o077) !== 0 && !chmod($this->path, 0o600)) {
            fclose($handle);
            throw new \RuntimeException("Cannot make the mail log at {$this->path} private; nothing is written to it.");
        }

        return $handle;
    }

    /**
     * Every message in a log, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $messages = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $message = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            if (\is_array($message)) {
                /** @var array<string, mixed> $message */
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
