<?php

declare(strict_types=1);

use Tinvar\Config\Configs;
use Tinvar\Config\EnvironmentFile;
use Tinvar\Console\Deploy\DeployApp;
use Tinvar\Console\Deploy\RecipeParts;
use Tinvar\Console\NewApp\DevEnvironment;
use Tinvar\Mail\MailProvider;
use Tinvar\Mail\MailTransports;

// One list of each provider's variables, MailProvider::variables(): what
// `tinvar new` writes into .env and what `tinvar deploy:init` asks for are
// both read from it, so they cannot disagree on a name or on a secret.

it('names only variables a mail config reads, and marks every one named like a secret as one', function (MailProvider $provider) {
    $read = [];
    foreach (MailTransports::configs() as $class) {
        foreach (Configs::keys($class) as $key) {
            $read[$key->variable] = true;
        }
    }

    expect($provider->variables())->toBeArray();
    foreach ($provider->variables() as $variable => $secret) {
        expect(isset($read[$variable]))->toBeTrue("{$variable} is read by no mail config")
            ->and($secret || !EnvironmentFile::namesSecret($variable))->toBeTrue("{$variable} is named like a secret and not marked one");
    }
})->with(MailProvider::cases());

it('gives the deploy recipes and the new app the same variables, the secrets apart', function (MailProvider $provider) {
    $parts = RecipeParts::mailVariables(new DeployApp('my-app', mail: [$provider]));
    $secrets = array_keys(array_filter($provider->variables()));
    $open = array_keys(array_filter($provider->variables(), static fn (bool $secret): bool => !$secret));

    expect($parts['secrets'])->toBe($secrets)
        ->and($parts['env'])->toBe($open)
        ->and(DevEnvironment::mailLines($provider))->toHaveCount(\count($provider->variables()));
    foreach (array_keys($provider->variables()) as $index => $variable) {
        expect(DevEnvironment::mailLines($provider)[$index])->toStartWith("# {$variable}");
    }
})->with(MailProvider::cases());

it('keeps the SES access key id with the secrets, as its name says it is one', function () {
    expect(RecipeParts::mailVariables(new DeployApp('my-app', mail: [MailProvider::Ses]))['secrets'])->toContain('TINVAR_SES_KEY');
});
