<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use BackedEnum;
use Closure;
use IndexNowKit\Attribute\Param\Condition;
use IndexNowKit\Attribute\Param\Equals;
use IndexNowKit\Attribute\Param\FieldCondition;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\UrlRule;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Url\ResolvedUrl;
use IndexNowKit\Url\UrlNormalizerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Body of `indexnow:explain <class> <id>`: "why was this object not submitted?" Walks the decision path of one
 * object: rules -> event subscription -> `when` guard (with the values it read) -> resolved URLs -> normalization ->
 * host/key -> debounce. Sends nothing. `--json` prints the same walk as one document.
 */
final class ExplainRunner
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private readonly IndexNowKit $indexNow,
        private readonly SubjectLoaderInterface $subjects,
        private readonly Config $config,
        private readonly KeyProviderInterface $keys,
        private readonly DebounceStoreInterface $debounce,
        private readonly UrlNormalizerInterface $normalizer,
        private readonly Vocabulary $words = new Vocabulary(),
    ) {}

    /**
     * @param string $class class argument as typed (FQCN or short name)
     * @param string $event created | updated | deleted
     * @param bool   $json  print the explanation as JSON (`class`, `id`, `event`, `config`, `rules[]`, `delivery[]`, `submits`)
     *
     * @return int exit code ({@see ExitCode})
     */
    public function run(SymfonyStyle $io, string $class, string $id, string $event = 'updated', bool $json = false): int
    {
        $error = $json ? $io->getErrorStyle() : $io;
        try {
            $class = $this->subjects->resolveClass($class);
        } catch (InvalidArgumentException $e) {
            $error->error($e->getMessage());

            return ExitCode::INVALID;
        }
        $eventValue = Event::tryFrom($event);
        if ($eventValue === null) {
            $error->error('--event must be created, updated or deleted.');

            return ExitCode::INVALID;
        }
        [$found] = $this->subjects->byIds($class, [$id], $eventValue);
        if ($found === []) {
            $error->error(\sprintf('%s with id "%s" not found.', $class, $id));

            return ExitCode::INVALID;
        }
        $object = $found[0];
        $rules = $this->indexNow->changes()->rulesOf($object);
        $explained = [];
        foreach ($rules as $rule) {
            $explained[] = $this->explainRule($object, $rule, $eventValue);
        }
        $urls = array_values(array_unique(array_merge([], ...array_column($explained, 'urls'))));
        $delivery = array_map($this->explainUrl(...), $urls);
        $submits = array_values(array_filter($delivery, static fn(array $d): bool => $d['submits']));

        if ($json) {
            $io->writeln((string) json_encode([
                'class' => $class,
                'id' => $id,
                'event' => $eventValue->value,
                'config' => ['enabled' => $this->config->enabled, 'dry_run' => $this->config->dryRun, 'dispatch' => $this->config->dispatch, 'debounce' => $this->config->debouncePerUrl],
                'rules' => array_map(static fn(array $r): array => array_diff_key($r, ['urls' => true]), $explained),
                'delivery' => $delivery,
                'submits' => array_column($submits, 'normalized'),
            ], self::JSON_FLAGS));

            return $rules->isEmpty() ? ExitCode::FAILURE : ExitCode::SUCCESS;
        }

        $io->title(\sprintf('IndexNow explain: %s #%s (%s)', $class, $id, $eventValue->value));
        $io->definitionList(
            ['enabled' => $this->config->enabled ? 'yes' : 'NO (enabled: false): nothing is sent'],
            ['dry_run' => $this->config->dryRun ? 'yes: requests are logged, not sent' : 'no'],
            ['dispatch' => $this->config->dispatch],
            ['debounce' => $this->config->debouncePerUrl . 's'],
        );
        if ($rules->isEmpty()) {
            $io->writeln('  <fg=red>✘</> no #[IndexNow] rule on ' . $class . ' (or the attribute is invalid: see the log)');

            return ExitCode::FAILURE;
        }
        foreach ($explained as $rule) {
            $this->printRule($io, $rule, $eventValue);
        }
        if ($urls === []) {
            $io->newLine();
            $io->warning('No URL would be submitted for this event.');

            return ExitCode::SUCCESS;
        }
        $io->section('Delivery');
        foreach ($delivery as $line) {
            $io->writeln('  ' . $line['line']);
        }
        $io->newLine();
        // A plain line, not a note block: the hint is a command to copy, wrapping would break it.
        $io->text(\sprintf('<comment>Nothing was sent.</comment> Submit with: %s %s %s %s', $this->words->cli, $this->words->submitSubjects, $class, $id));

        return ExitCode::SUCCESS;
    }

    /**
     * One rule of the object: subscription, every `when` condition with the value it read and the outcome, the field
     * filter, the resolved URLs (or why none).
     *
     * @return array{name: string, source: string, route: ?string, events: list<string>, subscribed: bool, when: list<array{condition: string, reads: bool, value: mixed, holds: ?bool, hint: ?string, error: ?string}>, applies: ?bool, fields: list<string>, urls: list<string>, resolved: list<array{url: string, locale: ?string, rule: string}>}
     */
    private function explainRule(object $object, UrlRule $rule, Event $event): array
    {
        $when = [];
        $applies = true;
        foreach ($rule->when as $condition) {
            $item = $this->explainCondition($object, $condition);
            $when[] = $item;
            if ($item['holds'] !== true) {
                $applies = $item['holds'] === null && $applies !== false ? null : false;
            }
        }
        $resolved = $applies === false || $applies === null ? [] : $this->indexNow->resolver()->resolveRule($object, $rule, $event);

        return [
            'name' => $rule->name,
            'source' => $rule->source->value,
            'route' => $rule->route,
            'events' => array_map(static fn(Event $e): string => $e->value, $rule->events),
            'subscribed' => $rule->listensTo($event),
            'when' => $when,
            'applies' => $rule->when === [] ? true : $applies,
            'fields' => $rule->fields,
            'urls' => array_map(static fn(ResolvedUrl $r): string => $r->url, $resolved),
            'resolved' => array_map(static fn(ResolvedUrl $r): array => ['url' => $r->url, 'locale' => $r->locale, 'rule' => $r->rule], $resolved),
        ];
    }

    /**
     * One `when` condition against the object: what it is, what it read, whether it holds, and the hint when a
     * string accessor is truthy only because a non-empty string is (`status ("draft") -> true`).
     *
     * @return array{condition: string, reads: bool, value: mixed, holds: ?bool, hint: ?string, error: ?string}
     */
    private function explainCondition(object $object, string|Condition|Closure $condition): array
    {
        $described = self::describeCondition($condition);
        $reads = \is_string($condition) || $condition instanceof FieldCondition;
        try {
            $value = match (true) {
                \is_string($condition) => ParamExtractor::read($object, $condition),
                $condition instanceof FieldCondition => ParamExtractor::read($object, $condition->field()),
                default => null,
            };
            $holds = ParamExtractor::condition($object, $condition);
        } catch (Throwable $e) {
            return ['condition' => $described, 'reads' => $reads, 'value' => null, 'holds' => null, 'hint' => null, 'error' => $e->getMessage()];
        }
        $hint = null;
        if (\is_string($condition) && \is_string($value) && $value !== '' && !\in_array(strtolower($value), ['1', 'true'], true)) {
            $hint = \sprintf('a non-empty string is truthy; use new Equals(\'%s\', %s)', $condition, self::literal($value));
        }
        $rendered = $value instanceof BackedEnum ? $value->value : (\is_scalar($value) || $value === null ? $value : get_debug_type($value));

        return ['condition' => $described, 'reads' => $reads, 'value' => $rendered, 'holds' => $holds, 'hint' => $hint, 'error' => null];
    }

    /**
     * @param array{name: string, source: string, route: ?string, events: list<string>, subscribed: bool, when: list<array{condition: string, reads: bool, value: mixed, holds: ?bool, hint: ?string, error: ?string}>, applies: ?bool, fields: list<string>, urls: list<string>, resolved: list<array{url: string, locale: ?string, rule: string}>} $rule
     */
    private function printRule(SymfonyStyle $io, array $rule, Event $event): void
    {
        $io->section(\sprintf('Rule "%s" (%s%s)', $rule['name'], $rule['source'], $rule['route'] !== null ? ' ' . $rule['route'] : ''));
        $io->writeln(\sprintf('  events: %s -> %s', implode(', ', $rule['events']), $rule['subscribed'] ? '<fg=green>subscribed</>' : '<fg=yellow>not subscribed to ' . $event->value . '</>'));
        foreach ($rule['when'] as $item) {
            $shown = $item['condition'] . ($item['reads'] ? ' (' . self::literal($item['value']) . ')' : '');
            if ($item['error'] !== null) {
                $io->writeln(\sprintf('  when: %s -> <fg=red>error: %s</>', $item['condition'], $item['error']));

                continue;
            }
            $io->writeln(\sprintf('  when: %s -> %s%s', $shown, $item['holds'] === true ? '<fg=green>true</>' : '<fg=yellow>false (page not public, nothing submitted)</>', $item['hint'] !== null ? ' — ' . $item['hint'] : ''));
        }
        if ($rule['fields'] !== []) {
            $io->writeln(\sprintf('  fields: updates count only when one of [%s] changed', implode(', ', $rule['fields'])));
        }
        if ($rule['resolved'] === []) {
            $io->writeln('  urls: <fg=yellow>none</> (see above, or the indexnow log channel for resolver errors)');

            return;
        }
        foreach ($rule['resolved'] as $item) {
            $io->writeln(\sprintf('  url: <fg=green>%s</>%s%s', $item['url'], $item['locale'] !== null ? ' [' . $item['locale'] . ']' : '', $item['rule'] !== $rule['name'] ? ' via ' . $item['rule'] : ''));
        }
    }

    private static function describeCondition(string|Condition|Closure $condition): string
    {
        return match (true) {
            \is_string($condition) => $condition,
            $condition instanceof Equals => \sprintf('%s == %s', $condition->path, self::literal($condition->value instanceof BackedEnum ? $condition->value->value : $condition->value)),
            $condition instanceof FieldCondition => \sprintf('%s(%s)', self::shortClass($condition), $condition->field()),
            $condition instanceof Closure => 'closure',
            default => self::shortClass($condition),
        };
    }

    /**
     * One URL of the delivery: normalized form, host, key, debounce; and whether it would be submitted.
     *
     * @return array{url: string, normalized: ?string, host: ?string, key: ?string, debounced: ?bool, submits: bool, line: string}
     */
    private function explainUrl(string $url): array
    {
        try {
            $normalized = $this->normalizer->normalize($url);
        } catch (InvalidUrlException $e) {
            return ['url' => $url, 'normalized' => null, 'host' => null, 'key' => null, 'debounced' => null, 'submits' => false, 'line' => \sprintf('%s -> <fg=red>dropped: %s</>', $url, $e->getMessage())];
        }
        $host = $this->normalizer->hostOf($normalized);
        $key = $this->keys->keyFor($host);
        $line = $normalized;
        if ($normalized !== $url) {
            $line .= ' (normalized from ' . $url . ')';
        }
        if ($key === null) {
            return ['url' => $url, 'normalized' => $normalized, 'host' => $host, 'key' => null, 'debounced' => null, 'submits' => false, 'line' => $line . \sprintf(' -> <fg=red>skipped: no key for host %s</> (add it to "hosts" or set base_url)', $host)];
        }
        $keyFile = $this->keys->keyLocationFor($host) ?? \sprintf('https://%s/%s.txt', $host, $key);
        $line .= \sprintf(' -> host %s, key %s (%s)', $host, KeyValidator::mask($key), str_replace($key, KeyValidator::mask($key), $keyFile));
        $debounced = null;
        if ($this->config->debouncePerUrl > 0) {
            try {
                $debounced = $this->debounce->filterRecent([$normalized], $this->config->debouncePerUrl) !== [];
                $line .= $debounced ? \sprintf(', <fg=yellow>debounced</> (sent within the last %ds; %s --force bypasses)', $this->config->debouncePerUrl, $this->words->submit) : ', not debounced';
            } catch (Throwable $e) {
                $line .= ', debounce store unavailable (' . $e->getMessage() . '), would submit';
            }
        }

        return ['url' => $url, 'normalized' => $normalized, 'host' => $host, 'key' => KeyValidator::mask($key), 'debounced' => $debounced, 'submits' => $debounced !== true, 'line' => $line];
    }

    private static function literal(mixed $value): string
    {
        return (string) json_encode($value instanceof BackedEnum ? $value->value : $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function shortClass(object $object): string
    {
        $class = $object::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
