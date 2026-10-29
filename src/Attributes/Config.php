<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A typed, immutable configuration object, filled from the environment.
 *
 *     #[Config(prefix: 'SHOP_')]
 *     final readonly class ShopConfig
 *     {
 *         public function __construct(
 *             public ?string $database = null,         // SHOP_DATABASE
 *             public int $workers = 4,                 // SHOP_WORKERS
 *             #[\SensitiveParameter, Env(file: true)]
 *             public ?Secret $webhookSecret = null,    // SHOP_WEBHOOK_SECRET, or the file SHOP_WEBHOOK_SECRET_FILE names
 *         ) {}
 *     }
 *
 * The spec's "attributes first": config is declared on the code it
 * describes, and there are no config files. Each constructor parameter is
 * one key, read from the variable named by the prefix and the parameter's
 * name in upper snake case, or by #[Env]. Tinvar\Config\Configs loads it,
 * refuses a missing or malformed key at boot, and keeps it for the worker
 * (ADR 0085).
 *
 * A config class is never Data: the encoder refuses it, and Data::from()
 * will not map from it, so nothing in it reaches the renderer by either.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Config
{
    public function __construct(public string $prefix = '')
    {
        if ($prefix !== '' && preg_match('/^[A-Z][A-Z0-9_]*_\z/', $prefix) !== 1) {
            throw new \InvalidArgumentException("'{$prefix}' is not a config prefix: upper case letters, digits and _, ending in _ (SHOP_).");
        }
    }
}
