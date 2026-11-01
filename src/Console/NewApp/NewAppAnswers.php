<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

use Tinvar\Console\Deploy\DeployTarget;
use Tinvar\Mail\MailProvider;
use Tinvar\Orm\DatabaseDriver;

/**
 * What `tinvar new` was told or asked (ADR 0150): every choice that shapes
 * the application it writes, each with the default a person with nothing
 * to say gets -- SQLite, one tenant, mail to a log, no kit, no deployment.
 */
final readonly class NewAppAnswers
{
    /**
     * @param string $directory where the application is made: absolute, and missing or empty
     * @param list<string> $kits the kits' packages to install, `tinvar/accounts`
     */
    public function __construct(
        public string $directory,
        public DatabaseDriver $database = DatabaseDriver::Sqlite,
        public string $databaseHost = '127.0.0.1',
        public ?int $databasePort = null,
        public string $databaseName = '',
        public string $databaseUser = '',
        public TenantLookup $tenancy = TenantLookup::Off,
        public MailProvider $mail = MailProvider::Log,
        public array $kits = [],
        public ?DeployTarget $deploy = null,
        public bool $install = true,
    ) {}

    /** The application's directory's name, as the app and its database are named after it. */
    public function name(): string
    {
        return basename($this->directory);
    }

    /** A database or user name made from the directory's: lowercase, underscores. */
    public function identifier(): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($this->name())), '_') ?: 'app';
    }
}
