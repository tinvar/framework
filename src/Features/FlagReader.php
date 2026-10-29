<?php

declare(strict_types=1);

namespace Tinvar\Features;

/**
 * What a query body, a page or a shared value is handed to read the flags:
 * active(), for the tenant, user and panel in force, and nothing else.
 *
 * Features can also read a flag for a scope its caller names, and say what
 * the scope in force is -- who the user is. A shared query's answer is kept
 * for every caller whose flags agree, so a body that could do either could
 * keep one user's answer for all of them. So BuildRules lets a shared query
 * take this, and not Features (ADR 0094); and Features answers either of
 * those as a read no answer can be kept under, wherever it is called.
 */
final readonly class FlagReader
{
    public function __construct(private Features $features) {}

    /** Whether $flag is on for the tenant, user and panel in force. */
    public function active(string|\BackedEnum $flag): bool
    {
        return $this->features->active($flag);
    }
}
