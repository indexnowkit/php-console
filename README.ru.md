# Консольные раннеры IndexNow — `indexnowkit/console`

Тела команд `check`, `submit`, `submit-<subject>`, `explain` и `key:generate`, которые есть у каждого адаптера
семейства (`bin/console indexnow:check`, `php artisan indexnow:check`, `php yii indexnow/check`), и единственное
объявление их аргументов и опций. Команда адаптера — разбор ввода поверх раннера из этого пакета; каждый фреймворк
печатает одно и то же, а приложение переиспользует раннер из своей команды (цикл по тенантам над
`SubmitSubjectsRunner` — команда в десять строк). Выделен из
[`indexnowkit/core`](https://github.com/indexnowkit/php/tree/main/packages/core) в core 0.7, чтобы ядро больше не
импортировало `symfony/console`; FQCN (`IndexNowKit\Console\*`) не изменились.

[English version](README.md) · Issues и pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (репозитории `php-*` — read-only сплиты)

## Установка

```bash
composer require indexnowkit/console        # тянет indexnowkit/core и symfony/console ^6.4 || ^7.0 || ^8.0
```

С адаптером фреймворка ставить ничего не нужно: `indexnowkit/symfony-bundle`, `indexnowkit/laravel` и
`indexnowkit/yii2` требуют этот пакет и регистрируют команды. `indexnowkit/sitemap` тоже строит на нём свою команду
`sitemap`.

## Что внутри

| Команда | Раннер | Что даёт адаптер |
|---|---|---|
| `check` | `Console\CheckRunner` | замыкание, собирающее `Config` из сырой конфигурации (бросает `ConfigurationException`); сервисы `Check\CheckInterface` для проводки адаптера и дополнительных пакетов |
| `submit <url>...` | `Console\SubmitRunner` | — |
| `submit-<subject> <class> [ids]` | `Console\SubmitSubjectsRunner` + `SubmitSubjectsOptions` | `Console\SubjectLoaderInterface`: разрешение класса (FQCN или короткое имя фреймворка), объекты по id, первые N объектов |
| `explain <class> <id>` | `Console\ExplainRunner` | тот же загрузчик |
| `key:generate` | `Console\KeyGenerateRunner` | путь к env-файлу по умолчанию |

Каждый раннер печатает в `Symfony\Component\Console\Style\SymfonyStyle` (`OutputStyle` Laravel — это он) и
возвращает `Console\ExitCode`. Общее для всех: `Console\Definitions` (аргументы и опции каждой команды, объявленные
один раз — `CommandDefinition`, `ArgumentDefinition`, `OptionDefinition` — и отрисованные адаптером в команду его
фреймворка), `Console\ResultFormatterInterface` (`ResultRenderer`: таблица или `--json`; приложение заменяет его
под свой CLI), `Console\Vocabulary` (слова, которые различаются между фреймворками: «entity» / «model» / «record»,
`bin/console` / `php artisan` / `php yii`, где лежит конфигурация), `Console\ClassNameResolver` (короткое имя класса
в FQCN, с текстами ошибок). Submitter'ы, которые команды берут для `--force` / `--dry-run`
(`Adapter\SubmitterFactory`), и агрегат порционного прогона (`Submission\ResultSummary`) остаются в ядре: это не
дела CLI.

## Чистый PHP

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

Пишете адаптер? [core/docs/adapters.md §14](https://github.com/indexnowkit/php/blob/main/packages/core/docs/adapters.md)
проходит по шести командам; бандл, пакет Laravel и компонент Yii2 — эталонные проводки.

## Требования

PHP 8.2+, `indexnowkit/core ^0.7`, `symfony/console ^6.4 || ^7.0 || ^8.0`.

## Заметки для AI-ассистентов

- Composer-пакет `indexnowkit/console`: тела команд (`IndexNowKit\Console\*Runner`) и определения команд (`IndexNowKit\Console\Definitions`), на которых адаптеры фреймворков строят свои `check`, `submit`, `submit-entity` / `submit-model` / `submit-record`, `explain` и `key:generate`. Пользователи фреймворков ставят адаптер, а не этот пакет.
- Минимальный полный сниппет (все `use` на месте) — команда приложения поверх раннера:

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

- Проверка: команда `check` адаптера (`bin/console indexnow:check`, `php artisan indexnow:check`, `php yii indexnow/check`) — это `CheckRunner`; каждый раннер возвращает `ExitCode` (`SUCCESS` 0, `FAILURE` 1, `INVALID` 2 при плохом вводе) и никогда не бросает на удалённых ошибках.
- Ловушки:
  - До core 0.7 эти классы жили в `indexnowkit/core` с теми же FQCN; пространство имён сменили только `Console\SubmitterFactory` (теперь `IndexNowKit\Adapter\SubmitterFactory`) и `Console\ResultSummary` (теперь `IndexNowKit\Submission\ResultSummary`).
  - Имена опций и аргументов берутся из `Definitions` (`--force`, `--dry-run`, `--json`, `--live`, `--host`, `--probe-url`, `--limit`, `--event`, `--write-env`, `--length`): команда адаптера не объявляет свои копии.
  - `--force` объявляет заново URL внутри окна дебаунса; `--dry-run` логирует запрос вместо отправки (`dry_run` в конфигурации делает то же для каждой отправки).
  - Ручная отправка: `submitEntity()` в Symfony, `submitModel()` в Laravel, `submitRecord()` в Yii2; команды — `indexnow:submit-entity`, `indexnow:submit-model`, `indexnow/submit-record`.
  - `dispatch: auto` есть в Symfony (`auto` | `messenger` | `sync` | `none`) и Yii2 (`auto` | `queue` | `sync` | `none`), в Laravel **нет** (`queue` | `sync` | `none`).

## Версионирование

SemVer; до 1.0 минорные версии могут ломать совместимость, изменения перечислены в [CHANGELOG.md](CHANGELOG.md).
Что покрывает обещание совместимости: [docs/bc.md](docs/bc.md).

MIT. IndexNow — товарный знак его владельца; проект независимый и не связан с Microsoft, Яндексом или indexnow.org.
