<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/** Whatever the application calls a user. */
interface Authenticatable
{
    public function authIdentifier(): string;
}
