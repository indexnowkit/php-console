# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed". What the compatibility promise covers: [docs/bc.md](docs/bc.md).

## [0.5.0] — 2026-09-08

### Added

- **The commands themselves, as classes** (wave L, spec 18): `Console\Command\SubmitCommand`, `SubmitSubjectsCommand`,
  `ExplainCommand`, `CheckCommand`, `ConfigCommand`, `KeyGenerateCommand` — the bodies the Symfony bundle and the Yii3
  package each carried a copy of (eleven near-identical classes, ~900 lines) — and the three stubs of the optional
  packages, `SitemapNotInstalledCommand`, `HistoryNotInstalledCommand`, `StatusNotInstalledCommand` (final, each with its
  `#[AsCommand]`, over the abstract `NotInstalledCommand`: one sentence and exit 1 instead of "command not found";
  three classes because a registry keyed by class — the command map of yiisoft/yii-console, Symfony's lazy loader —
  needs a class per name). Everything that varies between adapters enters by constructor: the runner, a `Vocabulary`
  (`SubmitSubjectsCommand` takes its **name** from `Vocabulary::$submitSubjects`, so it carries no `#[AsCommand]`; a
  Symfony container registers it with the `command` and `description` attributes of the `console.command` tag),
  `KeyGenerateCommand(runner, envFileName: '.env', envFile: null)` for what `--write-env` without a value means, and a
  `Check\SampleOptions` whose sampler the adapter already put inside. An adapter on symfony/console registers these
  classes and writes no command of its own; Laravel (artisan) and Yii2 (a controller) keep parsing over the runners.
- **`Console\ConfigSourceInterface`** (`raw()`, `build()`, `packages()`): what `check` and `config` read — the adapter's
  raw configuration, its strict build (`Adapter\ConfigFactory::build()`) and the blocks of the installed optional
  packages — as one object per adapter instead of three constructor arguments. "Implement" tier: methods are not
  added without a major version (before 1.0: without a minor listed under "Changed").
- `KeyGenerateCommand::DEFAULT_LENGTH` (`32`).
- **`Command\SubmitSubjectsCommand` and `Command\ExplainCommand` take `string $classArgument = 'class'`** (wave M, spec
  19 §4.2), appended: the name of the class argument as the adapter's command always called it. Laravel passes
  `'model'`, so `Artisan::call('indexnow:submit-model', ['model' => Post::class])` and the tests written against it
  keep working once artisan registers these classes; the argument is positional on the command line either way.
- **`SubjectSampler(SubjectLoaderInterface, IndexNowKit)`**: the `--sample-class` sampler (up to `PER_CLASS` = 3
  subjects of a class, or the one with the id, through their rules) as one class over the adapter's loader — the
  Symfony bundle, Laravel, Yii2 and Yii3 each carried a 38-line copy differing in the docblock and a property name
  (72.7 % alike, Yii2 and Yii3 95.5 %). An adapter puts it into `Check\SampleOptions::$sampler`.
- **`AbstractSubjectLoader`** ("Implement" tier): the skeleton the four ORM loaders shared — `ClassNameResolver` over
  the adapter's namespaces with a marker class or a predicate, the guard with one text, the found/missing loop of
  `byIds()`, the `max(1, $limit)` of `all()` — over two abstract methods, `findOne(class, id, event)` and
  `findMany(class, limit, event)`. The adapters' loaders extend it and keep their constructors and their class names;
  what is left in each is the ORM query.

### Changed

- **`CommandDefinition::laravelSignature()` is removed** (wave M, spec 19 §6.2). Its only consumer was the Laravel
  adapter's own artisan command classes, which are gone in laravel 0.15.0: artisan registers the command classes of
  this package (`Illuminate\Console\Application::resolve()` takes any symfony/console command), so nothing renders a
  `$signature` string any more. *Migration*: a command of your own that used it — `Definitions::check()->applyTo($this)`
  on a `Symfony\Component\Console\Command\Command` registered through `$this->commands([...])`, or keep a copy of the
  32-line renderer from 0.4.

