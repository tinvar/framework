<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Attributes\Queue as QueueOptions;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\UserLookup;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware\RequirePanelMembership;
use Tinvar\Orm\Orm;
use Tinvar\Queue\PendingJob;
use Tinvar\Support\Signer;
use Tinvar\Support\Uuid;

/**
 * Runs one job in the context it was dispatched in, after checking that the
 * context still holds.
 *
 * In order, each refusing the job for good (JobRefused) rather than letting
 * it run as anyone else:
 *
 *   1. The payload's MAC verifies, so the tenant, panel and user in it are
 *      the ones the dispatcher sealed (Envelope).
 *   2. The tenant: the job runs inside Orm::actAs() for it -- no tenant when
 *      it was dispatched with none, whatever the worker last ran as -- and
 *      never with its subtree granted, which only a request's panel does.
 *   3. The user, for a job that runs as its dispatcher: still there, on the
 *      auth epoch it was dispatched on. A role change or an impersonation
 *      ending in between moves the epoch, and what was authorised under the
 *      old one is not run under the new.
 *   4. The panel: one the application still defines, the one the job's
 *      class declares if it declares one, and -- for a job that runs as a
 *      user -- one the user still holds a membership of, in that tenant, as
 *      the panel requires (Memberships). A runner given none refuses a
 *      user's job in a panel that requires one, rather than skip the check.
 *   5. The job's models, read again inside that tenant.
 *
 * Every switch is scoped, so the runner leaves the context as it found it;
 * clearing what a job left behind -- the identity map, the container's
 * request-lived services, a transaction left open -- is the boundary's, and
 * the Worker runs the reset contract after every job.
 */
