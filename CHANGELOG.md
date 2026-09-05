# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed". What the compatibility promise covers: [docs/bc.md](docs/bc.md).

## [Unreleased]

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
