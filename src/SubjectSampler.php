<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Event;
use IndexNowKit\IndexNowKit;

/**
 * `check --sample-class=<FQCN>[:<id>]`: the URLs of up to three subjects of the class (or of the one with the id),
 * resolved through their `#[IndexNow]` rules the way a submission resolves them, over the adapter's
 * {@see SubjectLoaderInterface}. What the sample check of `indexnowkit/verify` calls through
 * `Check\SampleOptions::$sampler`; every adapter used to carry its own copy under its ORM's noun.
 */
final class SubjectSampler
{
    /** Subjects fetched per class without an id. */
    public const PER_CLASS = 3;

    public function __construct(private readonly SubjectLoaderInterface $subjects, private readonly IndexNowKit $indexNow) {}

    /**
     * @return list<string>
     */
    public function __invoke(string $class, ?string $id): array
    {
        $class = $this->subjects->resolveClass($class);
        if ($id !== null) {
            [$found] = $this->subjects->byIds($class, [$id], Event::Updated);
            $subjects = $found;
        } else {
            $subjects = $this->subjects->all($class, self::PER_CLASS, Event::Updated);
        }

        return $this->indexNow->urlsForAll($subjects, Event::Updated);
    }
}
