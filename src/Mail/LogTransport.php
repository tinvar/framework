<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Support\Posix;

/**
 * Appends every message to a file, one JSON object a line, instead of
 * sending it: for development, where nothing should leave the machine, and
 * for a browser test, which reads what was "sent" from the file.
 *
 * Appended under an exclusive lock, so two workers' messages never
 * interleave inside a line, to a file only its owner can read (open()).
 *
 * Its readers, Bcc too, since a test asks who a message went to; and each
 * attachment's name, type, size and digest, never its bytes, which would
 * swell a log a test reads whole (ADR 0116).
 */
final readonly class LogTransport implements Transport
{
    public function __construct(private string $path) {}

    public function send(Message $message): void
    {
        $line = json_encode([
            'from' => $message->from->toHeader(),
            'to' => array_map(static fn (Address $a): string => $a->toHeader(), $message->to),
            'cc' => array_map(static fn (Address $a): string => $a->toHeader(), $message->cc),
            'bcc' => array_map(static fn (Address $a): string => $a->toHeader(), $message->bcc),
            'replyTo' => $message->replyTo?->toHeader(),
            'listUnsubscribe' => $message->unsubscribe?->headers()['List-Unsubscribe'] ?? null,
            'listUnsubscribePost' => $message->unsubscribe?->headers()['List-Unsubscribe-Post'] ?? null,
            'subject' => $message->subject,
            'html' => $message->html,
            'text' => $message->text,
            'attachments' => array_map(static fn (Attachment $a): array => [
                ...$a->describe(),
                'contentId' => $a->contentId,
                'sha256' => hash('sha256', $a->content),
            ], $message->attachments),
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $directory = \dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory} for the mail log.");
        }
        self::assertPrivate($directory);

        $handle = $this->open();
        try {
            // Not opened to append -- that would create a file wherever a
            // link swapped in pointed -- so the end is found under the lock.
            flock($handle, LOCK_EX);
            if (fseek($handle, 0, SEEK_END) !== 0 || fwrite($handle, $line . "\n") === false || !fflush($handle)) {
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
     * link, a one-click unsubscribe token. Its directory is this user's
     * alone (assertPrivate()), so nobody else can put anything at its name.
     * And even so, a new log is created only by an exclusive open under a
     * 0077 umask -- private from its first byte, and never through a link,
     * dangling or not -- and an existing one is opened without being
     * created, and written to only when it is a plain file of this user's
     * with no other name: not a link, not a directory, not another user's
     * file, nor a hard link to one of this user's own. One an older version
     * left readable is closed up.
     *
     * @return resource
     */
    private function open()
    {
        clearstatcache(true, $this->path);
        $handle = false;
        if (!file_exists($this->path) && !is_link($this->path)) {
            $previous = umask(0o077);
            try {
                $handle = @fopen($this->path, 'xb');
            } finally {
                umask($previous);
            }
        }
        // There already, or put there since it was looked for: opened only
        // once its name is seen to be a plain file of this user's, and
        // without O_CREAT, so a name swapped since creates nothing.
        if ($handle === false) {
            $named = @lstat($this->path);
            if ($named === false || !self::ownPlainFile($named)) {
                throw self::refused($this->path);
            }
            $handle = @fopen($this->path, 'r+b');
            if ($handle === false) {
                throw new \RuntimeException("Cannot open the mail log at {$this->path}.");
            }
        }

        // And the file opened is the one the name is now, so nothing swapped
        // in between the look and the open is written to.
        clearstatcache(true, $this->path);
        $opened = fstat($handle);
        $named = @lstat($this->path);
        if ($opened === false || $named === false || !self::ownPlainFile($opened) || !self::ownPlainFile($named)
            || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']
            || $opened['mode'] !== $named['mode'] || $opened['uid'] !== $named['uid']) {
            fclose($handle);
            throw self::refused($this->path);
        }
        // PHP has no fchmod(); the name is this very file, checked above.
        if (($opened['mode'] & 0o077) !== 0 && !chmod($this->path, 0o600)) {
            fclose($handle);
            throw new \RuntimeException("Cannot make the mail log at {$this->path} private; nothing is written to it.");
        }

        return $handle;
    }

    /**
     * Refuses a directory that is not this user's -- its owner can rename,
     * replace or plant anything in it, whatever its mode says now -- and one
     * of this user's that another user can write into, and so put a file or
     * a link at the log's name or swap one in between a look and an open,
     * unless it has the sticky bit, where nobody else can rename or remove
     * what this user made.
     */
    private static function assertPrivate(string $directory): void
    {
        clearstatcache(true, $directory);
        $stat = @stat($directory);
        if ($stat === false) {
            throw new \RuntimeException("Cannot read the mail log's directory, {$directory}.");
        }
        if ($stat['uid'] !== Posix::effectiveUser("the mail log's directory, {$directory}")) {
            throw new \RuntimeException(
                "The mail log's directory, {$directory}, is not this user's, so its owner could put a file or a link "
                . "at its name: keep the log in a directory of this user's alone.",
            );
        }
        $others = ($stat['mode'] & 0o022) !== 0;
        $sticky = ($stat['mode'] & 0o1000) !== 0;
        if ($others && !$sticky) {
            throw new \RuntimeException(
                "The mail log's directory, {$directory}, can be written by other users, who could put a file or a link "
                . "at its name: keep the log in a directory of this user's alone.",
            );
        }
    }

    /** @param array<array-key, int> $stat */
    private static function ownPlainFile(array $stat): bool
    {
        return ($stat['mode'] & 0o170000) === 0o100000
            && $stat['nlink'] === 1
            && $stat['uid'] === Posix::effectiveUser('the mail log');
    }

    private static function refused(string $path): \RuntimeException
    {
        return new \RuntimeException(
            "The mail log at {$path} is not a plain file of this user's alone -- a link, a directory, another "
            . "user's file, or a file with another name as well; nothing is written to it.",
        );
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
