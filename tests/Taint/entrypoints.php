<?php

// The taint entry points of this package (audit 0.13 T20; bin/taint, .github/workflows/taint.yml): the public API called
// with request data, so that Psalm's taint analysis has a source to follow into the sinks (SQL, files, HTML, headers).
// A library has no taint source of its own — without this file Psalm reports nothing and proves nothing. Not a test:
// PHPUnit does not load it, phpstan analyses it at the level of the test suite, only psalm.xml lists it.

declare(strict_types=1);

namespace IndexNowKit\Taint;

use IndexNowKit\Check\Checker;
use IndexNowKit\Check\SampleOptions;
use IndexNowKit\Config;
use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\Command\CheckCommand;
use IndexNowKit\Console\Command\ConfigCommand;
use IndexNowKit\Console\ConfigRunner;
use IndexNowKit\Console\ConfigSourceInterface;
use IndexNowKit\Console\KeyGenerateRunner;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Testing\FakeTransport;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/** A request value as a string: the taint of the superglobal, none of the mixed. */
function input(string $name): string
{
    $value = $_GET[$name] ?? $_POST[$name] ?? $_SERVER[$name] ?? null;

    return \is_string($value) ? $value : '';
}

/** The raw configuration of an adapter, request-shaped: what `check` and `config` read through `ConfigSourceInterface`. */
final class RequestConfigSource implements ConfigSourceInterface
{
    public function raw(): array
    {
        /** @var array<string, mixed> $post */
        $post = $_POST;

        return $post;
    }

    public function build(): Config
    {
        return Config::fromArray($this->raw());
    }

    public function packages(): array
    {
        /** @var array<string, array<string, mixed>> $packages */
        $packages = \is_array($_POST['packages'] ?? null) ? $_POST['packages'] : [];

        return $packages;
    }
}

/** @var array<string, mixed> $post */
$post = $_POST;
$io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
(new KeyGenerateRunner())->run($io, 32, true, input('env_file'), true);
(new ConfigRunner())->run($io, static fn(): Config => Config::fromArray($post), $post);

// the commands over the same request-shaped source: the options come from the command line, the configuration from the request
$source = new RequestConfigSource();
$config = $source->build();
$checker = new Checker($config, StaticKeyProvider::fromConfig($config), new FakeTransport());
(new CheckCommand(new CheckRunner($checker), $source, new SampleOptions()))->run(new ArrayInput(['--host' => [input('host')], '--sample' => [input('sample')], '--sample-class' => [input('sample_class')], '--probe-url' => input('probe_url')]), new NullOutput());
(new ConfigCommand(new ConfigRunner(), $source))->run(new ArrayInput(['--json' => true]), new NullOutput());
