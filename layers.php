<?php

declare(strict_types=1);

/**
 * The layer rule's two maps, which Tinvar\Packages\Layers reads: the
 * packages (ADR 0054, 0055, 0056), and the modules inside the framework's
 * own package (ADR 0077).
 *
 * Each map lists its layers lowest first, with each layer's members --
 * namespaces, written with a trailing `\`, and single classes -- and the
 * layers below it that it may use.
 *
 * It lives beside src/ rather than in it, as aliases.php does, because it
 * names every layer's namespace on purpose, and the rule counts a namespace
 * in a string as a use of it: the class that reads the map would otherwise
 * be the framework naming tenancy, testing and codegen, and, inside it,
 * the core naming every module.
 */

// The packages above the framework, the same in both maps: nothing in the
// framework names them.
$above = [
    'tenancy' => ['Tinvar\\Tenancy\\'],
    'testing' => ['Tinvar\\Testing\\'],
    'codegen' => ['Tinvar\\Codegen\\'],
];

return [
    'packages' => [
        'layers' => [
            'support' => ['Tinvar\\Support\\', 'Tinvar\\Codec\\', 'Tinvar\\Container\\'],
            'database' => ['Tinvar\\Orm\\'],
            'framework' => ['Tinvar\\'],
            ...$above,
        ],
        'uses' => [
            'database' => ['support'],
            'framework' => ['support', 'database'],
            'tenancy' => ['support', 'database', 'framework'],
            'testing' => ['support', 'database', 'framework'],
            'codegen' => ['support', 'database', 'framework'],
        ],
        'sources' => [
            'packages/support/src/Codec' => 'Tinvar\\Codec\\',
            'packages/support/src/Container' => 'Tinvar\\Container\\',
            'packages/support/src/Support' => 'Tinvar\\Support\\',
            'packages/database/src/Orm' => 'Tinvar\\Orm\\',
            'packages/framework/src' => 'Tinvar\\',
            'packages/tenancy/src/Tenancy' => 'Tinvar\\Tenancy\\',
            'packages/testing/src/Testing' => 'Tinvar\\Testing\\',
            'packages/codegen/src' => 'Tinvar\\Codegen\\',
        ],
        'packages' => [
            'packages/support/src' => 'support',
            'packages/database/src' => 'database',
            'packages/framework/src' => 'framework',
            'packages/tenancy/src' => 'tenancy',
            'packages/testing/src' => 'testing',
            'packages/codegen/src' => 'codegen',
        ],
    ],

    // The framework's package, held apart inside: the queue's storage on
    // the database alone; the core, which names no module; jobs on the
    // queue; events, mail, webhooks, transfers and workflows each on jobs
    // and none on another; calls (an action or query run outside HTTP) on
    // the core, with tools and services on calls; the gateway's contract on
    // the core (ADR 0098, 0099); and the kernel -- the HTTP kernel, the console and the
    // server, which a process starts with -- over all of them. An
    // attribute is its module's, though the namespace is shared.
    'modules' => [
        'layers' => [
            'support' => ['Tinvar\\Support\\', 'Tinvar\\Codec\\', 'Tinvar\\Container\\'],
            'database' => ['Tinvar\\Orm\\'],
            'queue' => ['Tinvar\\Queue\\'],
            'core' => ['Tinvar\\', 'Tinvar\\Console\\Cron'],
            'jobs' => ['Tinvar\\Jobs\\', 'Tinvar\\Attributes\\Queue'],
            'events' => ['Tinvar\\Events\\', 'Tinvar\\Attributes\\Listener'],
            'mail' => ['Tinvar\\Mail\\'],
            'webhooks' => ['Tinvar\\Webhooks\\', 'Tinvar\\Attributes\\Webhook'],
            'transfers' => ['Tinvar\\Transfers\\'],
            'workflows' => ['Tinvar\\Workflows\\'],
            'calls' => ['Tinvar\\Calls\\'],
            'tools' => ['Tinvar\\Tools\\', 'Tinvar\\Attributes\\Tool'],
            'services' => ['Tinvar\\Services\\', 'Tinvar\\Attributes\\ServiceCall'],
            'gateway' => ['Tinvar\\Gateway\\'],
            'kernel' => ['Tinvar\\Http\\Kernel', 'Tinvar\\Console\\', 'Tinvar\\Server\\', 'Tinvar\\Http\\TestableApplication', 'Tinvar\\Http\\BrowserTestable', 'Tinvar\\Attributes\\Command'],
            ...$above,
        ],
        'uses' => [
            'database' => ['support'],
            'queue' => ['support', 'database'],
            'core' => ['support', 'database', 'queue'],
            'jobs' => ['support', 'database', 'queue', 'core'],
            'events' => ['support', 'database', 'queue', 'core', 'jobs'],
            'mail' => ['support', 'database', 'queue', 'core', 'jobs'],
            'webhooks' => ['support', 'database', 'queue', 'core', 'jobs'],
            'transfers' => ['support', 'database', 'queue', 'core', 'jobs'],
            'workflows' => ['support', 'database', 'queue', 'core', 'jobs'],
            'calls' => ['support', 'database', 'queue', 'core'],
            'tools' => ['support', 'database', 'queue', 'core', 'calls'],
            'services' => ['support', 'database', 'queue', 'core', 'calls'],
            'gateway' => ['support', 'database', 'queue', 'core'],
            'kernel' => ['support', 'database', 'queue', 'core', 'jobs', 'events', 'mail', 'webhooks', 'transfers', 'workflows', 'calls', 'tools', 'services', 'gateway'],
            'tenancy' => ['support', 'database', 'queue', 'core', 'jobs', 'events', 'mail', 'webhooks', 'transfers', 'workflows', 'calls', 'tools', 'services', 'gateway', 'kernel'],
            'testing' => ['support', 'database', 'queue', 'core', 'jobs', 'events', 'mail', 'webhooks', 'transfers', 'workflows', 'calls', 'tools', 'services', 'gateway', 'kernel'],
            'codegen' => ['support', 'database', 'queue', 'core', 'jobs', 'events', 'mail', 'webhooks', 'transfers', 'workflows', 'calls', 'tools', 'services', 'gateway', 'kernel'],
        ],
        'sources' => [
            'packages/framework/src' => 'Tinvar\\',
        ],
    ],
];
