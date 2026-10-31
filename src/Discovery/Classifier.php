<?php

declare(strict_types=1);

namespace Tinvar\Discovery;

use Tinvar\Attributes\Action;
use Tinvar\Attributes\Channel;
use Tinvar\Attributes\Command;
use Tinvar\Attributes\Config;
use Tinvar\Attributes\ErrorPage;
use Tinvar\Attributes\Export;
use Tinvar\Attributes\FieldsOf;
use Tinvar\Attributes\Import;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Listener;
use Tinvar\Attributes\NotRegistered;
use Tinvar\Attributes\OutboundPolicy;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Policy;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\Scenario;
use Tinvar\Attributes\Schedule;
use Tinvar\Attributes\ServiceCall;
use Tinvar\Attributes\Shared;
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
 * ClassAttributes, and does not make each of them a page.
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
     * The kinds $class is, and the classes its #[Policy] attributes decide about.
     *
     * @template T of object
     * @param \ReflectionClass<T> $class
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
        if (self::has($class, Page::class) || self::has($class, Layout::class) || self::has($class, ErrorPage::class)) {
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
        if ($class->implementsInterface(SharedData::class) || self::onMethod($class, Shared::class)) {
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
        if ($class->isSubclassOf(Model::class)) {
            $kinds[] = 'models';
        }
        if ($class->isSubclassOf(Data::class)) {
            $kinds[] = 'payloads';
        }
        if (self::has($class, Scenario::class)) {
            $kinds[] = 'scenarios';
        }
        if ($class->implementsInterface(Workflow::class)) {
            $kinds[] = 'workflows';
        } elseif ($class->implementsInterface(Job::class)) {
            $kinds[] = 'jobs';
        }
        if (self::onMethod($class, Listener::class)) {
            $kinds[] = 'listeners';
        }
        if ($class->implementsInterface(Mailable::class)) {
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

        /** @var list<class-string> $subjects */
        return [$kinds, $subjects];
    }

    /**
     * The framework's attributes on $class, or on one of its own public
     * methods, that nothing registers it by: each as `#[Name]` on where it
     * is. None for a class that is a kind, a form request, or no class to
     * build.
     *
     * @template T of object
     * @param \ReflectionClass<T> $class
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

    private static function framework(string $attribute): bool
    {
        return str_starts_with($attribute, 'Tinvar\\Attributes\\') && !\in_array($attribute, self::UNCLAIMED_ALLOWED, true);
    }

    private static function short(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }

    /**
     * @template T of object
     * @param \ReflectionClass<T> $class
     * @param class-string $attribute
     */
    private static function has(\ReflectionClass $class, string $attribute): bool
    {
        return $class->getAttributes($attribute) !== [];
    }

    /**
     * Whether a public method of $class carries $attribute.
     *
     * @template T of object
     * @param \ReflectionClass<T> $class
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
