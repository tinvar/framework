<?php

declare(strict_types=1);

namespace Tinvar\Discovery;

use Tinvar\Attributes\Action;
use Tinvar\Attributes\AdminResource;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Channel;
use Tinvar\Attributes\Command;
use Tinvar\Attributes\Config;
use Tinvar\Attributes\Cors;
use Tinvar\Attributes\ErrorPage;
use Tinvar\Attributes\Export;
use Tinvar\Attributes\FieldsOf;
use Tinvar\Attributes\Import;
use Tinvar\Attributes\Inbox;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Listener;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\Notification;
use Tinvar\Attributes\NotRegistered;
use Tinvar\Attributes\OutboundPolicy;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Policy;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\Queue;
use Tinvar\Attributes\Scenario;
use Tinvar\Attributes\Schedule;
use Tinvar\Attributes\ServiceCall;
use Tinvar\Attributes\Shared;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Attributes\Throttle;
use Tinvar\Attributes\Tool;
use Tinvar\Attributes\Webhook;
use Tinvar\Data\Data;
use Tinvar\Data\SharedData;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Jobs\Job;
use Tinvar\Mail\Mailable;
use Tinvar\Orm\Model;
use Tinvar\Validation\FormRequest;
use Tinvar\Workflows\Workflow;

/**
 * What the framework registers a class as, read from what it declares
 * (ADR 0146): its attributes, the interfaces it implements, the class it
 * extends. DiscoveredClasses::KINDS lists each kind and what puts a class
 * in it.
 *
 * Only a class that can be built is any kind: an abstract class, an
 * interface or a trait is a part of one, and an enum is registered only as
 * a sort or filter field enum (#[FieldsOf]). Attributes are read off the
 * class itself, never its parents -- an attribute on an abstract base
 * declares for its subclasses, which the registries read through
 * ClassAttributes, and does not make each of them a page. Only those it
 * reads that way (a check, a middleware) may sit on a base: one that
 * registers the class it is on -- a #[Page], a #[Policy] -- is refused
 * there (notInherited()) rather than dropped.
 *
 * And what a class may not be: one carrying an attribute of the
 * framework's, on itself or on a public method, that none of the kinds
 * takes -- a #[Panel] or an #[Authorize] on a class nothing registers --
 * declares a check that would never be asked. That is refused, as
 * KnownAttributes refuses a misspelt one (unclaimed()).
 */
final class Classifier
{
    /** The framework's attributes on a class that are no kind's, and need none: what they declare is read by name. */
    private const array UNCLAIMED_ALLOWED = [OutboundPolicy::class, NotRegistered::class];

    /**
     * The framework's class attributes a subclass takes from an abstract base,
     * a trait or (for #[NotRegistered]) that mean the same there: what the
     * registries read through ClassAttributes::of(). Every other one on an
     * abstract class, an interface or a trait would be dropped (notInherited()).
     */
    private const array INHERITED = [
        Authorize::class, Cors::class, Middleware::class, Panel::class, Queue::class, TenantFrom::class, Throttle::class,
        OutboundPolicy::class, NotRegistered::class, \Tinvar\Attributes\Domain::class,
    ];

    /** The kinds a #[Domain] holds to a host (ADR 0149): every entry point a request reaches through its stack. */
    private const array HOSTED = ['routes', 'queries', 'actions', 'endpoints', 'channels', 'transfers', 'inboxes'];

    /**
     * The kinds $class is, and the classes its #[Policy] attributes decide about.
     *
     * @param \ReflectionClass<object> $class
     * @return array{list<string>, list<class-string>} kinds, policy subjects
     */
    public static function classify(\ReflectionClass $class): array
    {
        if ($class->isEnum()) {
            return [self::has($class, FieldsOf::class) ? ['fieldEnums'] : [], []];
        }
        if ($class->isInterface() || $class->isTrait() || $class->isAbstract()) {
            return [[], []];
        }

        $name = $class->getName();
        $kinds = [];
        if (self::has($class, Page::class) || self::has($class, Layout::class) || self::has($class, ErrorPage::class)
            || self::has($class, AdminResource::class)) {
            $kinds[] = 'routes';
        }
        $query = self::has($class, Query::class);
        if ($query) {
            $kinds[] = 'queries';
        }
        $action = self::onMethod($class, Action::class);
        if ($action) {
            $kinds[] = 'actions';
        }
        if (EndpointRegistry::holdsEndpoints($name)) {
            $kinds[] = 'endpoints';
        }
        if (self::has($class, Channel::class)) {
            $kinds[] = 'channels';
        }
        if (self::has($class, Export::class) || self::has($class, Import::class)) {
            $kinds[] = 'transfers';
        }
        if (is_a($name, SharedData::class, true) || self::onMethod($class, Shared::class)) {
            $kinds[] = 'shared';
        }
        $subjects = array_map(static fn (\ReflectionAttribute $a): string => $a->newInstance()->subject, $class->getAttributes(Policy::class));
        if ($subjects !== []) {
            $kinds[] = 'policies';
        }
        if (self::has($class, Schedule::class)) {
            $kinds[] = 'tasks';
        }
        if (self::has($class, Command::class)) {
            $kinds[] = 'commands';
        }
        if (self::has($class, Config::class)) {
            $kinds[] = 'configs';
        }
        if (is_subclass_of($name, Model::class)) {
            $kinds[] = 'models';
        }
        if (is_subclass_of($name, Data::class)) {
            $kinds[] = 'payloads';
        }
        if (self::has($class, Scenario::class)) {
            $kinds[] = 'scenarios';
        }
        if (is_a($name, Workflow::class, true)) {
            $kinds[] = 'workflows';
        } elseif (is_a($name, Job::class, true)) {
            $kinds[] = 'jobs';
        }
        if (self::onMethod($class, Listener::class)) {
            $kinds[] = 'listeners';
        }
        if (is_a($name, Mailable::class, true)) {
            $kinds[] = 'mailables';
        }
        if (($query || $action) && (self::has($class, Tool::class) || self::onMethod($class, Tool::class))) {
            $kinds[] = 'tools';
        }
        if (($query || $action) && (self::has($class, ServiceCall::class) || self::onMethod($class, ServiceCall::class))) {
            $kinds[] = 'services';
        }
        if (self::has($class, Webhook::class)) {
            $kinds[] = 'webhooks';
        }
        if (self::has($class, Notification::class)) {
            $kinds[] = 'notifications';
        }
        if (self::has($class, Inbox::class)) {
            $kinds[] = 'inboxes';
        }

        /** @var list<class-string> $subjects */
        return [$kinds, $subjects];
    }

