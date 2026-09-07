<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use Closure;
use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;

/**
 * The skeleton of an ORM's {@see SubjectLoaderInterface}: the class argument resolved through
 * {@see ClassNameResolver} (an FQCN, or a short name under the adapter's namespaces) and guarded to be one the ORM
 * manages, the found/missing split of {@see byIds()}, the `max(1, $limit)` of {@see all()}. What the ORM does is
 * the two abstract methods: one object by id, up to N objects. Doctrine, Eloquent and the two Active Records each
 * wrote the skeleton around them once more; an application still decorates the adapter's loader (soft deletes,
 * tenant scoping, another id format) rather than extending this.
 *
 * "Implement" tier (docs/bc.md): the abstract and the protected methods are the contract, a method is not added
 * without a major version.
 */
abstract class AbstractSubjectLoader implements SubjectLoaderInterface
{
    private readonly ClassNameResolver $classes;

    /** @var (Closure(class-string): bool) */
    private readonly Closure $accepts;

    /**
     * @param list<string>                  $namespaces namespaces a short class name is looked up in
     * @param class-string|Closure(class-string): bool $marker the class or interface a subject class must extend or implement
     *                                                   (`Model::class`, `ActiveRecordInterface::class`), or a predicate
     *                                                   when the ORM has no marker (a managed Doctrine entity)
     * @param string                        $noun       what the texts call an accepted class: `an Eloquent model`, `an ActiveRecord class`
     */
    public function __construct(array $namespaces, string|Closure $marker, private readonly string $noun)
    {
        $this->accepts = $marker instanceof Closure ? $marker : static fn(string $class): bool => is_a($class, $marker, true);
        $this->classes = new ClassNameResolver($namespaces, $this->accepts, $noun);
    }

    /**
     * @return class-string
     */
    final public function resolveClass(string $class): string
    {
        return $this->guard($this->classes->resolve($class));
    }

    final public function byIds(string $class, array $ids, Event $event): array
    {
        $class = $this->guard($class);
        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $subject = $this->findOne($class, $id, $event);
            if ($subject === null) {
                $missing[] = $id;
            } else {
                $found[] = $subject;
            }
        }

        return [$found, $missing];
    }

    final public function all(string $class, int $limit, Event $event): iterable
    {
        return $this->findMany($this->guard($class), max(1, $limit), $event);
    }

    /**
     * One subject of the class by its id, null when there is none. $event tells a deleted-event lookup to include
     * soft-deleted rows where the ORM has them.
     *
     * @param class-string $class
     */
    abstract protected function findOne(string $class, string $id, Event $event): ?object;

    /**
     * Up to $limit (>= 1) subjects of the class, in the ORM's order; a generator when the ORM reads in batches.
     *
     * @param class-string $class
     *
     * @return iterable<object>
     */
    abstract protected function findMany(string $class, int $limit, Event $event): iterable;

    /**
     * The class once it passed the marker (a class-string the command already resolved, or one an application
     * handed `byIds()` directly); the text is {@see ClassNameResolver}'s, so the family has one.
     *
     * @return class-string
     *
     * @throws InvalidArgumentException
     */
    final protected function guard(string $class): string
    {
        if (!class_exists($class) || !($this->accepts)($class)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not %s: the command loads objects by id through the ORM and resolves their URLs from #[IndexNow] rules, so it needs a class the ORM manages.', $class, $this->noun));
        }

        return $class;
    }
}
