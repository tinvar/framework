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
    'billing' => ['Tinvar\\Billing\\'],
    'testing' => ['Tinvar\\Testing\\'],
    'codegen' => ['Tinvar\\Codegen\\'],
];

// The core, held apart into layers of its own (ADR 0114), lowest first:
// each may use the ones before it, and none after. A namespace is a layer's
// whole unless a class or a namespace below it is named in another, as the
// interface `Http\Middleware` stays with the transport while the middleware
// in `Http\Middleware\` reads who is asking. Every class named here alone is
// one whose namespace is the name applications know it by, and whose uses
// are another layer's; ADR 0114 says why for each.
$core = [
    // What a request is -- the request, the response, the pipeline and the
    // middleware interface, the HTTP exceptions and the stores -- and what
    // an application declares with: attributes are declarations, so they
    // stand with the transport, except those that run a check of a layer
    // above as they are built. Anything of Tinvar's no layer names is here,
    // the lowest, so a new namespace that uses anything above fails. The
    // console's prompts are here too (ADR 0144): they use nothing of
    // Tinvar's, and the generators, staging and every module may ask.
    'http' => [
        'Tinvar\\', 'Tinvar\\Http\\', 'Tinvar\\Http\\Middleware', 'Tinvar\\Http\\Endpoints\\Limits', 'Tinvar\\Console\\Cron',
        'Tinvar\\Console\\Prompts', 'Tinvar\\Console\\Prompts\\',
        'Tinvar\\Attributes\\ClassAttributes', 'Tinvar\\Attributes\\Authorize', 'Tinvar\\Attributes\\Panel', 'Tinvar\\Attributes\\PublicAccess',
        // Which host a request is for (ADR 0149): read from the request
        // alone, below everything that routes, mints or checks by it.
        'Tinvar\\Routing\\HostPattern', 'Tinvar\\Routing\\HostPatternRefused', 'Tinvar\\Routing\\HostMatch', 'Tinvar\\Routing\\Domains',
    ],
    // Who is asking: the user, the panel, the gates and the policies, the
    // middleware that reads them, and the throttle keyed by them.
    'auth' => ['Tinvar\\Auth\\', 'Tinvar\\Http\\Middleware\\', 'Tinvar\\Http\\Throttle'],
    // What is held and sent: config, Data and its encoder, the renderer's
    // wire format, storage, outbound HTTP and the AWS signer it and mail
    // share, validation, the tag caches and
    // flags; the signed tokens every entry point mints; the route a loader
    // is given; a span, and where work runs as one; and the attributes
    // whose checks are these.
    'data' => [
        'Tinvar\\Config\\', 'Tinvar\\Data\\', 'Tinvar\\Protocol\\', 'Tinvar\\Storage\\', 'Tinvar\\Outbound\\', 'Tinvar\\Aws\\',
        'Tinvar\\Validation\\', 'Tinvar\\Cache\\', 'Tinvar\\Features\\',
        'Tinvar\\Render\\SignedToken', 'Tinvar\\Render\\TokenClaims', 'Tinvar\\Render\\TokenError', 'Tinvar\\Render\\TenantClaim',
        'Tinvar\\Routing\\CompiledRoute', 'Tinvar\\Routing\\RouteKind', 'Tinvar\\Routing\\RoutingException',
        'Tinvar\\Observability\\Span', 'Tinvar\\Observability\\Spans',
        // What a form request's upload field holds, built by the action
        // runner and handed to the validator as a value (ADR 0127).
        'Tinvar\\Uploads\\Upload', 'Tinvar\\Uploads\\UploadList',
        'Tinvar\\Attributes\\',
    ],
    // The reads: pages and their loaders, queries and their cache, and the
    // build rules every declared entry point is held to.
    'reads' => ['Tinvar\\Routing\\', 'Tinvar\\Query\\', 'Tinvar\\Auth\\BuildRules', 'Tinvar\\Attributes\\Query', 'Tinvar\\Cache\\QueryCache', 'Tinvar\\Live\\TagsRead'],
    // The renderer and what watches it: telemetry, probes and the dev
    // toolbar.
    'render' => ['Tinvar\\Render\\', 'Tinvar\\Observability\\', 'Tinvar\\Dev\\', 'Tinvar\\Http\\DevOverlay'],
    // Which host and which user's origin: realms, custom domains, the
    // cross-host handoff and emailed links.
    'identity' => ['Tinvar\\Identity\\', 'Tinvar\\Http\\Middleware\\RequirePlatformHost'],
    // The other entry points -- actions, endpoints, channels and live
    // updates, uploads -- the page router, and the checks every one of them
    // is entered through.
    'entry' => [
        'Tinvar\\Live\\', 'Tinvar\\Channels\\', 'Tinvar\\Actions\\', 'Tinvar\\Endpoints\\', 'Tinvar\\Uploads\\',
        'Tinvar\\Http\\EntryContext', 'Tinvar\\Http\\BuildRegistry', 'Tinvar\\Http\\Middleware\\CheckVersion', 'Tinvar\\Routing\\Router',
    ],
    // The framework's own endpoints under /_ and what a request's scope and
    // failure are made of, which the kernel mounts.
    'requests' => ['Tinvar\\Http\\Endpoints\\', 'Tinvar\\Http\\RequestScope', 'Tinvar\\Http\\FragmentSupport', 'Tinvar\\Http\\PageCursors', 'Tinvar\\Http\\ExceptionHandler'],
    // Tools over the rest: the supply chain and the layer rule, generators,
    // scenarios, staging, nodes.
    'operations' => ['Tinvar\\Packages\\', 'Tinvar\\Generators\\', 'Tinvar\\Scenarios\\', 'Tinvar\\Staging\\', 'Tinvar\\Nodes\\'],
];
$coreUses = [];
$below = ['support', 'database', 'queue'];
foreach (array_keys($core) as $layer) {
    $coreUses[$layer] = $below;
    $below[] = $layer;
}
$coreNames = array_keys($core);

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
            'billing' => ['support', 'database', 'framework'],
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
            'packages/billing/src/Billing' => 'Tinvar\\Billing\\',
            'packages/testing/src/Testing' => 'Tinvar\\Testing\\',
            'packages/codegen/src' => 'Tinvar\\Codegen\\',
        ],
        'packages' => [
            'packages/support/src' => 'support',
            'packages/database/src' => 'database',
            'packages/framework/src' => 'framework',
            'packages/tenancy/src' => 'tenancy',
            'packages/billing/src' => 'billing',
            'packages/testing/src' => 'testing',
            'packages/codegen/src' => 'codegen',
        ],
    ],

    // The framework's package, held apart inside: the queue's storage on
    // the database alone; the core, which names no module; jobs on the
    // queue; events, mail, webhooks, transfers, workflows and push each on jobs
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
            ...$core,
            'jobs' => ['Tinvar\\Jobs\\', 'Tinvar\\Attributes\\Queue'],
            'events' => ['Tinvar\\Events\\', 'Tinvar\\Attributes\\Listener'],
            'mail' => ['Tinvar\\Mail\\'],
            'webhooks' => ['Tinvar\\Webhooks\\', 'Tinvar\\Attributes\\Webhook'],
            'transfers' => ['Tinvar\\Transfers\\'],
            'workflows' => ['Tinvar\\Workflows\\'],
            'push' => ['Tinvar\\Push\\'],
            'calls' => ['Tinvar\\Calls\\'],
            'tools' => ['Tinvar\\Tools\\', 'Tinvar\\Attributes\\Tool'],
            'services' => ['Tinvar\\Services\\', 'Tinvar\\Attributes\\ServiceCall'],
            'gateway' => ['Tinvar\\Gateway\\'],
            'kernel' => ['Tinvar\\Http\\Kernel', 'Tinvar\\Console\\', 'Tinvar\\Discovery\\', 'Tinvar\\Server\\', 'Tinvar\\Http\\TestableApplication', 'Tinvar\\Http\\BrowserTestable', 'Tinvar\\Attributes\\Command'],
            ...$above,
        ],
        'uses' => [
            'database' => ['support'],
            'queue' => ['support', 'database'],
            ...$coreUses,
            'jobs' => ['support', 'database', 'queue', ...$coreNames],
            'events' => ['support', 'database', 'queue', ...$coreNames, 'jobs'],
            'mail' => ['support', 'database', 'queue', ...$coreNames, 'jobs'],
            'webhooks' => ['support', 'database', 'queue', ...$coreNames, 'jobs'],
            'transfers' => ['support', 'database', 'queue', ...$coreNames, 'jobs'],
            'workflows' => ['support', 'database', 'queue', ...$coreNames, 'jobs'],
            'push' => ['support', 'database', 'queue', ...$coreNames, 'jobs'],
            'calls' => ['support', 'database', 'queue', ...$coreNames],
            'tools' => ['support', 'database', 'queue', ...$coreNames, 'calls'],
            'services' => ['support', 'database', 'queue', ...$coreNames, 'calls'],
            'gateway' => ['support', 'database', 'queue', ...$coreNames],
            'kernel' => ['support', 'database', 'queue', ...$coreNames, 'jobs', 'events', 'mail', 'webhooks', 'transfers', 'workflows', 'push', 'calls', 'tools', 'services', 'gateway'],
            'tenancy' => ['support', 'database', 'queue', ...$coreNames, 'jobs', 'events', 'mail', 'webhooks', 'transfers', 'workflows', 'push', 'calls', 'tools', 'services', 'gateway', 'kernel'],
            'billing' => ['support', 'database', 'queue', ...$coreNames, 'jobs', 'events', 'mail', 'webhooks', 'transfers', 'workflows', 'push', 'calls', 'tools', 'services', 'gateway', 'kernel'],
            'testing' => ['support', 'database', 'queue', ...$coreNames, 'jobs', 'events', 'mail', 'webhooks', 'transfers', 'workflows', 'push', 'calls', 'tools', 'services', 'gateway', 'kernel'],
            'codegen' => ['support', 'database', 'queue', ...$coreNames, 'jobs', 'events', 'mail', 'webhooks', 'transfers', 'workflows', 'push', 'calls', 'tools', 'services', 'gateway', 'kernel'],
        ],
        'sources' => [
            'packages/framework/src' => 'Tinvar\\',
        ],
    ],
];
