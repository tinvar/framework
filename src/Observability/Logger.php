<?php

declare(strict_types=1);

namespace Tinvar\Observability;

use Tinvar\Http\HttpException;

/**
 * Structured logs: one JSON object a line, every one carrying the id of the
 * request it was written in.
 *
 *   {"time":"2026-10-24T09:14:03.512Z","level":"error","message":"...","source":"php",
 *    "request_id":"4bf92f3577b34da6a3ce929d0e0e4736", ...context}
 *
 * JSON rather than text so a field is a field: a message cannot forge a
 * second line, since a newline inside it is written as `\n`, and a shipper
 * reads `status` without a pattern. The renderer writes the same shape with
 * `"source":"renderer"` and the same `request_id`, so one query over both
 * finds everything one request did on either side of the socket.
 *
 * Everything written passes through the Redactor first -- the message and
 * every context value -- and nothing about writing a line can fail the
 * request it describes: a sink that throws is reported to PHP's own error
 * log, once, and the line is dropped.
 */
final class Logger
{
    /** @var array<string, int> */
    public const array LEVELS = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
    ];

    /** Fields the line itself owns, which a context entry of the same name never replaces. */
    private const array RESERVED = ['time', 'level', 'message', 'source', 'request_id'];

    private readonly int $threshold;

    /** @var (\Closure(): array<string, string>)|null */
    private ?\Closure $fields = null;

    private bool $sinkFailed = false;

    /**
     * @param string $level the least severe level written
     * @param string $source what wrote the line: `php` here, `renderer` in the renderer's
     * @param (\Closure(): float)|null $clock for tests: the time, as microtime(true)
     */
    public function __construct(
        private readonly LogSink $sink,
        private readonly Redactor $redactor = new Redactor(),
        string $level = 'info',
        private readonly string $source = 'php',
        private readonly ?\Closure $clock = null,
    ) {
        if (!isset(self::LEVELS[$level])) {
            throw new \InvalidArgumentException("No log level '{$level}'; use one of " . implode(', ', array_keys(self::LEVELS)) . '.');
        }
        $this->threshold = self::LEVELS[$level];
    }

    /**
     * Where every line's request fields come from: Telemetry hands over its
     * current request's id here, so code that logs never has to.
     *
     * @param \Closure(): array<string, string> $fields
     */
    public function contextFrom(\Closure $fields): void
    {
        $this->fields = $fields;
    }

    public function enabled(string $level): bool
    {
        return (self::LEVELS[$level] ?? PHP_INT_MAX) >= $this->threshold;
    }

    /** @param array<array-key, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<array-key, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<array-key, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<array-key, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * A failure, under the digest the client was shown, so the reference on
     * an error page finds this line.
     *
     * @param array<array-key, mixed> $context
     */
    public function exception(\Throwable $e, ?string $digest = null, string $level = 'error', array $context = []): void
    {
        $this->log($level, $e::class . ': ' . $e->getMessage(), [
            ...$context,
            ...($digest === null ? [] : ['digest' => $digest]),
            'exception' => $e,
        ]);
    }

    /**
     * This logger as the Kernel's `log:` closure: every failure the exception
     * handler classifies, under its digest. A refusal -- a 404, a 403 --
     * is the request's answer rather than something gone wrong, so it is
     * written at debug; anything else at error.
     *
     * @return \Closure(\Throwable, string): void
     */
    public function forExceptions(): \Closure
    {
        return function (\Throwable $e, string $digest): void {
            $answered = $e instanceof HttpException && $e->status < 500;
            $this->exception($e, $digest, $answered ? 'debug' : 'error');
        };
    }

    /** @param array<array-key, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled($level)) {
            return;
        }

        try {
            $line = [
                'time' => $this->time(),
                'level' => $level,
                'message' => $this->redactor->text($message),
                'source' => $this->source,
                ...($this->fields === null ? [] : ($this->fields)()),
            ];
            foreach ($this->redactor->context($context) as $key => $value) {
                if (!\in_array($key, self::RESERVED, true)) {
                    $line[$key] = $value;
                }
            }

            $this->sink->write((string) json_encode(
                $line,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR,
            ));
        } catch (\Throwable $e) {
            // Once: a log that cannot be written would otherwise say so on
            // every request, into the very log that is failing.
            if (!$this->sinkFailed) {
                $this->sinkFailed = true;
                error_log('[tinvar] the log could not be written: ' . $e::class . ': ' . $this->redactor->text($e->getMessage()));
            }
        }
    }

    /** Now, as RFC 3339 in UTC to the millisecond. */
    private function time(): string
    {
        $now = $this->clock === null ? microtime(true) : ($this->clock)();
        $seconds = (int) floor($now);

        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', (int) (($now - $seconds) * 1000));
    }
}
