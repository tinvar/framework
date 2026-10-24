<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Whether a page's history is encrypted, against the default.
 *
 * tinvar-spec.md, "Security": "History encryption: on by default for
 * authenticated routes, including remembered form state ...
 * `#[EncryptHistory(false)]` opts a page out." By default a page is
 * encrypted when it is rendered for a signed-in user; `false` opts it out,
 * and `true` opts in a page rendered for a visitor who is not signed in.
 * See ADR 0019.
 *
 * Read off the page class itself. Not off a parent class -- PHP does not
 * inherit class attributes, and an opt-out reached by inheritance is the
 * fail-open direction (see ClassAttributes) -- and not off a layout, which
 * the registry refuses rather than silently ignoring: the spec opts a page
 * out, one page at a time.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class EncryptHistory
{
    public function __construct(public bool $encrypt = true) {}
}
