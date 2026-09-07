<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Unit;

use Closure;
use IndexNowKit\Console\AbstractSubjectLoader;
use IndexNowKit\Console\Tests\Support\ConsoleArticle;
use IndexNowKit\Console\Tests\Support\ConsolePost;
use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The skeleton of the ORM loaders (spec 19 §4.3): class resolution with the marker or a predicate, the found/missing
 * split, `max(1, $limit)`, the event reaching the ORM, one guard text.
 */
final class AbstractSubjectLoaderTest extends TestCase
{
    /** @var list<array{class-string, int, Event}> what findMany() was asked for */
    public static array $calls = [];

    protected function setUp(): void
    {
        self::$calls = [];
    }

    /**
     * @param list<ConsolePost> $rows
     */
    private static function loader(array $rows, string|Closure $marker = ConsolePost::class): AbstractSubjectLoader
    {
        return new class (['IndexNowKit\\Console\\Tests\\Support'], $marker, 'a console post', $rows) extends AbstractSubjectLoader {
            /** @param list<ConsolePost> $rows */
            public function __construct(array $namespaces, string|Closure $marker, string $noun, private readonly array $rows)
            {
                parent::__construct($namespaces, $marker, $noun);
            }

            protected function findOne(string $class, string $id, Event $event): ?object
            {
                foreach ($this->rows as $row) {
                    if ((string) $row->id === $id) {
                        return $row;
                    }
                }

                return null;
            }

            protected function findMany(string $class, int $limit, Event $event): iterable
            {
                AbstractSubjectLoaderTest::$calls[] = [$class, $limit, $event];

                return \array_slice($this->rows, 0, $limit);
            }
        };
    }

    #[TestDox('resolveClass() takes an FQCN or a short name under the namespaces, and refuses a class the marker rejects')]
    public function testResolveClass(): void
    {
        $loader = self::loader([]);

        self::assertSame(ConsolePost::class, $loader->resolveClass(ConsolePost::class));
        self::assertSame(ConsolePost::class, $loader->resolveClass('\\' . ConsolePost::class));
        self::assertSame(ConsolePost::class, $loader->resolveClass('ConsolePost'));

        try {
            $loader->resolveClass(ConsoleArticle::class);
            self::fail('the marker rejects it');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('is not a console post', $e->getMessage());
        }
        try {
            $loader->resolveClass('Nope');
            self::fail('unknown class');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Class "Nope" not found', $e->getMessage());
        }
    }

    #[TestDox('a predicate instead of a marker class: the ORM without a base class (a managed Doctrine entity)')]
    public function testPredicateMarker(): void
    {
        $loader = self::loader([], static fn(string $class): bool => $class === ConsoleArticle::class);

        self::assertSame(ConsoleArticle::class, $loader->resolveClass('ConsoleArticle'));
        $this->expectException(InvalidArgumentException::class);
        $loader->all(ConsolePost::class, 1, Event::Updated);
    }

    #[TestDox('byIds() splits found and missing in the order given; all() asks the ORM for at least one and hands the event through')]
    public function testByIdsAndAll(): void
    {
        $one = new ConsolePost(1, 'one');
        $two = new ConsolePost(2, 'two');
        $loader = self::loader([$one, $two]);

        self::assertSame([[$two, $one], ['9']], $loader->byIds(ConsolePost::class, ['2', '9', '1'], Event::Deleted));
        self::assertSame([$one], [...$loader->all(ConsolePost::class, 0, Event::Deleted)]);
        self::assertSame([$one, $two], [...$loader->all(ConsolePost::class, 5, Event::Created)]);
        self::assertSame([[ConsolePost::class, 1, Event::Deleted], [ConsolePost::class, 5, Event::Created]], self::$calls);
    }

    #[TestDox('byIds() and all() guard the class too: an application may call them with a class the command never resolved')]
    public function testGuardOnDirectCalls(): void
    {
        $loader = self::loader([]);

        try {
            $loader->byIds(stdClass::class, ['1'], Event::Updated);
            self::fail('a class the marker rejects');
        } catch (InvalidArgumentException $e) {
            self::assertSame('"stdClass" is not a console post: the command loads objects by id through the ORM and resolves their URLs from #[IndexNow] rules, so it needs a class the ORM manages.', $e->getMessage());
        }
    }
}
