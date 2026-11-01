<?php

declare(strict_types=1);

namespace Tinvar\Console\Deploy;

/**
 * How the application's image runs: one container, PHP and the renderers
 * as two users under one entrypoint (ADR 0059), or two containers sharing
 * only a socket volume (ADR 0071), which needs the Dockerfile's `pod-php`
 * and `pod-renderer` targets and a platform that shares a volume between
 * two containers of one instance.
 */
enum ContainerLayout: string
{
    case One = 'one';
    case Two = 'two';
}
