<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Unit;

use IndexNowKit\Adapter\SubmitterFactory;
use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Config;
use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\ConfigRunner;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Console\ExplainRunner;
use IndexNowKit\Console\KeyGenerateRunner;
use IndexNowKit\Console\ResultRenderer;
use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Console\SubmitRunner;
use IndexNowKit\Console\SubmitSubjectsOptions;
use IndexNowKit\Console\SubmitSubjectsRunner;
use IndexNowKit\Console\Tests\Support\Factory;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\Http\Response;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Submission\ResultSummary;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Throttle\NullThrottle;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\UrlNormalizer;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

#[IndexNow(url: 'url', when: 'status')]
#[IndexNow(urls: ['/articles'], when: new \IndexNowKit\Attribute\Param\Equals('status', 'published'), name: 'index')]
final class ConsoleArticle
{
    public function __construct(public int $id, public string $status = 'draft') {}

    public function url(): string
    {
        return '/articles/' . $this->id;
    }
}

#[IndexNow(url: 'url', when: 'published')]
final class ConsolePost
{
    public function __construct(public int $id, public string $slug, public bool $published = true) {}

    public function url(): string
    {
        return '/posts/' . $this->slug;
    }
}

final class ConsoleUntracked
{
    public function __construct(public int $id) {}
}

/**
 * In-memory stand-in for an ORM loader: objects by class and id.
 */
final class ArraySubjectLoader implements SubjectLoaderInterface
{
    /** @var list<Event> */
    public array $events = [];

    /**
     * @param array<class-string, list<object>> $objects
     */
    public function __construct(private readonly array $objects) {}

    public function resolveClass(string $class): string
    {
        $class = ltrim($class, '\\');
        if (!isset($this->objects[$class])) {
            throw new InvalidArgumentException(\sprintf('Class "%s" not found.', $class));
        }

        return $class;
    }

    public function byIds(string $class, array $ids, Event $event): array
    {
        $this->events[] = $event;
        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $match = array_values(array_filter($this->objects[$class] ?? [], static fn(object $o): bool => (string) $o->id === $id));
            if ($match === []) {
                $missing[] = $id;
            } else {
                $found[] = $match[0];
            }
        }

        return [$found, $missing];
    }

    public function all(string $class, int $limit, Event $event): iterable
    {
        return \array_slice($this->objects[$class] ?? [], 0, $limit);
    }
}

