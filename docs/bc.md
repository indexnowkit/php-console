# Backward compatibility

`indexnowkit/console` follows SemVer and the tiers of the core's [docs/bc.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/bc.md).
**Before 1.0, minor versions may contain breaking changes**, listed under "Changed" in [CHANGELOG.md](../CHANGELOG.md).

| Tier | Members |
|---|---|
| **Call** — signatures only grow by appended, defaulted parameters; pass anything past the first argument by name | `CheckRunner`, `ConfigRunner`, `SubmitRunner`, `SubmitSubjectsRunner`, `ExplainRunner`, `KeyGenerateRunner` (constructors and `run()`), `ResultRenderer`, `Vocabulary` (constructor: named arguments), `ClassNameResolver`, `Definitions::*` |
| **Commands** — `final` classes over the runners, registered by an adapter on symfony/console; constructors take named arguments and grow only by appended, defaulted parameters | `Command\SubmitCommand`, `Command\SubmitSubjectsCommand`, `Command\ExplainCommand`, `Command\CheckCommand`, `Command\ConfigCommand`, `Command\KeyGenerateCommand`, `Command\SitemapNotInstalledCommand`, `Command\HistoryNotInstalledCommand`, `Command\StatusNotInstalledCommand`. The **name** of each is a contract (`#[AsCommand]`, or `Vocabulary::$submitSubjects` for `SubmitSubjectsCommand`); the description is not. `Command\NotInstalledCommand` is the one abstract class of the package: extend it for a stub of your own optional command, the message is its only constructor argument |
| **Implement** — methods are not added without a major version | `SubjectLoaderInterface`, `ResultFormatterInterface`, `ConfigSourceInterface` (new in 0.5.0: before 1.0 a method may still be appended in a minor, listed under "Changed") |
| **Value objects** — `final readonly`, properties only appended with defaults | `CommandDefinition`, `ArgumentDefinition`, `OptionDefinition`, `SubmitSubjectsOptions` |
| **Constants** — referenced, not hard-coded | `ExitCode::SUCCESS`, `FAILURE`, `INVALID`, `OptionDefinition::FLAG`, `VALUE`, `OPTIONAL_VALUE`, `LIST`, `CheckRunner::CONFIG_INVALID` |
| **Documents** — the shape only grows by optional members | `docs/check.schema.json`, the JSON of `check --json` (`status`, `environment`, `items[].{level, code, message, host}`); the codes are the core's `docs/check-codes.md` |

**Command surface.** The argument and option names, defaults and descriptions in `Definitions` are what the adapters
render into their commands, so they are the public API of every adapter's CLI: an option is renamed only with a
deprecation window on the adapter side. Descriptions and the printed texts of the runners are not API (they are
written for humans and get improved); exit codes are.

Not covered: log and exception message texts, anything under `tests/`.

The package pins `indexnowkit/core ^0.13`: the runners take the core's `Config`, `Checker`, `Adapter\SubmitterFactoryInterface`
and `Submission\ResultSummary`, so a core minor that changes them ships with a `console` minor.
