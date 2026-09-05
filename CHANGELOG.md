# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed". What the compatibility promise covers: [docs/bc.md](docs/bc.md).

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
