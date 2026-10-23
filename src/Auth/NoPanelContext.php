<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/**
 * Something asked which panel it was in, and nobody had decided.
 *
 * "A policy that reads Panel::current() where none is set throws." Failing
 * closed here is deliberate: a panel NARROWS what a request sees, so code
 * asking the question is about to restrict something, and answering with a
 * default would answer it with the widest possible reading.
 */
final class NoPanelContext extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'No panel is set for this work. An HTTP request takes its panel from the route; '
            . 'a job, console command or service call must state one with PanelContext::within().'
        );
    }
}
