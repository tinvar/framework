<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/** Opts a routable entry point out of authentication. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class PublicAccess {}
