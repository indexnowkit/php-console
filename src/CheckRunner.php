<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Check\CheckItem;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Body of `indexnow:check`: validates the configuration, runs the {@see CheckerInterface} (key files, engines, live
 * probe, adapter checks) and prints one line per finding, or the report as JSON (`--json`, schema in
 * docs/check.schema.json). Adapter-specific wiring (is the ORM hook active? is the queue routed?) is a
 * `Check\CheckInterface` the adapter registers, not a special case here.
 *
 * Exit codes: 0 when the report has no error; 1 on an error, or on a warning with `--strict`; an invalid
 * configuration is an error. `--strict` changes the exit code only, never the report's `status`.
 */
final class CheckRunner
{
    /** The code of the line `--json` prints when the configuration does not build ({@see Config} threw). */
    public const CONFIG_INVALID = 'config.invalid';

    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(private readonly CheckerInterface $checker, private readonly Vocabulary $words = new Vocabulary()) {}

    /**
     * @param callable(): mixed         $validateConfig builds the Config from the raw adapter configuration; throws
     *                                                  ConfigurationException when it is invalid. When it returns the
     *                                                  Config, `--json` reports its `environment`
     * @param bool                      $live           send a real probe request to every configured engine
     * @param string|list<string>|null  $host           check only this host, or these hosts (`--host` repeated)
     * @param string|null               $probeUrl       page to send with $live (default https://<host>/)
     * @param bool                      $json           print the report as JSON instead of lines
     * @param bool                      $strict         exit 1 on warnings too
     *
     * @return int exit code ({@see ExitCode})
     */
    public function run(SymfonyStyle $io, callable $validateConfig, bool $live = false, string|array|null $host = null, ?string $probeUrl = null, bool $json = false, bool $strict = false): int
    {
        if (!$json) {
            $io->title('IndexNow check');
        }

        try {
            $config = $validateConfig();
        } catch (ConfigurationException $e) {
            if ($json) {
                $report = new CheckReport();
                $report->error('configuration: ' . $e->getMessage(), self::CONFIG_INVALID);
                $io->writeln(self::toJson($report, null));

                return ExitCode::FAILURE;
            }
            $io->writeln('  <fg=red>✘</> configuration: ' . $e->getMessage());
            $io->newLine();
            $io->error(\sprintf('IndexNow is disabled until the configuration is fixed (see %s).', $this->words->configLocation));

            return ExitCode::FAILURE;
        }

        $report = $this->report($live, self::hosts($host), $probeUrl !== null && $probeUrl !== '' ? $probeUrl : null);
        $failed = $report->hasErrors() || ($strict && $report->hasWarnings());
        if ($json) {
            $io->writeln(self::toJson($report, $config instanceof Config ? $config->environment : null));

            return $failed ? ExitCode::FAILURE : ExitCode::SUCCESS;
        }
        self::printReport($io, $report);
        $io->newLine();
        if ($report->hasErrors()) {
            $io->error('IndexNow is not ready. Fix the errors above.');

            return ExitCode::FAILURE;
        }
        if ($failed) {
            $io->warning('IndexNow is ready, but --strict treats the warnings above as failures.');

            return ExitCode::FAILURE;
        }
        $io->success('IndexNow is ready.');
        $io->text(\sprintf('Next: annotate a class with #[IndexNow(...)], or send one URL now: %s %s https://…', $this->words->cli, $this->words->submit));

        return ExitCode::SUCCESS;
    }

    public static function printReport(SymfonyStyle $io, CheckReport $report): void
    {
        foreach ($report->items() as $item) {
            $io->writeln(match ($item->level) {
                CheckLevel::Ok => '  <fg=green>✔</> ' . $item->message,
                CheckLevel::Warning => '  <fg=yellow>!</> ' . $item->message,
                CheckLevel::Error => '  <fg=red>✘</> ' . $item->message,
            });
        }
    }

    /**
     * The report as the JSON of docs/check.schema.json: `status` is the worst level of the report (`--strict` does not
     * change it), `environment` the configured one, `items` every line with its level, code, message and host.
     */
    public static function toJson(CheckReport $report, ?string $environment): string
    {
        return (string) json_encode([
            'status' => $report->status()->value,
            'environment' => $environment,
            'items' => array_map(static fn(CheckItem $item): array => ['level' => $item->level->value, 'code' => $item->code, 'message' => $item->message, 'host' => $item->host], $report->items()),
        ], self::JSON_FLAGS);
    }

    /**
     * One checker run per requested host, merged: the global lines (environment, adapter checks) are the same in every
     * run and are kept once, the host lines of every run are kept.
     *
     * @param list<string> $hosts
     */
    private function report(bool $live, array $hosts, ?string $probeUrl): CheckReport
    {
        if (\count($hosts) <= 1) {
            return $this->checker->run(liveProbe: $live, onlyHost: $hosts[0] ?? null, probeUrl: $probeUrl);
        }
        $merged = new CheckReport();
        $seen = [];
        foreach ($hosts as $host) {
            foreach ($this->checker->run(liveProbe: $live, onlyHost: $host, probeUrl: $probeUrl)->items() as $item) {
                $key = $item->level->value . "\0" . $item->code . "\0" . $item->host . "\0" . $item->message;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged->add($item);
            }
        }

        return $merged;
    }

    /**
     * @param string|list<string>|null $host
     *
     * @return list<string>
     */
    private static function hosts(string|array|null $host): array
    {
        $hosts = [];
        foreach (\is_array($host) ? $host : [$host] as $candidate) {
            if (\is_string($candidate) && trim($candidate) !== '') {
                $hosts[] = strtolower(trim($candidate));
            }
        }

        return array_values(array_unique($hosts));
    }
}
