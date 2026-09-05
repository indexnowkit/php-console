# IndexNow console runners — `indexnowkit/console`

The bodies of the `check`, `submit`, `submit-<subject>`, `explain` and `key:generate` commands every framework
adapter of the family ships (`bin/console indexnow:check`, `php artisan indexnow:check`, `php yii indexnow/check`),
and the one declaration of their arguments and options. An adapter's command is input parsing over a runner from this
package; every framework prints the same thing, and an application reuses a runner from its own command (a tenant
loop over `SubmitSubjectsRunner` is a ten-line command). Split out of
[`indexnowkit/core`](https://github.com/indexnowkit/php/tree/main/packages/core) in core 0.7 so the core no longer
imports `symfony/console`; the FQCN (`IndexNowKit\Console\*`) are unchanged.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/console)](https://packagist.org/packages/indexnowkit/console)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/console)](https://packagist.org/packages/indexnowkit/console)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
![PHPStan](https://img.shields.io/badge/phpstan-level%209-4c1)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4)
[![License](https://img.shields.io/packagist/l/indexnowkit/console)](LICENSE)

[Русская версия](README.ru.md) · Issues and pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (the `php-*` repositories are read-only splits)

## Install

```bash
composer require indexnowkit/console        # brings indexnowkit/core and symfony/console ^6.4 || ^7.0 || ^8.0
```

With a framework adapter you install nothing: `indexnowkit/symfony-bundle`, `indexnowkit/laravel` and
`indexnowkit/yii2` require this package and register the commands. `indexnowkit/sitemap` builds its `sitemap`
command on it too.

## What is inside

| Command | Runner | What the adapter supplies |
|---|---|---|
| `check` | `Console\CheckRunner` | a closure that builds `Config` from the raw configuration (throws `ConfigurationException`); `Check\CheckInterface` services for adapter wiring and the add-on packages |
| `submit <url>...` | `Console\SubmitRunner` | — |
| `submit-<subject> <class> [ids]` | `Console\SubmitSubjectsRunner` + `SubmitSubjectsOptions` | a `Console\SubjectLoaderInterface`: class resolution (FQCN or the framework's short name), objects by id, first N objects |
| `explain <class> <id>` | `Console\ExplainRunner` | the same loader |
| `key:generate` | `Console\KeyGenerateRunner` | the default env file path |

Every runner renders to a `Symfony\Component\Console\Style\SymfonyStyle` (Laravel's `OutputStyle` is one) and
returns a `Console\ExitCode`. Shared by all of them: `Console\Definitions` (the arguments and options of every
command, declared once — `CommandDefinition`, `ArgumentDefinition`, `OptionDefinition` — and rendered by the adapter
into its framework's command), `Console\ResultFormatterInterface` (`ResultRenderer`: the table or `--json`; an
application replaces it to match its own CLI), `Console\Vocabulary` (the words that differ between frameworks:
"entity" / "model" / "record", `bin/console` / `php artisan` / `php yii`, where the configuration lives),
`Console\ClassNameResolver` (a short class name to a FQCN, with the error texts). The submitters the commands use
for `--force` / `--dry-run` (`Adapter\SubmitterFactory`) and the aggregate of a batched run
(`Submission\ResultSummary`) stay in the core: they are not CLI concerns.

## Plain PHP

```php
use IndexNowKit\Check\Checker;
use IndexNowKit\Config;
use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\IndexNowKit;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

$indexNow = IndexNowKit::create(Config::fromEnv());
$io = new SymfonyStyle(new ArrayInput([]), new ConsoleOutput());
$runner = new CheckRunner(new Checker($indexNow->config, $indexNow->keys, $indexNow->transport), new Vocabulary());
exit($runner->run($io, static fn(): Config => Config::fromEnv(), live: true));
```

Writing an adapter? [core/docs/adapters.md §14](https://github.com/indexnowkit/php/blob/main/packages/core/docs/adapters.md)
walks through the six commands; the bundle, the Laravel package and the Yii2 component are the reference wirings.

## Requirements

PHP 8.2+, `indexnowkit/core ^0.7`, `symfony/console ^6.4 || ^7.0 || ^8.0`.

## Notes for AI assistants

- Composer package `indexnowkit/console`: the command bodies (`IndexNowKit\Console\*Runner`) and the command definitions (`IndexNowKit\Console\Definitions`) the framework adapters build their `check`, `submit`, `submit-entity` / `submit-model` / `submit-record`, `explain` and `key:generate` commands on. Framework users install an adapter, not this package.
- Minimal complete snippet (every `use` included) — an application command over a runner:

```php
use IndexNowKit\Console\SubmitRunner;
use IndexNowKit\IndexNowKit;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ReannounceCommand
{
    public function __construct(private SubmitRunner $runner, private IndexNowKit $indexNow) {}

    public function run(SymfonyStyle $io): int
    {
        return $this->runner->run($io, ['https://www.example.com/pricing'], force: true, dryRun: false, json: false);
    }
}
```

- Verify: the adapter's `check` command (`bin/console indexnow:check`, `php artisan indexnow:check`, `php yii indexnow/check`) is `CheckRunner`; every runner returns an `ExitCode` (`SUCCESS` 0, `FAILURE` 1, `INVALID` 2 for bad input) and never throws for remote errors.
- Pitfalls:
  - Before core 0.7 these classes lived in `indexnowkit/core` with the same FQCN; only `Console\SubmitterFactory` (now `IndexNowKit\Adapter\SubmitterFactory`) and `Console\ResultSummary` (now `IndexNowKit\Submission\ResultSummary`) changed their namespace.
  - Option and argument names come from `Definitions` (`--force`, `--dry-run`, `--json`, `--live`, `--host`, `--probe-url`, `--limit`, `--event`, `--write-env`, `--length`): an adapter's command must not declare its own copies.
  - `--force` re-announces URLs inside the debounce window; `--dry-run` logs the request instead of sending it (`dry_run` in the configuration does the same for every submission).
  - Manual submission is `submitEntity()` in Symfony, `submitModel()` in Laravel, `submitRecord()` in Yii2; the commands are `indexnow:submit-entity`, `indexnow:submit-model`, `indexnow/submit-record`.
  - `dispatch: auto` exists in Symfony (`auto` | `messenger` | `sync` | `none`) and Yii2 (`auto` | `queue` | `sync` | `none`), **not** in Laravel (`queue` | `sync` | `none`).

## Versioning

SemVer; until 1.0 minor versions may contain breaking changes, listed in [CHANGELOG.md](CHANGELOG.md). What the
compatibility promise covers: [docs/bc.md](docs/bc.md).

MIT. IndexNow is a trademark of its owner; this project is independent and not affiliated with Microsoft, Yandex or indexnow.org.
