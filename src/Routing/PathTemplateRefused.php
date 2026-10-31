<?php

declare(strict_types=1);

namespace Tinvar\Routing;

/**
 * What PathTemplate::compile() refuses in a template: which rule, and the
 * segment or parameter name that breaks it -- for each registry to word in
 * its own exception.
 */
final class PathTemplateRefused extends \InvalidArgumentException
{
    /** A literal segment that is not a plain path segment, or is `.` or `..`. */
    public const string LITERAL = 'literal';

    /** A parameter whose name is not an identifier. */
    public const string NAME = 'name';

    /** A parameter named twice. */
    public const string TWICE = 'twice';

    public function __construct(public readonly string $rule, public readonly string $part)
    {
        parent::__construct("Path template refused ({$rule}): '{$part}'.");
    }
}
