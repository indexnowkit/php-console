# Backward compatibility

`indexnowkit/console` follows SemVer and the tiers of the core's [docs/bc.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/bc.md).
**Before 1.0, minor versions may contain breaking changes**, listed under "Changed" in [CHANGELOG.md](../CHANGELOG.md).

| Tier | Members |
|---|---|
| **Call** — signatures only grow by appended, defaulted parameters; pass anything past the first argument by name | `CheckRunner`, `ConfigRunner`, `SubmitRunner`, `SubmitSubjectsRunner`, `ExplainRunner`, `KeyGenerateRunner` (constructors and `run()`), `ResultRenderer`, `Vocabulary` (constructor: named arguments), `ClassNameResolver`, `SubjectSampler` (the `--sample-class` sampler over the adapter's loader; `PER_CLASS`), `Definitions::*` |
| **Commands** — `final` classes over the runners, registered by an adapter on symfony/console; constructors take named arguments and grow only by appended, defaulted parameters | `Command\SubmitCommand`, `Command\SubmitSubjectsCommand`, `Command\ExplainCommand`, `Command\CheckCommand`, `Command\ConfigCommand`, `Command\KeyGenerateCommand`, `Command\SitemapNotInstalledCommand`, `Command\HistoryNotInstalledCommand`, `Command\StatusNotInstalledCommand`. The **name** of each is a contract (`#[AsCommand]`, or `Vocabulary::$submitSubjects` for `SubmitSubjectsCommand`); the description is not. `SubmitSubjectsCommand` and `ExplainCommand` take `string $classArgument = 'class'` (since 0.5.0): the name of the class argument as the adapter's command always called it (`model` in Laravel) — positional on the command line either way. `Command\NotInstalledCommand` is the one abstract class of the package: extend it for a stub of your own optional command, the message is its only constructor argument |
| **Implement** — methods are not added without a major version | `SubjectLoaderInterface`, `ResultFormatterInterface`, `ConfigSourceInterface` (new in 0.5.0: before 1.0 a method may still be appended in a minor, listed under "Changed"), `AbstractSubjectLoader` (new in 0.5.0: the skeleton of an ORM loader — `findOne()` and `findMany()` are what you implement, `resolveClass()`, `byIds()`, `all()` and `guard()` are final; a protected method is not added without a major version) |
| **Value objects** — `final readonly`, properties only appended with defaults | `CommandDefinition`, `ArgumentDefinition`, `OptionDefinition`, `SubmitSubjectsOptions` |
| **Constants** — referenced, not hard-coded | `ExitCode::SUCCESS`, `FAILURE`, `INVALID`, `OptionDefinition::FLAG`, `VALUE`, `OPTIONAL_VALUE`, `LIST`, `CheckRunner::CONFIG_INVALID` |
| **Documents** — the shape only grows by optional members | `docs/check.schema.json`, the JSON of `check --json` (`status`, `environment`, `items[].{level, code, message, host}`); the codes are the core's `docs/check-codes.md` |

**Command surface.** The argument and option names, defaults and descriptions in `Definitions` are what the adapters
render into their commands, so they are the public API of every adapter's CLI: an option is renamed only with a
deprecation window on the adapter side. `CommandDefinition::laravelSignature()` is gone in 0.5.0 (artisan registers
the command classes of this package since Laravel 0.15; the renderer had no other consumer). Descriptions and the printed texts of the runners are not API (they are
written for humans and get improved); exit codes are.

Not covered: log and exception message texts, anything under `tests/`.

The package pins `indexnowkit/core ^0.13`: the runners take the core's `Config`, `Checker`, `Adapter\SubmitterFactoryInterface`
and `Submission\ResultSummary`, so a core minor that changes them ships with a `console` minor.
