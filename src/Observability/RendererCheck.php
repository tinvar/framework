<?php

declare(strict_types=1);

namespace Tinvar\Observability;

use Tinvar\Render\RendererHealth;

/**
 * The renderers answer: the renderer process's own health check, as the
 * spec's deployment asks of each process, and the pool's health in the
 * metrics.
 *
 * Asked as `renderer:check` asks (RendererHealth, ADR 0028): a `health`
 * frame alone on a connection, answered with the renderer's pid and how
 * many renders it has open and has finished. An answer proves its event
 * loop turns, which a connect alone does not. Nothing else is sent -- no
 * render, nothing of any request's.
 *
 * For /_ready, one renderer answering is enough: a pool's supervisor
 * replaces one that stops, and the others serve meanwhile, so an instance
 * with any renderer answering can take traffic. For /_metrics, every
 * renderer is reported by its slot in the pool -- a slot keeps its number
 * when its renderer is replaced, so the label set stays as small as the
 * pool:
 *
 *   tinvar_renderers                  sockets found
 *   tinvar_renderers_answering        renderers that answered
 *   tinvar_renderer_up{slot}          1 when that renderer answered
 *   tinvar_renderer_in_flight{slot}   renders open on it now
 *   tinvar_renderer_renders_total{slot} renders it has finished; starts
 *                                     again from 0 when it is replaced
 */
final class RendererCheck implements HealthCheck, Collector
{
    private readonly RendererHealth $health;

    /**
     * @param string $path the renderer's socket, or a pool's directory: TINVAR_SOCKET
     * @param RendererHealth|null $health asks each renderer; built with the
     *        application's codec, and a timeout short enough that a stuck
     *        renderer does not hold a probe for long
     */
    public function __construct(
        private readonly string $path,
        ?RendererHealth $health = null,
        private readonly string $name = 'renderer',
    ) {
        $this->health = $health ?? new RendererHealth(timeoutSeconds: 1.0);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function check(): void
    {
        $answers = $this->health->checkAll($this->path);
        if ($answers === []) {
            throw new \RuntimeException("No renderer is listening at {$this->path}.");
        }

        $failures = [];
        foreach ($answers as $socket => $answer) {
            if (\is_array($answer)) {
                return;
            }
            $failures[] = basename($socket) . ": {$answer}";
        }

        throw new \RuntimeException('No renderer answered (' . implode('; ', $failures) . ').');
    }

    public function collect(): array
    {
        try {
            $answers = $this->health->checkAll($this->path);
        } catch (\Throwable) {
            // A pool directory that fails its trust check, say: nothing in
            // it is asked, and nothing in it is answering.
            $answers = [];
        }

        $samples = [
            ['name' => 'tinvar_renderers', 'help' => 'Renderer sockets found.', 'value' => \count($answers)],
            ['name' => 'tinvar_renderers_answering', 'help' => 'Renderers that answered a health check.', 'value' => \count(array_filter($answers, is_array(...)))],
        ];

        // One renderer per slot: during a rolling restart a slot has two
        // sockets, the renderer draining and its replacement, and two series
        // of one label set are an exposition a scraper refuses. The newest
        // -- the highest sequence -- is the slot's.
        $newest = [];
        foreach (array_keys($answers) as $socket) {
            [$slot, $sequence] = self::slotAndSequence($socket);
            if (!isset($newest[$slot]) || $sequence > $newest[$slot][1]) {
                $newest[$slot] = [$socket, $sequence];
            }
        }
        ksort($newest, SORT_NATURAL);

        foreach ($newest as [$socket]) {
            $answer = $answers[$socket];
            $slot = ['slot' => self::slotAndSequence($socket)[0]];
            $samples[] = ['name' => 'tinvar_renderer_up', 'help' => 'Whether a renderer answered a health check.', 'labels' => $slot, 'value' => \is_array($answer) ? 1 : 0];
            if (!\is_array($answer)) {
                continue;
            }
            $samples[] = ['name' => 'tinvar_renderer_in_flight', 'help' => 'Renders open on a renderer.', 'labels' => $slot, 'value' => $answer['inFlight']];
            $samples[] = [
                'name' => 'tinvar_renderer_renders_total',
                'help' => 'Renders a renderer has finished, from 0 again when it is replaced.',
                'type' => 'counter',
                'labels' => $slot,
                'value' => $answer['served'],
            ];
        }

        return $samples;
    }

    /**
     * A pool's socket by its slot -- `renderer-<slot>-<sequence>.sock`, the
     * sequence changing with each replacement, and growing (pool.js
     * #launch()) -- and a lone renderer's as `0`, there being one slot.
     *
     * @return array{string, int} the slot, and the sequence
     */
    private static function slotAndSequence(string $socket): array
    {
        return preg_match('/^renderer-(\d{1,4})-(\d{1,18})\.sock\z/', basename($socket), $m) === 1 ? [$m[1], (int) $m[2]] : ['0', 0];
    }
}
