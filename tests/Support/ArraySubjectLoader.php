<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Support;

use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;

/**
 * In-memory stand-in for an ORM loader: objects by class and id.
 */
final class ArraySubjectLoader implements SubjectLoaderInterface
{
    /** @var list<Event> */
    public array $events = [];

    /**
     * @param array<class-string, list<object>> $objects
     */
    public function __construct(private readonly array $objects) {}

    public function resolveClass(string $class): string
    {
        $class = ltrim($class, '\\');
        if (!isset($this->objects[$class])) {
            throw new InvalidArgumentException(\sprintf('Class "%s" not found.', $class));
        }

        return $class;
    }

    public function byIds(string $class, array $ids, Event $event): array
    {
        $this->events[] = $event;
        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $match = array_values(array_filter($this->objects[$class] ?? [], static fn(object $o): bool => (string) $o->id === $id));
            if ($match === []) {
                $missing[] = $id;
            } else {
                $found[] = $match[0];
            }
        }

        return [$found, $missing];
    }

    public function all(string $class, int $limit, Event $event): iterable
    {
        return \array_slice($this->objects[$class] ?? [], 0, $limit);
    }
}
