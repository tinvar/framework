<?php

declare(strict_types=1);

namespace Tinvar\Console\Deploy;

use Tinvar\Console\Deploy\Recipes\FlyRecipe;
use Tinvar\Console\Deploy\Recipes\RailwayRecipe;
use Tinvar\Console\Deploy\Recipes\RenderRecipe;
use Tinvar\Console\Deploy\Recipes\VpsComposeRecipe;
use Tinvar\Console\Deploy\Recipes\VpsSystemdRecipe;

/** Where `tinvar deploy:init` writes an application's deployment for (ADR 0157). */
enum DeployTarget: string
{
    case Fly = 'fly';
    case Render = 'render';
    case Railway = 'railway';
    case VpsSystemd = 'vps-systemd';
    case VpsCompose = 'vps-compose';

    /** What a person reads in a menu. */
    public function title(): string
    {
        return match ($this) {
            self::Fly => 'Fly.io',
            self::Render => 'Render',
            self::Railway => 'Railway',
            self::VpsSystemd => 'A VPS: the image under systemd, Caddy in front',
            self::VpsCompose => 'A VPS: Docker Compose, Caddy in front',
        };
    }

    public function recipe(): DeployRecipe
    {
        return match ($this) {
            self::Fly => new FlyRecipe(),
            self::Render => new RenderRecipe(),
            self::Railway => new RailwayRecipe(),
            self::VpsSystemd => new VpsSystemdRecipe(),
            self::VpsCompose => new VpsComposeRecipe(),
        };
    }

    /** @return array<string, string> value => title, for a select */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $target) {
            $options[$target->value] = $target->title();
        }

        return $options;
    }
}
