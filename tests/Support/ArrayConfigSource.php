<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Support;

use IndexNowKit\Config;
use IndexNowKit\Console\ConfigSourceInterface;

/**
 * A configuration source over a raw array: `build()` is `Config::fromArray()` of it (throwing on an invalid value),
 * `packages()` whatever the test hands in. Counts the builds: the commands build once per run, never at construction.
 */
final class ArrayConfigSource implements ConfigSourceInterface
{
    public int $builds = 0;

    /**
     * @param array<string, mixed>                $raw
     * @param array<string, array<string, mixed>> $packages
     */
    public function __construct(private readonly array $raw, private readonly array $packages = []) {}

    public function raw(): array
    {
        return $this->raw;
    }

    public function build(): Config
    {
        ++$this->builds;

        return Config::fromArray($this->raw);
    }

    public function packages(): array
    {
        return $this->packages;
    }
}