final readonly class JobRunner
{
    /**
     * @param Signer $signer built for Envelope::PURPOSE from the application key
     * @param \Closure(class-string): object $resolver builds what handle() asks for
     */
    public function __construct(
        private Orm $orm,
        private Signer $signer,
        private \Closure $resolver,
        private AuthContext $auth,
        private ?PanelContext $panels = null,
        private ?PanelRegistry $registry = null,
        private ?Memberships $memberships = null,
        private ?UserLookup $users = null,
    ) {}

    /**
     * The envelope a payload seals.
     *
     * @throws JobRefused when it does not verify
     */
    public function open(string $payload): Envelope
    {
        return Envelope::open($payload, $this->signer);
    }

    /** How the envelope's job is queued: its tries, backoff and timeout. */
    public function options(Envelope $envelope): QueueOptions
    {
        return QueueOptions::of($envelope->declarations());
    }

    /**
     * A run's JobControl: what handle() is given when it asks for one.
     *
     * @param (\Closure(): bool)|null $stopping whether the worker running it is stopping;
     *        never, when it is run where nothing stops it
     */
    public function control(Envelope $envelope, ?\Closure $stopping = null): JobControl
    {
        return new JobControl($envelope, $stopping ?? static fn (): bool => false, $this->signer);
    }

    /**
     * The payload a job that paused goes back on its queue with: its own,
     * sealed again with the checkpoint it gave and one more pause counted.
     *
     * @param array<array-key, mixed>|null $checkpoint
     * @param bool $counted whether the pause counts against #[Queue(releases:)]
     */
    public function pause(Envelope $envelope, ?array $checkpoint, bool $counted = true): string
    {
        return $envelope->paused($checkpoint, $counted)->seal($this->signer);
    }

    /**
     * The next job of the envelope's chain, sealed to queue now with the
     * envelope's own tenant, user and epoch (Envelope::next()); null at the
     * chain's end. For Continuation, with an envelope the worker opened and
     * verified.
     */
    public function next(Envelope $envelope, int $now): ?PendingJob
    {
        $next = $envelope->next(Uuid::v7(), $now);

        return $next === null ? null : new PendingJob($next->id, QueueOptions::of($next->declarations())->name, $next->seal($this->signer), $now);
    }

    /**
     * The envelope's chain's catch, sealed to queue now as Envelope::caught()
     * says; null where the chain has none. For Continuation, once a job of
     * the chain has failed for good.
     */
    public function caught(Envelope $envelope, int $now): ?PendingJob
    {
        $catch = $envelope->caught(Uuid::v7(), $now);

        return $catch === null ? null : new PendingJob($catch->id, QueueOptions::of($catch->declarations())->name, $catch->seal($this->signer), $now);
    }

    /**
     * Runs the job's handle().
     *
     * @throws JobRefused when its context no longer holds
     */
    public function run(Envelope $envelope, ?JobControl $control = null): void
    {
        $control ??= $this->control($envelope);
        $this->within($envelope, function () use ($envelope, $control): void {
            $job = $this->job($envelope);
            $this->call($job, 'handle', $control);
        });
    }

    /**
     * Runs the job's failed() hook, if it has one, once its last try has
     * failed -- in the same context, so it reads what the job would have.
     * The hook is given the error, and its services by type as handle() is,
     * with a JobControl that answers for this job.
     */
    public function failed(Envelope $envelope, \Throwable $error): void
    {
        if (!method_exists($envelope->class, 'failed')) {
            return;
        }

        $this->within($envelope, function () use ($envelope, $error): void {
            $this->call($this->job($envelope), 'failed', $this->control($envelope), error: $error);
        });
    }

    /**
     * Runs the job's refused() hook, if it has one, once it was refused for
     * good: a job that stands for a record its user can see -- an export,
     * an import -- says there that it will not run, rather than leaving the
     * record queued until it reads as failed an hour on (ADR 0107).
     *
     * Only for an envelope that verified: what it names is what the
     * dispatcher sealed. In none of the context that refused it -- the
     * tenant it was sealed in, nobody signed in, no panel -- with that
     * context told as a Refusal. The job's models are read again in that
     * tenant to build it; one gone since leaves nothing to call the hook
     * on, and nothing is.
     */
    public function refused(Envelope $envelope, JobRefused $error): void
    {
        if (!method_exists($envelope->class, 'refused')) {
            return;
        }

        $refusal = new Refusal($error->reason, $envelope->tenant, $envelope->panel, $envelope->user);
        $this->orm->withoutSubtreeGrant(fn () => $this->orm->actAs($envelope->tenant, function () use ($envelope, $refusal): void {
            $this->auth->actingAs(null, function () use ($envelope, $refusal): void {
                $work = function () use ($envelope, $refusal): void {
                    $this->call($this->job($envelope), 'refused', $this->control($envelope), $refusal);
                };
                $this->panels === null ? $work() : $this->outsidePanels($work);
            });
        }));
    }

    /** @param \Closure(): void $work */
    private function within(Envelope $envelope, \Closure $work): void
    {
        // Never with the subtree granted (ADR 0047): a sync job runs inside
        // the request that dispatched it, whose panel may have granted it.
        $this->orm->withoutSubtreeGrant(fn () => $this->orm->actAs($envelope->tenant, function () use ($envelope, $work): void {
            $user = $this->userFor($envelope);
            $this->auth->actingAs($user, function () use ($envelope, $work): void {
                $panel = $this->panelFor($envelope);
                if ($panel === null) {
                    // None: and none in force either, whatever the code that
                    // runs this was in. A job dispatched in no panel must not
                    // inherit one from a request it happens to run inside.
                    $this->panels === null ? $work() : $this->outsidePanels($work);

                    return;
                }

                \assert($this->panels !== null);
                $this->panels->within($panel, $work);
            }, $envelope->epoch);
        }));
    }

    /**
     * The user a job runs as: nobody, unless its class asks for its
     * dispatcher or its payload sealed one (a job that runs as its starter,
     * RunsAsStarter), who must still be there on the same auth epoch.
     *
     * A job that runs as its starter is checked for the user its payload
     * seals as an asUser job is, and runs as nobody when it seals none.
     */
    private function userFor(Envelope $envelope): ?Authenticatable
    {
        // A user sealed in a chain or batch for a job beside this one is not
        // this job's: it runs as one only when it asked, or runs as its
        // starter and was started by someone.
        $asUser = QueueOptions::of($envelope->declarations())->asUser;
        $starter = is_subclass_of($envelope->class, RunsAsStarter::class) && $envelope->user !== null;
        if (!$asUser && !$starter) {
            return null;
        }
        if ($envelope->user === null) {
            throw new JobRefused('user_missing', "{$envelope->class} runs as its dispatcher, and the payload names nobody.");
        }
        if ($this->users === null) {
            throw new JobRefused('user_missing', "{$envelope->class} runs as its dispatcher, and the application gave the runner no UserLookup to find them with.");
        }

        $user = $this->users->find($envelope->user)
            ?? throw new JobRefused('user_missing', "The user {$envelope->class} was dispatched by is gone.");
        if (!hash_equals($envelope->epoch, $this->users->epoch($user))) {
            throw new JobRefused(
                'epoch_changed',
                "The user {$envelope->class} was dispatched by is on another auth epoch now: what they may do has changed since.",
            );
        }

        return $user;
    }

    /** The panel the job runs in, held to its class's declaration and, for a user, their membership. */
    private function panelFor(Envelope $envelope): ?string
    {
        try {
            $declared = DeclaredPanel::of($envelope->declarations(), $this->registry);
        } catch (\LogicException $e) {
            throw new JobRefused('panel_unknown', $e->getMessage(), $e);
        }

        if ($envelope->panel === null) {
            if ($declared !== null) {
                throw new JobRefused('panel_mismatch', "{$envelope->class} is in panel '{$declared->name}' now, and was dispatched in none.");
            }

            return null;
        }

        if ($declared !== null && $declared->name !== $envelope->panel) {
            throw new JobRefused('panel_mismatch', "{$envelope->class} is in panel '{$declared->name}' now, and was dispatched in '{$envelope->panel}'.");
        }
        $definition = $this->registry?->definition($envelope->panel);
        if ($definition === null || $this->panels === null) {
            throw new JobRefused('panel_unknown', "{$envelope->class} was dispatched in panel '{$envelope->panel}', which the application does not define.");
        }

        // A job running as a user is held to the panel as a request by them
        // would be: their membership, in this tenant, as the panel requires.
        // With no Memberships to hold them to, a panel that requires one is
        // refused, as a request in it is at boot and a tool or service call
        // in it is -- never run on trust.
        if ($this->auth->user() !== null) {
            $binding = ($declared ?? new PanelBinding($envelope->panel))->within($definition);
            if ($this->memberships === null) {
                if ($binding->narrows()) {
                    throw new JobRefused(
                        'membership',
                        "{$envelope->class} runs as a user in panel '{$envelope->panel}', which requires a membership, and the runner was given no Memberships to hold them to.",
                    );
                }
            } else {
                try {
                    RequirePanelMembership::verify($this->auth, $this->memberships, $this->orm, $binding);
                } catch (HttpException $e) {
                    throw new JobRefused('membership', $e->getMessage(), $e);
                }
            }
        }

        return $envelope->panel;
    }

    /**
     * Runs $work with no panel in force: the context's own state, put back
     * afterwards. PanelContext has no scoped "none", so the job runs where
     * nothing has been set -- only true on a worker, or inside a request in
     * no panel; inside a panel the job is refused rather than run in it.
     *
     * @param \Closure(): void $work
     */
    private function outsidePanels(\Closure $work): void
    {
        \assert($this->panels !== null);
        $current = $this->panels->currentOrNull();
        if ($current !== null) {
            throw new JobRefused('panel_mismatch', "A job dispatched in no panel cannot run inside panel '{$current}'.");
        }

        $work();
    }

    private function job(Envelope $envelope): Job
    {
        $job = Payload::construct($envelope->class, $envelope->arguments, $this->orm);
        \assert($job instanceof Job);

        return $job;
    }

    /** Calls a job's method with what its parameters ask for, built by the resolver. */
    private function call(Job $job, string $method, ?JobControl $control = null, ?Refusal $refusal = null, ?\Throwable $error = null): void
    {
        if (!method_exists($job, $method)) {
            throw new JobRefused('handle_missing', $job::class . " has no {$method}() to run.");
        }

        $arguments = [];
        foreach (new \ReflectionMethod($job, $method)->getParameters() as $parameter) {
            $type = $parameter->getType();
            // failed()'s error: the parameter typed as an error, or not
            // typed at all, as it always took it. One typed for an error of
            // another kind than this is refused, not handed something built.
            $erroneous = $type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_a($type->getName(), \Throwable::class, true);
            if ($error !== null && ($type === null || $erroneous)) {
                if ($type instanceof \ReflectionNamedType && !is_a($error, $type->getName())) {
                    throw new JobRefused('handle_unresolvable', $job::class . "::{$method}() takes {$type->getName()}, and the error was " . $error::class . '.');
                }
                $arguments[] = $error;
                $error = null;
                continue;
            }
            if ($erroneous) {
                throw new JobRefused('handle_unresolvable', $job::class . "::{$method}() takes an error, which only failed() is given, once.");
            }
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                throw new JobRefused(
                    'handle_unresolvable',
                    $job::class . "::{$method}() takes \${$parameter->getName()}, which is not a service: a job's data belongs in its constructor.",
                );
            }
            /** @var class-string $class */
            $class = $type->getName();
            // This run's own, never the container's: a JobControl from
            // anywhere else would answer for another job.
            if ($class === Refusal::class) {
                $arguments[] = $refusal ?? throw new JobRefused('handle_unresolvable', $job::class . "::{$method}() takes a Refusal, which only refused() is given.");
                continue;
            }
            if ($class === JobControl::class) {
                $arguments[] = $control ?? throw new JobRefused('handle_unresolvable', $job::class . "::{$method}() takes a JobControl, which only handle() and failed() are given.");
                continue;
            }
            $arguments[] = ($this->resolver)($class);
        }

        $job->{$method}(...$arguments);
    }
}
