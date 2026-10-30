<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

use Tinvar\Support\BuildProfile;

/**
 * `tinvar gateway:keys` (ADR 0119): the public halves of the keys PHP signs
 * node commands and console tickets with, for this environment, as the
 * product hands them to its agents and its console edge.
 *
 * Only the public halves, which verify and can make nothing, and only this
 * process's profile's: the application key is bound to the profile it
 * serves (AppKey::application(), ADR 0090), so staging's keys are printed
 * by staging, under staging's profile, and production's by production.
 */
final class GatewayKeys
{
    /**
     * The lines the command prints: the profile, then each purpose and its
     * public key as URL-safe base64.
     *
     * @param string $appKey the application key AppKey::application() gives, bound to $profile
     * @return list<string>
     */
    public static function lines(#[\SensitiveParameter] string $appKey, ?string $profile = null): array
    {
        $profile ??= BuildProfile::fromEnvironment();

        return [
            "profile {$profile}",
            NodeCommands::PURPOSE . ' ' . NodeCommands::key($appKey, $profile)->publicKeyText(),
            ConsoleTickets::PURPOSE . ' ' . ConsoleTickets::key($appKey, $profile)->publicKeyText(),
        ];
    }
}
