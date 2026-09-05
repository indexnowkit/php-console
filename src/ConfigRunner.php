<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Version;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Body of `indexnow:config`: the effective configuration after defaults and environment variables, keys masked,
 * plus the adapter-only keys of the raw configuration (`messenger.*`, `queue.*`, `eloquent.*`, …) as given. The
 * artifact for a bug report and for an assistant that has to reason about a setup; `--json` for both.
 */
final class ConfigRunner
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(private readonly Vocabulary $words = new Vocabulary()) {}

    /**
     * @param callable(): Config   $buildConfig builds the Config from the raw adapter configuration; throws ConfigurationException when invalid
     * @param array<string, mixed> $raw         the adapter's raw configuration array (its own blocks included), for the adapter-only keys
     * @param bool                 $json        one JSON document instead of the dotted table
     *
     * @return int exit code ({@see ExitCode})
     */
    public function run(SymfonyStyle $io, callable $buildConfig, array $raw = [], bool $json = false): int
    {
        try {
            $config = $buildConfig();
        } catch (ConfigurationException $e) {
            if ($json) {
                $io->writeln((string) json_encode(['error' => $e->getMessage(), 'adapter' => self::adapterOnly($raw), 'core' => Version::get()], self::JSON_FLAGS));
            } else {
                $io->error(\sprintf('The configuration does not build: %s (see %s).', $e->getMessage(), $this->words->configLocation));
            }

            return ExitCode::FAILURE;
        }
        $effective = self::masked($config->toArray());
        $adapter = self::adapterOnly($raw);
        if ($json) {
            $io->writeln((string) json_encode(['config' => $effective, 'adapter' => $adapter, 'endpoints' => $config->endpoints, 'core' => Version::get()], self::JSON_FLAGS));

            return ExitCode::SUCCESS;
        }
        $io->title('IndexNow configuration');
        $io->text(\sprintf('Effective values (defaults and environment applied, keys masked); read from %s.', $this->words->configLocation));
        $rows = [];
        foreach (self::flatten($effective) as $key => $value) {
            $rows[] = [$key, self::render($value)];
        }
        $io->table(['Option', 'Value'], $rows);
        if ($adapter !== []) {
            $rows = [];
            foreach (self::flatten($adapter) as $key => $value) {
                $rows[] = [$key, self::render($value)];
            }
            $io->table(['Adapter option', 'Value'], $rows);
        }
        $io->text(\sprintf('Endpoints: %s. Core %s. Machine-readable: --json.', implode(', ', $config->endpoints), Version::get()));

        return ExitCode::SUCCESS;
    }

    /**
     * Keys masked to four characters: `key`, `previous_key`, and both inside every `hosts` entry.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function masked(array $config): array
    {
        foreach (['key', 'previous_key'] as $name) {
            if (\is_string($config[$name] ?? null)) {
                $config[$name] = KeyValidator::mask($config[$name]);
            }
        }
        $hosts = [];
        foreach (\is_array($config['hosts'] ?? null) ? $config['hosts'] : [] as $host => $entry) {
            if (\is_string($entry)) {
                $hosts[$host] = KeyValidator::mask($entry);
            } elseif (\is_array($entry)) {
                foreach (['key', 'previous_key'] as $name) {
                    if (\is_string($entry[$name] ?? null)) {
                        $entry[$name] = KeyValidator::mask($entry[$name]);
                    }
                }
                $hosts[$host] = $entry;
            }
        }
        $config['hosts'] = $hosts;

        return $config;
    }

    /**
     * The raw keys the core does not know, nested as given: the adapter's own blocks. `hosts` and every
     * `Config::OPTIONS` key are left out (they are in the effective configuration already).
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public static function adapterOnly(array $raw): array
    {
        $out = [];
        foreach ($raw as $name => $value) {
            $name = (string) $name;
            if ($name === 'hosts' || \in_array($name, Config::OPTIONS, true)) {
                continue;
            }
            if (\is_array($value) && !array_is_list($value)) {
                $block = [];
                foreach ($value as $sub => $subValue) {
                    if (!\in_array($name . '.' . (string) $sub, Config::OPTIONS, true)) {
                        $block[(string) $sub] = $subValue;
                    }
                }
                if ($block !== []) {
                    $out[$name] = $block;
                }

                continue;
            }
            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed> dotted key => scalar or list
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $path = $prefix . (string) $key;
            if (\is_array($value) && $value !== [] && !array_is_list($value) && $path !== 'hosts' && $path !== 'engine_aliases' && $path !== 'locale_hosts' && $path !== 'logging.levels') {
                /** @var array<string, mixed> $value */
                $out = [...$out, ...self::flatten($value, $path . '.')];
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    private static function render(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_array($value) => $value === [] ? '[]' : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
