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
`indexnowkit/yii2` and `indexnowkit/yii3` require this package and register the commands. `indexnowkit/sitemap` builds its `sitemap`
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

## Commands: how an application on symfony/console registers them

Since 0.5.0 the commands are classes of this package (`IndexNowKit\Console\Command\*`), the ones the Symfony bundle and
the Yii3 package register. An application with a `Symfony\Component\Console\Application` of its own registers them the
same way: build the runners, hand each command what varies by constructor — nothing here knows a framework or a
container.

```php
use IndexNowKit\Check\Checker;
use IndexNowKit\Check\SampleOptions;
use IndexNowKit\Config;
use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\Command\CheckCommand;
use IndexNowKit\Console\Command\ConfigCommand;
use IndexNowKit\Console\Command\KeyGenerateCommand;
use IndexNowKit\Console\Command\SubmitCommand;
use IndexNowKit\Console\ConfigRunner;
use IndexNowKit\Console\ConfigSourceInterface;
use IndexNowKit\Console\KeyGenerateRunner;
use IndexNowKit\Console\SubmitRunner;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\IndexNowKit;
use Symfony\Component\Console\Application;

final class EnvConfigSource implements ConfigSourceInterface          // what check and config read
{
    public function raw(): array { return Config::fromEnv()->toArray(); }
    public function build(): Config { return Config::fromEnv(); }    // throws ConfigurationException when invalid
    public function packages(): array { return []; }                 // the blocks of the installed optional packages
}

$indexNow = IndexNowKit::create(Config::fromEnv());
$words = new Vocabulary(cli: 'bin/indexnow', configLocation: 'the INDEXNOW_* env vars');
$submitters = $indexNow->submitterFactory();                         // --force / --dry-run build their own submitter

$application = new Application('indexnow');
$application->addCommands([
    new CheckCommand(new CheckRunner(new Checker($indexNow->config, $indexNow->keys, $indexNow->transport), $words), new EnvConfigSource(), new SampleOptions()),
    new ConfigCommand(new ConfigRunner($words), new EnvConfigSource()),
    new SubmitCommand(new SubmitRunner($indexNow, $submitters)),
    new KeyGenerateCommand(new KeyGenerateRunner($words), envFileName: '.env'),   // --write-env without a value: <cwd>/.env
]);
$application->run();
```

`SubmitSubjectsCommand` (`indexnow:submit-<subject>`, its name is `Vocabulary::$submitSubjects`) and `ExplainCommand`
need a `SubjectLoaderInterface` — the ORM of the application — and are registered by the adapters that have one.
`indexnow:sitemap` is `IndexNowKit\Sitemap\Console\SitemapCommand` of `indexnowkit/sitemap`, `indexnow:history` and
`indexnow:status` are `IndexNowKit\History\Console\HistoryCommand` / `StatusCommand` of `indexnowkit/history`; without
the package, `Command\SitemapNotInstalledCommand`, `HistoryNotInstalledCommand` and `StatusNotInstalledCommand` stand in
under the same names with the install line and exit 1. In a Symfony container `SubmitSubjectsCommand` is registered
lazily with the `command` and `description` attributes of the `console.command` tag (its name is not an attribute of
the class); every other command carries `#[AsCommand]` and is lazy on its own.

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

No framework at all? Install the CLI instead of writing this: [`indexnowkit/cli`](https://github.com/indexnowkit/php/tree/main/packages/cli)
(`composer global require indexnowkit/cli`, a PHAR, a Docker image) registers these classes over `INDEXNOW_*` variables and a
state file. Writing an adapter? [core/docs/adapters.md §14](https://github.com/indexnowkit/php/blob/main/packages/core/docs/adapters.md)
walks through the six commands; the bundle, the Laravel package, the Yii2 component and the Yii3 package are the reference wirings.

## Requirements

PHP 8.2+, `indexnowkit/core ^0.13`, `symfony/console ^6.4 || ^7.0 || ^8.0`.

## Notes for AI assistants

- Composer package `indexnowkit/console`: the command bodies (`IndexNowKit\Console\*Runner`), the command definitions (`IndexNowKit\Console\Definitions`) and, since 0.5.0, the symfony/console command classes themselves (`IndexNowKit\Console\Command\*`: `check`, `config`, `submit`, `submit-entity` / `submit-record`, `explain`, `key:generate`, the three "not installed" stubs) that the Symfony bundle, the Laravel package (artisan runs any symfony/console command; `submit-model` through a `LazyCommand`, the class argument named `model`) and the Yii3 package register; Yii2 (a `yii\console\Controller`) builds its actions on the runners. Since 0.5.0 also `Console\SubjectSampler` (the `--sample-class` sampler) and `Console\AbstractSubjectLoader` (the skeleton of an ORM loader). Framework users install an adapter, not this package.
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
  - Manual submission is `submitEntity()` in Symfony, `submitModel()` in Laravel, `submitRecord()` in Yii2 and Yii3; the commands are `indexnow:submit-entity`, `indexnow:submit-model`, `indexnow/submit-record` (Yii2), `indexnow:submit-record` (Yii3).
  - `dispatch: auto` exists in Symfony (`auto` | `messenger` | `sync` | `none`) and Yii2 (`auto` | `queue` | `sync` | `none`), **not** in Laravel (`queue` | `sync` | `none`); Yii3 has `sync` | `none` only.

## Versioning

SemVer; until 1.0 minor versions may contain breaking changes, listed in [CHANGELOG.md](CHANGELOG.md). What the
compatibility promise covers: [docs/bc.md](docs/bc.md).

MIT. IndexNow is a trademark of its owner; this project is independent and not affiliated with Microsoft, Yandex or indexnow.org.
