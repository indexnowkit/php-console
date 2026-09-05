# Contributing

This repository is a read-only split of [`indexnowkit/php`](https://github.com/indexnowkit/php) (`packages/console`).
Please open issues and pull requests there; releases are tagged in the monorepo as `console@x.y.z` and mirrored here.

Quick rules (details in the monorepo's CONTRIBUTING.md):

- Every change comes with tests (`RunnersTest`, `DefinitionsTest`); the printed texts follow one rule: the fact,
  what is allowed, how to fix it.
- Option and argument names in `Definitions` are the CLI of every adapter: rename only with a deprecation window.
- phpstan level 9 and php-cs-fixer must pass.
- The package is a consumer of `indexnowkit/core`: nothing here may require a change in the core to work.
