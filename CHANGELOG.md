# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed". What the compatibility promise covers: [docs/bc.md](docs/bc.md).

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
