<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * Appends every message to a file, one JSON object a line, instead of
 * sending it: for development, where nothing should leave the machine, and
 * for a browser test, which reads what was "sent" from the file.
 *
 * Appended under an exclusive lock, so two workers' messages never
 * interleave inside a line.
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
            'subject' => $message->subject,
            'html' => $message->html,
            'text' => $message->text,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $directory = \dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory} for the mail log.");
        }
        if (file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write the mail log at {$this->path}.");
        }
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
