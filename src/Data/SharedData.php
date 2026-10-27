<?php

declare(strict_types=1);

namespace Tinvar\Data;

/**
 * A class whose #[Shared] methods are values every page render may read:
 * Inertia's `share()`, as tinvar-spec.md's "Data categories" adopts it
 * (ADR 0060).
 *
 * Every page, or every page of one panel: a provider is in the panel its
 * #[Panel] names, or its namespace places it in, and its values reach only
 * renders inside that panel's stack. One in no panel reaches every page.
 *
 * Built afresh for each render through the application's resolver, and
 * refused if it could outlive one -- a worker-lived #[Dependency], or a
 * static property -- since what it holds is one visitor's.
 */
interface SharedData {}
