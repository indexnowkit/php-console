# Security

Report vulnerabilities privately to the maintainer (see `composer.json` → `authors`) or through GitHub's private
vulnerability reporting on [indexnowkit/php](https://github.com/indexnowkit/php/security). Please do not open a
public issue for an unfixed vulnerability.

## What the runners handle

- `check` prints the configured key masked (`KeyValidator::mask()`). `key:generate` prints the freshly generated key
  in full — that is its job; the operator copies it into the environment — and with `--write-env` writes
  `INDEXNOW_KEY=<key>` to the env file you name and nothing else. Do not run it in a shared terminal log.
- Class names given to `submit-<subject>` / `explain` are resolved through `ClassNameResolver` against the loader of
  the adapter; the runners instantiate nothing by name themselves.
- `check --live` sends one probe request to every configured engine with the configured key; `--probe-url` names
  the URL it announces. Use it deliberately: the probe is a real submission.
- Output escaping is Symfony Console's; values from the configuration and from the engines' responses are printed
  through it, control characters in engine bodies are collapsed by the core's `Checker` before they reach a line.

Reports are acknowledged within 5 business days; a fix or a mitigation plan follows within 30 days.
