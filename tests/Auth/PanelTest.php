<?php

declare(strict_types=1);

/**
 * "Every non-HTTP context has a defined panel, serialized with the work. A
 * policy that reads Panel::current() where none is set throws."
 *
 * Panels were carried and never consulted: QueryCache took one as a
 * constructor constant, and a fragment token signed one that no decision
 * read. A signed field nothing reads is either dead weight or a missing
 * check.
 */

use Tinvar\Attributes\Panel;
use Tinvar\Auth\NoPanelContext;
use Tinvar\Auth\PanelContext;

describe('the current panel', function () {
    // A panel NARROWS what a request sees, so code asking which panel it is
    // in is about to restrict something. Answering with a default would
    // answer it with the widest possible reading.
    it('throws rather than guessing when none is set', function () {
        expect(fn () => (new PanelContext())->current())
            ->toThrow(NoPanelContext::class, 'No panel is set');
    });

    it('reports one that was set', function () {
        $panels = new PanelContext();

        $seen = $panels->within('admin', static fn (): string => $panels->current());

        expect($seen)->toBe('admin');
    });

    // Scoped, like actAs(): a bare setter leaks the switch into whatever runs
    // next on the same worker.
    it('restores what was there afterwards', function () {
        $panels = new PanelContext();

        $panels->within('admin', static fn (): bool => true);

        expect($panels->isSet())->toBeFalse();
    });

    it('nests', function () {
        $panels = new PanelContext();

        $inner = $panels->within('admin', static fn (): array => [
            $panels->within('reseller', static fn (): string => $panels->current()),
            $panels->current(),
        ]);

        expect($inner)->toBe(['reseller', 'admin']);
    });

    // "no panel" and "a panel called nothing" are different states, and
    // conflating them is how a narrowing mechanism gets widened by accident.
    it('refuses an empty panel name', function () {
        expect(fn () => (new PanelContext())->within('', static fn (): bool => true))
            ->toThrow(InvalidArgumentException::class, 'cannot be empty');
    });

    it('clears on reset, as the scope contract requires', function () {
        $panels = new PanelContext();
        $fiber = new Fiber(static function () use ($panels): void {
            $panels->within('admin', static function (): void {
                \Fiber::suspend();
            });
        });

        $fiber->start();
        $panels->reset();

        expect($panels->isSet())->toBeFalse();
    });
});

/**
 * Fiber-local for the same reason as the tenant and the current user: the
 * render scheduler interleaves query bodies.
 */
describe('panels are fiber-local', function () {
    it('does not leak a switch into a sibling fiber', function () {
        $panels = new PanelContext();
        $seen = null;

        $victim = new Fiber(static function () use ($panels, &$seen): void {
            $panels->within('user', static function () use ($panels, &$seen): void {
                \Fiber::suspend();
                $seen = $panels->current();
            });
        });

        $attacker = new Fiber(static function () use ($panels): void {
            $panels->within('admin', static function (): void {
                \Fiber::suspend();
            });
        });

        $victim->start();
        $attacker->start();
        $victim->resume();
        $attacker->resume();

        expect($seen)->toBe('user');
    });

    it('leaves the main context alone while a fiber is in a panel', function () {
        $panels = new PanelContext();

        $fiber = new Fiber(static function () use ($panels): void {
            $panels->within('admin', static function (): void {
                \Fiber::suspend();
            });
        });

        $fiber->start();

        expect($panels->isSet())->toBeFalse();

        $fiber->resume();
    });

    // A fiber that has not switched sees whatever the request established.
    it('inherits the main context panel', function () {
        $panels = new PanelContext();
        $seen = null;

        $panels->within('user', static function () use ($panels, &$seen): void {
            $fiber = new Fiber(static function () use ($panels, &$seen): void {
                $seen = $panels->current();
            });
            $fiber->start();
        });

        expect($seen)->toBe('user');
    });
});

describe('the attribute', function () {
    it('carries the narrowing a panel declares', function () {
        $panel = new Panel('reseller', requires: 'reseller', permission: 'reseller.access');

        expect($panel->name)->toBe('reseller')
            ->and($panel->requires)->toBe('reseller')
            ->and($panel->permission)->toBe('reseller.access');
    });

    it('needs a name', function () {
        expect(fn () => new Panel(''))->toThrow(InvalidArgumentException::class, 'needs a name');
    });
});