    /**
     * The framework's attributes on $class, or on one of its own public
     * methods, that nothing registers it by: each as `#[Name]` on where it
     * is. None for a class that is a kind, a form request, or no class to
     * build.
     *
     * @param \ReflectionClass<object> $class
     * @param list<string> $kinds what classify() found it to be
     * @return list<string>
     */
    public static function unclaimed(\ReflectionClass $class, array $kinds): array
    {
        if ($kinds !== [] || $class->isInterface() || $class->isTrait() || ($class->isAbstract() && !$class->isEnum()) || $class->isSubclassOf(FormRequest::class)) {
            return [];
        }

        $found = [];
        foreach ($class->getAttributes() as $attribute) {
            if (self::framework($attribute->getName())) {
                $found[] = '#[' . self::short($attribute->getName()) . ']';
            }
        }
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }
            foreach ($method->getAttributes() as $attribute) {
                if (self::framework($attribute->getName())) {
                    $found[] = '#[' . self::short($attribute->getName()) . "] on {$method->getName()}()";
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * What is wrong with $class, an abstract class, an interface or a trait,
     * carrying a framework attribute that registers only the class it is on:
     * a #[Page], a #[Policy], a #[Schedule] there is read off no subclass, so
     * it is dropped without a word -- and a #[Policy] dropped that way can
     * leave the subject decided by a more permissive policy of its parent's
     * (PolicyRegistry::resolve()). Null for a class that can be built, and
     * for the attributes a subclass inherits (INHERITED).
     *
     * @param \ReflectionClass<object> $class
     */
    public static function notInherited(\ReflectionClass $class): ?string
    {
        if ($class->isEnum()) {
            return null;
        }
        $what = match (true) {
            $class->isInterface() => 'is an interface',
            $class->isTrait() => 'is a trait',
            $class->isAbstract() => 'is abstract',
            default => null,
        };
        if ($what === null) {
            return null;
        }

        $found = [];
        foreach ($class->getAttributes() as $attribute) {
            $name = $attribute->getName();
            if (str_starts_with($name, 'Tinvar\\Attributes\\') && !\in_array($name, self::INHERITED, true)) {
                $found[] = '#[' . self::short($name) . ']';
            }
        }
        if ($found === []) {
            return null;
        }
        $found = array_values(array_unique($found));
        $one = \count($found) === 1;
        $where = \in_array('#[Policy]', $found, true)
            ? 'put #[Policy] on the concrete policy class'
            : 'put ' . ($one ? 'it' : 'them') . ' on each concrete class';

        return "{$class->getName()} {$what} and carries " . implode(', ', $found)
            . ($one ? ', which registers only the class it is on and is' : ', which register only the class they are on and are')
            . " inherited by nothing, so it would be dropped: {$where}.";
    }

    /**
     * What is wrong with $class carrying a #[Domain] -- on itself, a method,
     * an ancestor or a trait -- when none of the kinds it is holds a host:
     * a task, a job or a webhook is reached by no request a host could hold,
     * and the host would be dropped without a word. Null when it is one of
     * them, or declares none.
     *
     * @param \ReflectionClass<object> $class
     * @param list<string> $kinds what classify() found it to be
     */
    public static function hostless(\ReflectionClass $class, array $kinds): ?string
    {
        if ($kinds === [] || array_intersect($kinds, self::HOSTED) !== []) {
            return null;
        }
        $declared = \Tinvar\Attributes\ClassAttributes::of($class, \Tinvar\Attributes\Domain::class) !== [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $declared = $declared || $method->getAttributes(\Tinvar\Attributes\Domain::class) !== [];
        }
        if (!$declared) {
            return null;
        }

        return "{$class->getName()} is held to a host by #[Domain], and is " . implode(', ', $kinds) . ', which no host holds: '
            . 'only a page, a layout, an endpoint, an action, a query, a channel, a transfer or an inbox is reached by a request a host '
            . 'could hold (ADR 0149). Take it off.';
    }

    private static function framework(string $attribute): bool
    {
        return str_starts_with($attribute, 'Tinvar\\Attributes\\') && !\in_array($attribute, self::UNCLAIMED_ALLOWED, true);
    }

    private static function short(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param class-string $attribute
     */
    private static function has(\ReflectionClass $class, string $attribute): bool
    {
        return $class->getAttributes($attribute) !== [];
    }

    /**
     * Whether a public method of $class carries $attribute.
     *
     * @param \ReflectionClass<object> $class
     * @param class-string $attribute
     */
    private static function onMethod(\ReflectionClass $class, string $attribute): bool
    {
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes($attribute) !== []) {
                return true;
            }
        }

        return false;
    }
}
