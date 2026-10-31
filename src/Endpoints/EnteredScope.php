<?php

declare(strict_types=1);

namespace Tinvar\Endpoints;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\PanelContext;
use Tinvar\Orm\Orm;

/**
 * What a request has put in place around the code answering it -- the
 * tenant, the signed-in user, the panel and the membership it was granted,
 * the read-only guard -- taken down, to be put back around work that runs
 * after it has all been taken down.
 *
 * A streamed body is that work: its producer runs as the response is sent,
 * after the middleware that entered the tenant and the panel has returned
 * and after the Kernel has reset the request's scope. Run bare, it read as
 * nobody, in no tenant, and wrote where a GET may not. run() enters each
 * again, scoped, and leaves each as it found it.
 */
final readonly class EnteredScope
{
    private function __construct(
        private Orm $orm,
        private ?string $tenant,
        private ?AuthContext $auth,
        private ?Authenticatable $user,
        private string $epoch,
        private ?PanelContext $panels,
        private ?string $panel,
        private ?PanelBinding $granted,
        private bool $readOnly,
    ) {}

    /** What is in place now, with the read-only guard as $readOnly says. */
    public static function capture(Orm $orm, ?AuthContext $auth, ?PanelContext $panels, bool $readOnly): self
    {
        return new self(
            orm: $orm,
            tenant: $orm->hasTenancy() ? $orm->tenant() : null,
            auth: $auth,
            user: $auth?->user(),
            epoch: $auth?->epoch() ?? '',
            panels: $panels,
            panel: $panels?->currentOrNull(),
            granted: $panels?->granted(),
            readOnly: $readOnly,
        );
    }

    /**
     * $work's result, run inside what capture() saw.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function run(\Closure $work): mixed
    {
        $orm = $this->orm;
        if ($this->readOnly) {
            $work = static fn (): mixed => $orm->readOnly($work);
        }

        if ($this->panels !== null) {
            $panels = $this->panels;
            $granted = $this->granted;
            $inner = $work;
            $work = static fn (): mixed => $panels->grantedWithin($granted, $inner);
            if ($this->panel !== null) {
                $panel = $this->panel;
                $granting = $work;
                $work = static fn (): mixed => $panels->within($panel, $granting);
            }
        }

        if ($this->auth !== null) {
            $auth = $this->auth;
            $user = $this->user;
            $epoch = $this->epoch;
            $signedIn = $work;
            $work = static fn (): mixed => $auth->actingAs($user, $signedIn, $epoch);
        }

        if ($this->tenant !== null && $this->tenant !== '') {
            $tenant = $this->tenant;
            $tenanted = $work;
            $work = static fn (): mixed => $orm->actAs($tenant, $tenanted);
        }

        return $work();
    }
}
