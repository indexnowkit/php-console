<?php

// The taint entry points of this package (audit 0.13 T20; bin/taint, .github/workflows/taint.yml): the public API called
// with request data, so that Psalm's taint analysis has a source to follow into the sinks (SQL, files, HTML, headers).
// A library has no taint source of its own — without this file Psalm reports nothing and proves nothing. Not a test:
// PHPUnit does not load it, phpstan analyses it at the level of the test suite, only psalm.xml lists it.

declare(strict_types=1);

namespace IndexNowKit\Taint;

use IndexNowKit\Config;
use IndexNowKit\Console\ConfigRunner;
use IndexNowKit\Console\KeyGenerateRunner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/** A request value as a string: the taint of the superglobal, none of the mixed. */
function input(string $name): string
{
    $value = $_GET[$name] ?? $_POST[$name] ?? $_SERVER[$name] ?? null;

    return \is_string($value) ? $value : '';
}

/** @var array<string, mixed> $post */
$post = $_POST;
$io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
(new KeyGenerateRunner())->run($io, 32, true, input('env_file'), true);
(new ConfigRunner())->run($io, static fn(): Config => Config::fromArray($post), $post);
