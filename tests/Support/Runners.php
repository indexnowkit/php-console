<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Support;

use IndexNowKit\Adapter\SubmitterFactory;
use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Throttle\NullThrottle;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\UrlNormalizer;

/**
 * The pieces the command tests build their runners from: a kit over a `FakeTransport`, the command submitter
 * factory, an in-memory subject loader, a configuration source over an array.
 */
final class Runners
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $overrides
     */
    public static function kit(FakeTransport $transport, array $overrides = []): IndexNowKit
    {
        return IndexNowKit::create(Factory::config($overrides), $transport, resolver: new AttributeUrlResolver(new AttributeReader(), ParamExtractor::plain()));
    }

    public static function submitters(IndexNowKit $kit, FakeTransport $transport): SubmitterFactory
    {
        return new SubmitterFactory($transport, $kit->keys, $kit->config, new MemoryDebounceStore(), new NullThrottle(), new UrlNormalizer($kit->config->baseUrl, $kit->config->maxUrlLength));
    }

    public static function loader(): ArraySubjectLoader
    {
        return new ArraySubjectLoader([ConsolePost::class => [new ConsolePost(1, 'one'), new ConsolePost(2, 'two'), new ConsolePost(3, 'draft', published: false)], ConsoleUntracked::class => [new ConsoleUntracked(7)], ConsoleArticle::class => [new ConsoleArticle(1)]]);
    }

    /**
     * @param array<string, mixed>                $raw
     * @param array<string, array<string, mixed>> $packages
     */
    public static function configSource(array $raw, array $packages = []): ArrayConfigSource
    {
        return new ArrayConfigSource($raw, $packages);
    }
}
