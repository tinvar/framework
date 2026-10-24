<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * One thing /_ready asks about before it says the application can take
 * traffic: the database answers, the renderer answers.
 *
 * Each check bounds its own time -- a connect timeout, a statement that
 * returns at once -- since a readiness probe that hangs is read as a
 * failure anyway, only later. A renderer pool (ADR 0028) brings a check of
 * its own here in place of RendererCheck.
 */
interface HealthCheck
{
    /** What /_ready reports it as: lowercase letters, digits, '_' and '-'. */
    public function name(): string;

    /** Returns when healthy; throws, saying why, when not. The reason goes to the log alone. */
    public function check(): void;
}
