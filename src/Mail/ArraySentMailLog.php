<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/** The sent-mail log in memory: for tests, and an application with no database. */
final class ArraySentMailLog implements SentMailLog
{
    /** @var array<string, int> key => when it was sent */
    private array $sent = [];

    public function record(SentMessage $sent, int $now): void
    {
        if ($sent->messageId === null || $sent->skipped) {
            return;
        }
        foreach ($sent->message->envelope() as $reader) {
            $this->sent[self::key($sent->transport, $sent->messageId, $reader)] = $now;
        }
    }

    public function wasSent(string $provider, string $messageId, string $recipient, int $now): bool
    {
        $at = $this->sent[self::key($provider, $messageId, $recipient)] ?? null;

        return $at !== null && $now - $at <= self::RETENTION;
    }

    public function readers(string $provider, string $messageId, int $now): int
    {
        $prefix = $provider . "\n" . $messageId . "\n";

        return \count(array_filter(
            $this->sent,
            static fn (int $at, string $key): bool => str_starts_with($key, $prefix) && $now - $at <= self::RETENTION,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    private static function key(string $provider, string $messageId, string $recipient): string
    {
        return $provider . "\n" . $messageId . "\n" . hash('sha256', strtolower($recipient));
    }
}