final class RunnersTest extends TestCase
{
    private FakeTransport $transport;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->output = new BufferedOutput();
    }

    private function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), $this->output);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function kit(array $overrides = []): IndexNowKit
    {
        return IndexNowKit::create(Factory::config($overrides), $this->transport, resolver: new AttributeUrlResolver(new AttributeReader()));
    }

    private function submitters(IndexNowKit $kit): SubmitterFactory
    {
        return new SubmitterFactory($this->transport, $kit->keys, $kit->config, new MemoryDebounceStore(), new NullThrottle(), new UrlNormalizer($kit->config->baseUrl, $kit->config->maxUrlLength));
    }

    private function loader(): ArraySubjectLoader
    {
        return new ArraySubjectLoader([ConsolePost::class => [new ConsolePost(1, 'one'), new ConsolePost(2, 'two'), new ConsolePost(3, 'draft', published: false)], ConsoleUntracked::class => [new ConsoleUntracked(7)], ConsoleArticle::class => [new ConsoleArticle(1)]]);
    }

    /**
     * @return list<string>
     */
    private function sentUrls(): array
    {
        $urls = [];
        foreach ($this->transport->posts as $post) {
            $urls = [...$urls, ...$post['body']['urlList']];
        }

        return $urls;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function json(): array
    {
        $decoded = json_decode($this->output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return array_values($decoded);
    }

    #[TestDox('submit: a table with one row per engine/host, exit 0; --json prints the results; a failing engine gives exit 1')]
    public function testSubmit(): void
    {
        $kit = $this->kit();
        $runner = new SubmitRunner($kit, $this->submitters($kit));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ['/a', 'https://www.example.com/b'], false, false, false));
        self::assertMatchesRegularExpression('/\bapi\s+www\.example\.com\s+2\s+ok\b/', $this->output->fetch());
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $this->sentUrls());

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ['/c'], false, false, true));
        $rows = $this->json();
        self::assertSame('ok', $rows[0]['status']);
        self::assertSame(['https://www.example.com/c'], $rows[0]['urls']);

        $this->transport->willRespond(new Response(403));
        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), ['/d'], false, false, false));
    }

    #[TestDox('submit --dry-run sends nothing and explains the skipped rows; --force bypasses the debounce store')]
    public function testSubmitDryRunAndForce(): void
    {
        $kit = $this->kit(['debounce' => ['per_url' => 600]]);
        $runner = new SubmitRunner($kit, $this->submitters($kit));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ['/a'], false, true, false));
        $display = $this->output->fetch();
        self::assertStringContainsString('dry_run', $display);
        self::assertStringContainsString('Nothing was sent', $display);
        self::assertSame([], $this->transport->posts);

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ['/a'], false, false, true));
        self::assertSame('ok', $this->json()[0]['status']);
        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ['/a'], false, false, true));
        self::assertSame('debounced', $this->json()[0]['reason'], 'the second submission within debounce.per_url is skipped');
        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ['/a'], true, false, true));
        self::assertSame('ok', $this->json()[0]['status'], '--force submits again');
        self::assertCount(2, $this->transport->posts);
    }

    #[TestDox('submit with no URL prints a warning and exit 0')]
    public function testSubmitNothing(): void
    {
        $kit = $this->kit();

        self::assertSame(ExitCode::SUCCESS, (new SubmitRunner($kit, $this->submitters($kit)))->run($this->io(), [], false, false, false));
        self::assertStringContainsString('Nothing submitted', $this->output->fetch());
    }

    #[TestDox('submit-subjects: resolves every object of the class through its rules (drafts skipped), counts with the adapter words')]
    public function testSubmitSubjects(): void
    {
        $kit = $this->kit();
        $runner = new SubmitSubjectsRunner($kit, $this->loader(), $this->submitters($kit), words: new Vocabulary('model', 'models', 'php artisan', 'indexnow:submit-model'));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class)));
        self::assertStringContainsString('3 models -> 2 URL(s)', $this->output->fetch());
        self::assertSame(['https://www.example.com/posts/one', 'https://www.example.com/posts/two'], $this->sentUrls());
    }

    #[TestDox('submit-subjects: ids select objects, --explain lists rule and URL as a table or JSON and sends nothing')]
    public function testSubmitSubjectsExplain(): void
    {
        $kit = $this->kit();
        $runner = new SubmitSubjectsRunner($kit, $this->loader(), $this->submitters($kit));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, ['1'], explain: true)));
        $display = $this->output->fetch();
        self::assertStringContainsString('1 object -> 1 URL(s)', $display);
        self::assertStringContainsString('/posts/one', $display);
        self::assertSame([], $this->transport->posts, '--explain sends nothing');

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, ['2'], explain: true, json: true)));
        $rows = $this->json();
        self::assertSame('/posts/two', $rows[0]['url'], 'as resolved by the rule; normalization happens on submit');
        self::assertSame(ConsolePost::class, $rows[0]['class']);

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, ['3'], explain: true)));
        self::assertStringContainsString('No URL resolved', $this->output->fetch());
    }

    #[TestDox('submit-subjects: unknown class, bad event and missing ids are INVALID; a partial miss still submits the rest')]
    public function testSubmitSubjectsInvalidInput(): void
    {
        $kit = $this->kit();
        $loader = $this->loader();
        $runner = new SubmitSubjectsRunner($kit, $loader, $this->submitters($kit));

        self::assertSame(ExitCode::INVALID, $runner->run($this->io(), new SubmitSubjectsOptions('Nope')));
        self::assertStringContainsString('not found', $this->output->fetch());
        self::assertSame(ExitCode::INVALID, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, event: 'moved')));
        self::assertStringContainsString('--event must be', $this->output->fetch());
        self::assertSame(ExitCode::INVALID, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, ['999'])));
        self::assertStringContainsString('id(s) not found: 999', $this->output->fetch());

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, ['1', '999'], event: 'deleted')));
        self::assertStringContainsString('id(s) not found: 999', $this->output->fetch());
        self::assertSame(['https://www.example.com/posts/one'], $this->sentUrls());
        self::assertSame(Event::Deleted, end($loader->events), 'the event reaches the loader (soft deletes)');
    }

    #[TestDox('submit-subjects: --limit reached prints a warning; no URL prints the explain hint with the adapter CLI; --dry-run --json reports dry_run')]
    public function testSubmitSubjectsLimitAndHints(): void
    {
        $kit = $this->kit();
        $runner = new SubmitSubjectsRunner($kit, $this->loader(), $this->submitters($kit), words: new Vocabulary(cli: 'bin/console'));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, limit: 2)));
        self::assertStringContainsString('--limit=2 reached', $this->output->fetch());

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsoleUntracked::class)));
        $display = $this->output->fetch();
        self::assertStringContainsString('No URL resolved', $display);
        self::assertStringContainsString('bin/console indexnow:explain', $display);
        self::assertStringContainsString('php yii indexnow/explain', (function () use ($kit): string {
            $runner = new SubmitSubjectsRunner($kit, $this->loader(), $this->submitters($kit), words: new Vocabulary(cli: 'php yii', explain: 'indexnow/explain'));
            $runner->run($this->io(), new SubmitSubjectsOptions(ConsoleUntracked::class));

            return $this->output->fetch();
        })());

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, ['1'], dryRun: true, json: true)));
        self::assertSame('dry_run', $this->json()[0]['reason']);
    }

    #[TestDox('explain: rule, when, URL, masked key and the submit hint; nothing is sent')]
    public function testExplain(): void
    {
        $kit = $this->kit(['debounce' => ['per_url' => 60]]);
        $runner = new ExplainRunner($kit, $this->loader(), $kit->config, $kit->keys, new MemoryDebounceStore(), new UrlNormalizer($kit->config->baseUrl), new Vocabulary('entity', 'entities', 'bin/console', 'indexnow:submit-entity'));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ConsolePost::class, '1'));
        $display = $this->output->fetch();
        self::assertStringContainsString('IndexNow explain: ' . ConsolePost::class . ' #1 (updated)', $display);
        self::assertStringContainsString('when: published (true) -> true', $display, 'the value the condition read is shown');
        self::assertStringContainsString('url: /posts/one', $display);
        self::assertStringContainsString('https://www.example.com/posts/one (normalized from /posts/one)', $display);
        self::assertStringContainsString('host www.example.com, key ' . KeyValidator::mask(Factory::KEY), $display);
        self::assertStringNotContainsString(Factory::KEY, $display, 'the key is never printed in full');
        self::assertStringContainsString('not debounced', $display);
        self::assertStringContainsString('bin/console indexnow:submit-entity ' . ConsolePost::class . ' 1', $display);
        self::assertSame([], $this->transport->posts);

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ConsolePost::class, '3'));
        $display = $this->output->fetch();
        self::assertStringContainsString('when: published (false) -> false', $display);
        self::assertStringContainsString('No URL would be submitted', $display);
    }

    #[TestDox('explain: a truthy status string gets the Equals hint, an Equals condition shows the comparison and the value; --json is the same walk as one document')]
    public function testExplainConditionValuesAndJson(): void
    {
        $kit = $this->kit();
        $runner = new ExplainRunner($kit, $this->loader(), $kit->config, $kit->keys, new MemoryDebounceStore(), new UrlNormalizer($kit->config->baseUrl));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ConsoleArticle::class, '1'));
        $display = $this->output->fetch();
        self::assertStringContainsString('when: status ("draft") -> true — a non-empty string is truthy; use new Equals(\'status\', "draft")', $display);
        self::assertStringContainsString('when: status == "published" ("draft") -> false (page not public, nothing submitted)', $display);
        self::assertStringContainsString('url: /articles/1', $display, 'the truthy rule still resolves');
        self::assertStringNotContainsString('url: /articles' . "\n", $display, 'the Equals rule yields nothing');

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ConsoleArticle::class, '1', json: true));
        $raw = $this->output->fetch();
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(ConsoleArticle::class, $decoded['class']);
        self::assertSame('updated', $decoded['event']);
        self::assertSame(['enabled' => true, 'dry_run' => false, 'dispatch' => 'sync', 'debounce' => 0], $decoded['config']);
        $rules = $decoded['rules'];
        self::assertIsArray($rules);
        self::assertCount(2, $rules);
        self::assertSame('url:url', $rules[0]['name']);
        self::assertSame([['condition' => 'status', 'reads' => true, 'value' => 'draft', 'holds' => true, 'hint' => 'a non-empty string is truthy; use new Equals(\'status\', "draft")', 'error' => null]], $rules[0]['when']);
        self::assertTrue($rules[0]['applies']);
        self::assertSame([['url' => '/articles/1', 'locale' => null, 'rule' => 'url:url']], $rules[0]['resolved']);
        self::assertSame('index', $rules[1]['name']);
        self::assertSame('status == "published"', $rules[1]['when'][0]['condition']);
        self::assertFalse($rules[1]['when'][0]['holds']);
        self::assertFalse($rules[1]['applies']);
        self::assertSame([], $rules[1]['resolved']);
        self::assertSame(['https://www.example.com/articles/1'], $decoded['submits']);
        self::assertSame('https://www.example.com/articles/1', $decoded['delivery'][0]['normalized']);
        self::assertSame(KeyValidator::mask(Factory::KEY), $decoded['delivery'][0]['key']);
        self::assertStringNotContainsString(Factory::KEY, $raw);
        self::assertSame([], $this->transport->posts);

        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), ConsoleUntracked::class, '7', json: true));
        $decoded = json_decode($this->output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame([], $decoded['rules'], 'no rules: an empty document and exit 1, like the text form');
    }

    #[TestDox('explain: an object without rules is a FAILURE; unknown class, unknown id and a bad event are INVALID')]
    public function testExplainInvalidInput(): void
    {
        $kit = $this->kit();
        $runner = new ExplainRunner($kit, $this->loader(), $kit->config, $kit->keys, new MemoryDebounceStore(), new UrlNormalizer($kit->config->baseUrl));

        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), ConsoleUntracked::class, '7'));
        self::assertStringContainsString('no #[IndexNow] rule', $this->output->fetch());
        self::assertSame(ExitCode::INVALID, $runner->run($this->io(), 'Nope', '1'));
        self::assertSame(ExitCode::INVALID, $runner->run($this->io(), ConsolePost::class, '999'));
        self::assertStringContainsString('not found', $this->output->fetch());
        self::assertSame(ExitCode::INVALID, $runner->run($this->io(), ConsolePost::class, '1', 'moved'));
    }

    #[TestDox('key:generate prints the key with the env hint; --write-env adds INDEXNOW_KEY once, --force rotates it; an unwritable file is a FAILURE')]
    public function testKeyGenerate(): void
    {
        $runner = new KeyGenerateRunner(new Vocabulary(cli: 'php artisan', keyFileServedBy: 'by the package route'));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io()));
        $display = $this->output->fetch();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/m', $display);
        self::assertMatchesRegularExpression('/INDEXNOW_KEY=[a-f0-9]{32}/', $display);
        self::assertStringContainsString('php artisan indexnow:check', $display);

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), 16, false));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/m', $this->output->fetch());

        $file = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($file);
        file_put_contents($file, "APP_NAME=x");
        try {
            self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), envFile: $file));
            $first = (string) file_get_contents($file);
            self::assertMatchesRegularExpression('/^APP_NAME=x\nINDEXNOW_KEY=[a-f0-9]{32}\n$/', $first);
            self::assertStringContainsString('by the package route', $this->output->fetch());

            self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), envFile: $file));
            self::assertStringContainsString('nothing to do', $this->output->fetch());
            self::assertSame($first, file_get_contents($file));

            preg_match('/^INDEXNOW_KEY=(.+)$/m', $first, $m);
            $firstKey = $m[1];
            self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), envFile: $file, force: true));
            $display = $this->output->fetch();
            self::assertStringContainsString('Rotating the key', $display);
            self::assertStringContainsString('is kept as INDEXNOW_PREVIOUS_KEY', $display);
            self::assertStringNotContainsString($firstKey, $display, 'the old key is masked');
            $second = (string) file_get_contents($file);
            self::assertNotSame($first, $second);
            self::assertMatchesRegularExpression('/^APP_NAME=x\nINDEXNOW_KEY=[a-f0-9]{32}\nINDEXNOW_PREVIOUS_KEY=' . $firstKey . '\n$/', $second, 'the old key moves to INDEXNOW_PREVIOUS_KEY, right after the key');
            self::assertSame(1, preg_match_all('/^INDEXNOW_KEY=/m', $second));

            self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), envFile: $file, force: true), 'a second rotation while the previous key is still set is refused');
            self::assertStringContainsString('INDEXNOW_PREVIOUS_KEY is still set from an earlier rotation', $this->output->fetch());
            self::assertSame($second, file_get_contents($file), 'nothing written');

            self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), envFile: $file, force: true, yes: true));
            $this->output->fetch();
            $third = (string) file_get_contents($file);
            preg_match('/^INDEXNOW_KEY=(.+)$/m', $second, $m);
            self::assertMatchesRegularExpression('/^APP_NAME=x\nINDEXNOW_KEY=[a-f0-9]{32}\nINDEXNOW_PREVIOUS_KEY=' . $m[1] . '\n$/', $third, '--yes overwrites the previous key with the key just replaced');
            self::assertStringNotContainsString($firstKey, $third, 'the key of the first rotation is gone');

            self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), envFile: $file, force: true, noPrevious: true));
            self::assertMatchesRegularExpression('/^APP_NAME=x\nINDEXNOW_KEY=[a-f0-9]{32}\n$/', (string) file_get_contents($file), '--no-previous drops INDEXNOW_PREVIOUS_KEY');
            self::assertStringNotContainsString('is kept as', $this->output->fetch());

            file_put_contents($file, "INDEXNOW_PREVIOUS_KEY=\nINDEXNOW_KEY=\"" . $firstKey . "\"\n");
            self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), envFile: $file, force: true), 'an empty INDEXNOW_PREVIOUS_KEY does not count as set');
            self::assertMatchesRegularExpression('/^INDEXNOW_PREVIOUS_KEY=' . $firstKey . '\nINDEXNOW_KEY=[a-f0-9]{32}\n$/', (string) file_get_contents($file), 'the existing line is reused, quotes are stripped from the old value');
        } finally {
            @unlink($file);
        }

        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), envFile: '/nonexistent/indexnow/.env'));
        self::assertStringContainsString('Cannot write', $this->output->fetch());
    }

    #[TestDox('check: prints the report lines and the adapter checks, exit 0 when ready, exit 1 on errors or an invalid configuration')]
    public function testCheck(): void
    {
        $config = Factory::config();
        $check = new class implements CheckInterface {
            public function check(CheckReport $report): void
            {
                $report->ok('eloquent: observers active');
            }
        };
        $checker = new Checker($config, $this->kit()->keys, $this->transport, [$check]);
        $runner = new CheckRunner($checker, new Vocabulary(configLocation: 'config/indexnow.php'));
        $valid = static fn(): Config => $config;

        $this->transport->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), $valid));
        $display = $this->output->fetch();
        self::assertStringContainsString('key file OK', $display);
        self::assertStringContainsString('eloquent: observers active', $display);
        self::assertStringContainsString('IndexNow is ready.', $display);

        $this->transport->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(404));
        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), $valid, false, 'www.example.com'));
        $display = $this->output->fetch();
        self::assertStringContainsString('HTTP 404', $display);
        self::assertStringContainsString('IndexNow is not ready', $display);

        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), static function (): never {
            throw new ConfigurationException('key "shor*" is invalid');
        }));
        $display = $this->output->fetch();
        self::assertStringContainsString('configuration: key "shor*" is invalid', $display);
        self::assertStringContainsString('config/indexnow.php', $display);
    }

    #[TestDox('check --json: the report of docs/check.schema.json with status, environment and coded items; --strict fails on warnings without changing the status; --host repeated merges the host lines')]
    public function testCheckJsonStrictAndHosts(): void
    {
        $config = Factory::config(['environment' => 'prod', 'hosts' => ['b.example.com' => Factory::KEY]]);
        $checker = new Checker($config, \IndexNowKit\Key\StaticKeyProvider::fromConfig($config), $this->transport, [new \IndexNowKit\Check\StaticCheck(\IndexNowKit\Check\CheckLevel::Ok, 'cdn: purged', 'cdn.purged')]);
        $runner = new CheckRunner($checker);
        $valid = static fn(): Config => $config;
        $this->transport->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        $this->transport->onGet('https://b.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        $schema = json_decode((string) file_get_contents(__DIR__ . '/../../docs/check.schema.json'));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), $valid, json: true), 'the hosts map without strict_hosts is a warning, not an error');
        $raw = $this->output->fetch();
        self::assertStringNotContainsString('IndexNow check', $raw, 'no title, no closing line: stdout is the JSON document');
        $decoded = json_decode($raw);
        $validator = new \JsonSchema\Validator();
        $validator->validate($decoded, $schema);
        self::assertTrue($validator->isValid(), json_encode($validator->getErrors(), JSON_PRETTY_PRINT) . "\n" . $raw);
        $report = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame('warning', $report['status']);
        self::assertSame('prod', $report['environment']);
        $items = $report['items'];
        self::assertIsArray($items);
        $codes = array_column($items, 'code');
        self::assertContains('config.strict_hosts', $codes);
        self::assertContains('cdn.purged', $codes);
        self::assertSame(['b.example.com', 'www.example.com'], array_column(array_filter($items, static fn(array $i): bool => $i['code'] === 'key_file.status'), 'host'));
        self::assertNull($items[0]['host']);
        self::assertSame([], array_filter($items, static fn(array $i): bool => $i['code'] === null), 'every line has a code');

        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), $valid, json: true, strict: true), '--strict: warnings fail');
        $report = json_decode($this->output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame('warning', $report['status'], '--strict changes the exit code, not the status');

        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), $valid, strict: true));
        self::assertStringContainsString('--strict treats the warnings above', $this->output->fetch());

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), $valid, host: ['www.example.com', 'B.example.com', 'www.example.com'], json: true));
        $report = json_decode($this->output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        $items = $report['items'];
        self::assertIsArray($items);
        self::assertSame(['www.example.com', 'b.example.com'], array_column(array_filter($items, static fn(array $i): bool => $i['code'] === 'key_file.status'), 'host'), 'one key file line per requested host, in the requested order, de-duplicated');
        self::assertCount(1, array_filter($items, static fn(array $i): bool => $i['code'] === 'cdn.purged'), 'the global lines are kept once');
        self::assertCount(1, array_filter($items, static fn(array $i): bool => $i['code'] === 'environment.name'));
        self::assertCount(16, $this->transport->gets, 'two runs (one per host) fetch one key file and one robots.txt each, after the three full runs above');

        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), static function (): never {
            throw new ConfigurationException('key "shor*" is invalid');
        }, json: true));
        $report = json_decode($this->output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame('error', $report['status']);
        self::assertNull($report['environment']);
        self::assertSame([['level' => 'error', 'code' => CheckRunner::CONFIG_INVALID, 'message' => 'configuration: key "shor*" is invalid', 'host' => null]], $report['items']);
        $validator->reset();
        $invalid = json_decode(json_encode($report, JSON_THROW_ON_ERROR));
        $validator->validate($invalid, $schema);
        self::assertTrue($validator->isValid());
    }

    #[TestDox('config: the effective configuration with masked keys and the adapter-only keys, as a table or as JSON; an invalid configuration is a FAILURE')]
    public function testConfig(): void
    {
        $raw = ['key' => Factory::KEY, 'hosts' => ['b.example.com' => Factory::KEY, 'c.example.com' => ['key' => Factory::KEY, 'previous_key' => 'oldkey1234567890']], 'base_url' => 'https://www.example.com', 'debounce' => ['per_url' => 30], 'queue' => ['connection' => 'redis'], 'eloquent' => ['enabled' => false], 'messenger' => ['transport' => 'async']];
        $runner = new ConfigRunner(new Vocabulary(configLocation: 'config/indexnow.php'));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), static fn(): Config => Config::fromArray($raw), $raw, true));
        $raw_output = $this->output->fetch();
        $decoded = json_decode($raw_output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertStringNotContainsString(Factory::KEY, $raw_output, 'keys are masked everywhere');
        self::assertStringNotContainsString('oldkey1234567890', $raw_output);
        self::assertSame(KeyValidator::mask(Factory::KEY), $decoded['config']['key']);
        self::assertSame(KeyValidator::mask(Factory::KEY), $decoded['config']['hosts']['b.example.com']);
        self::assertSame(KeyValidator::mask('oldkey1234567890'), $decoded['config']['hosts']['c.example.com']['previous_key']);
        self::assertSame(30, $decoded['config']['debounce']['per_url']);
        self::assertSame(600, Config::DEFAULT_DEBOUNCE_PER_URL, 'sanity');
        self::assertSame(10000, $decoded['config']['batch']['max_urls'], 'defaults are filled in');
        self::assertTrue($decoded['config']['normalizer']['strip_tracking_params']);
        self::assertSame(['queue' => ['connection' => 'redis'], 'eloquent' => ['enabled' => false], 'messenger' => ['transport' => 'async']], $decoded['adapter'], 'the keys the core does not know, as given');
        self::assertSame(['https://api.indexnow.org/indexnow'], $decoded['endpoints']);
        self::assertIsString($decoded['core']);

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), static fn(): Config => Config::fromArray($raw), $raw));
        $display = $this->output->fetch();
        self::assertStringContainsString('IndexNow configuration', $display);
        self::assertStringContainsString('config/indexnow.php', $display);
        self::assertStringContainsString('debounce.per_url', $display);
        self::assertStringContainsString('queue.connection', $display);
        self::assertStringNotContainsString(Factory::KEY, $display);

        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), static function (): never {
            throw new ConfigurationException('key "shor*" is invalid');
        }, $raw, true));
        $decoded = json_decode($this->output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('key "shor*" is invalid', $decoded['error']);
        self::assertSame(['queue' => ['connection' => 'redis'], 'eloquent' => ['enabled' => false], 'messenger' => ['transport' => 'async']], $decoded['adapter'], 'the adapter keys still help the bug report');
    }

    #[TestDox('ResultRenderer: an empty summary is a warning, an all-skipped summary carries the reason note')]
    public function testRendererSummary(): void
    {
        $renderer = new ResultRenderer();

        self::assertSame(ExitCode::SUCCESS, $renderer->summary($this->io(), new ResultSummary(), false));
        self::assertStringContainsString('yielded no URL', $this->output->fetch());

        $kit = $this->kit();
        $summary = new ResultSummary();
        $summary->add($this->submitters($kit)->create(false, true)->submit(['/a']));
        self::assertSame(ExitCode::SUCCESS, $renderer->summary($this->io(), $summary, false));
        self::assertStringContainsString('Nothing was sent', $this->output->fetch());
        self::assertSame(ExitCode::SUCCESS, $renderer->summary($this->io(), $summary, true));
        self::assertSame('dry_run', $this->json()[0]['reason']);
    }

    public function testVocabularyCounts(): void
    {
        $words = new Vocabulary('entity', 'entities');

        self::assertSame('1 entity', $words->count(1));
        self::assertSame('0 entities', $words->count(0));
        self::assertSame('2 entities', $words->count(2));
    }
}