- Version 0.5.0 instead of 0.4.2: the classes above are additive, but `sitemap` and `history` build their commands on
  them and pin `^0.5`, so the adapters move together. The fixes below were written for 0.4.2 and ship here.

### Fixed

- **`indexnow:config` masks the adapter-only keys too** (`ConfigRunner::adapterOnly()` now goes through
  `maskedBlock()`). The masking added in 0.4.0 covered only the blocks of **installed** optional packages, and every
  adapter hands `history` over as a plain adapter key while `indexnowkit/history` is absent — so `history.pdo.dsn` with
  its database password was printed in full, in the table, in `--json`, and in the "the configuration does not build"
  JSON the command invites you to paste into a bug report.
- **`indexnow:key:generate --write-env` creates the env file with mode 0600 before the key is written into it.** It was
  created under the umask (usually 0644), the key went in, and only then were the permissions narrowed. An env file
  that already existed with wider permissions is not narrowed (that is the application's call) but now gets a warning
  line naming the file and the `chmod` to run.
- **A key rotation keeps a previous key containing `$` or `\` verbatim.** The old key is read from the env file and went
  into the replacement string of `preg_replace()` unescaped: an inherited `INDEXNOW_KEY=$0` wrote
  `INDEXNOW_PREVIOUS_KEY=INDEXNOW_KEY=<new key>` into the file.

### Added

- `SubmitSubjectsOptions::DEFAULT_LIMIT` (`1000`): the default of `--limit` in `Definitions::submitSubjects()`, and
  what an adapter should fall back to for a non-numeric `--limit` instead of repeating the literal.

### Changed

- Requires `indexnowkit/core ^0.13`.

## [0.4.1] — 2026-09-07

### Changed

- `explain` accepts a `FieldCondition` in `when` next to a `Condition` (core 0.12.0 splits the two interfaces).
- Requires `indexnowkit/core ^0.12`.

## [0.4.0] — 2026-09-07

### Changed

- **`indexnow:config` masks the secrets of optional packages too** (`ConfigRunner::maskedBlock()`): a `dsn` anywhere in a
  package block loses its password, user and userinfo (`history.pdo.dsn` — for pgsql the password can only live in the
  DSN), a `password`/`secret`/`token` key is masked whole. Before, only the core block was masked and the description
  "keys masked (paste it into a bug report)" invited a production database password into an issue.
- **`key_location` is masked** (global and per host): by default it is `https://host/<key>.txt`, and it went out in full.
- `indexnow:key:generate --env-file` creates a new env file with mode 0600.
- `ExplainRunner` reads with the extractor of the facade, which is the resolver's since core 0.11.
- Requires `indexnowkit/core ^0.11`.

## [0.3.1] — 2026-09-06

### Changed

- Requires `indexnowkit/core ^0.10` (`Attribute\ParamExtractor` became an injected object; nothing else in the core changed).
- `ExplainRunner` reads `when` conditions with the extractor of the facade (`IndexNowKit::$extractor`), so `explain` prints the same values the resolver reads through the adapter's readers.

## [0.3.0] — 2026-09-06

### Added

- **`check --sample=<url>` and `--sample-class=<FQCN>[:<id>]`** (both repeatable) in `Definitions::check()`: the
  `Verify\Check\SampleCheck` of `indexnowkit/verify` fetches every sample and prints what an engine would see
  (status, noindex, canonical, robots.txt), warnings only. The adapters hand the options to the check; without the
  package they are an error naming the install line. `CheckRunner::run()` did not change.
- **`ConfigRunner::run(..., array $packages = [])`** (appended): the effective block of every installed optional
  package by its name (`verify` => `VerifyConfig::toArray()`, `history` => `HistoryConfig::toArray()`), printed as
  a top-level section of `config --json` and as a table of its own — not among the adapter-only keys.

### Changed

- Requires `indexnowkit/core ^0.9`.

## [0.2.0] — 2026-09-06

### Changed

- Requires `indexnowkit/core ^0.8` (`CheckItem::$code`, `Config::toArray()`, `Condition`).

### Added

- **`check --json`, `--strict`, repeatable `--host`** (spec 17 §5.1). `Definitions::check()` declares the three;
  `CheckRunner::run()` takes `string|list<string>|null $host` (widened) plus appended `bool $json = false, bool
  $strict = false`. `--json` prints the report of [docs/check.schema.json](docs/check.schema.json) (`status`,
  `environment`, `items[].{level, code, message, host}`; an invalid configuration is one `config.invalid` error item);
  `--strict` exits 1 on warnings without changing `status`; several `--host` run the checker once per host and merge
  the reports (global lines once, host lines per host). `CheckRunner::toJson()` is public for adapters that print
  the report elsewhere.
- `OptionDefinition::LIST` / `OptionDefinition::list()`: a repeatable value option — `--name=a --name=b` in
  symfony/console, `{--name=* : …}` in an artisan signature, an `array` property (`--name=a,b`) in a Yii controller.
- **Key rotation keeps the old key** (spec 17 §5.2). `key:generate --write-env --force` writes the replaced key as
  `INDEXNOW_PREVIOUS_KEY` (an existing line is reused, else added after `INDEXNOW_KEY`) and refuses to rotate while
  that variable still holds the key of an earlier rotation (`KeyGenerateRunner::PREVIOUS_KEY_STILL_SET`, exit 1,
  nothing written). New flags `--no-previous` (rotate and drop the variable) and `--yes` (overwrite it);
  `KeyGenerateRunner::run()` takes them as appended `bool $noPrevious = false, bool $yes = false`.
- `explain` prints every `when` condition with the value it read and, for a truthy status string, the fix
  (`when: status ("draft") -> true — a non-empty string is truthy; use new Equals('status', "draft")`); custom
  conditions print their class. `explain --json` gives the same walk as one document (`class`, `id`, `event`,
  `config`, `rules[]` with `when[]`, `delivery[]`, `submits`); `ExplainRunner::run()` takes `bool $json = false`
  (appended), `Definitions::explain()` declares the flag.
- **`indexnow:config`** (spec 17 §5.7): `ConfigRunner` prints the effective configuration (defaults and environment
  applied, `key`, `previous_key` and the `hosts` keys masked) as a table, and with `--json` as
  `{"config", "adapter" (the adapter-only keys of the raw configuration, as given), "endpoints", "core"}` — the
  artifact for a bug report. `Definitions::config()` declares it; an invalid configuration is exit 1 with the error.

## [0.1.0] — 2026-09-06

First release: the console layer of the family, split out of `indexnowkit/core` 0.6 (spec 17 §4.2) so the core no
longer imports `Symfony\Component\Console\`. Requires `indexnowkit/core ^0.7` and `symfony/console ^6.4 || ^7.0 || ^8.0`.

### Added

- `IndexNowKit\Console\{CheckRunner, SubmitRunner, SubmitSubjectsRunner, ExplainRunner, KeyGenerateRunner}`,
  `ResultRenderer`, `ResultFormatterInterface`, `SubjectLoaderInterface`, `SubmitSubjectsOptions`, `Definitions`,
  `CommandDefinition`, `ArgumentDefinition`, `OptionDefinition`, `Vocabulary`, `ExitCode`, `ClassNameResolver` — moved
  from the core with their FQCN unchanged. What moved elsewhere in the same release: `Console\SubmitterFactory` and
  `SubmitterFactoryInterface` are `IndexNowKit\Adapter\*`, `Console\ResultSummary` is `IndexNowKit\Submission\ResultSummary`
  (both in the core).
