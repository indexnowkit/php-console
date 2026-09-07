<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;

/**
 * What the `check` and `config` commands read: the adapter's raw configuration, the strict build of it, and the
 * blocks of the installed optional packages. One implementation per adapter (the bundle's is over its processed
 * configuration tree and `%kernel.environment%`, Yii3's over its `IndexNow` facade), so the commands themselves know
 * neither the framework nor its container.
 */
interface ConfigSourceInterface
{
    /**
     * The raw array the adapter feeds `Config::fromArray()`, env placeholders resolved, the adapter's own blocks
     * included: `config` prints the adapter-only keys out of it.
     *
     * @return array<string, mixed>
     */
    public function raw(): array;

    /**
     * The strict build: the adapter's `Adapter\ConfigFactory::build()`, which throws on an invalid value (the runtime
     * path logs one critical line and disables IndexNow instead).
     *
     * @throws ConfigurationException
     */
    public function build(): Config;

    /**
     * The effective block of every installed optional package, by its name (`verify` => `VerifyConfig::toArray()`,
     * `history` => `HistoryConfig::toArray()`): `config` prints each as a section of its own.
     *
     * @return array<string, array<string, mixed>>
     */
    public function packages(): array;
}
