<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Unit;

use IndexNowKit\Console\SubjectSampler;
use IndexNowKit\Console\Tests\Support\ConsolePost;
use IndexNowKit\Console\Tests\Support\Runners;
use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\Testing\FakeTransport;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The `--sample-class` sampler every adapter used to copy (spec 19 §2.2): up to three subjects of a class, or the
 * one with the id, resolved through their rules.
 */
final class SubjectSamplerTest extends TestCase
{
    #[TestDox('a class without an id samples up to three subjects; with an id the one; a missing id is no URL; an unknown class is the loader\'s error')]
    public function testSampling(): void
    {
        $loader = Runners::loader();
        $sampler = new SubjectSampler($loader, Runners::kit(new FakeTransport()));

        self::assertSame(['/posts/one', '/posts/two'], $sampler(ConsolePost::class, null), 'three loaded, the draft resolves to nothing; the URLs as the rules give them, the verify sample check normalizes');
        self::assertSame(['/posts/two'], $sampler(ConsolePost::class, '2'));
        self::assertSame([], $sampler(ConsolePost::class, '999'));
        self::assertSame(3, SubjectSampler::PER_CLASS);

        $this->expectException(InvalidArgumentException::class);
        $sampler('Nope', null);
    }
}
