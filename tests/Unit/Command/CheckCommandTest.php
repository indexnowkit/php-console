<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Unit\Command;

use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\SampleOptions;
use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\Command\CheckCommand;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Console\Tests\Support\Factory;
use IndexNowKit\Console\Tests\Support\Runners;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\Http\Response;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Testing\FakeTransport;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckCommandTest extends TestCase
{
    private FakeTransport $transport;

    private SampleOptions $samples;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->samples = new SampleOptions();
        $this->transport->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        $this->transport->onGet('https://b.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function command(array $raw = [], ?SampleOptions $samples = null): CheckCommand
    {
        $raw += ['key' => Factory::KEY, 'base_url' => 'https://www.example.com', 'debounce' => ['per_url' => 0]];
        $config = Factory::config($raw);
        $samples ??= $this->samples;
        $check = new class ($samples) implements CheckInterface {
            public function __construct(private readonly SampleOptions $samples) {}

            public function check(CheckReport $report): void
            {
                $report->ok('adapter: wired');
                $report->ok(\sprintf('samples: %d url(s), %d class(es)', \count($this->samples->urls), \count($this->samples->classes)), 'test.samples');
            }
        };
        $checker = new Checker($config, StaticKeyProvider::fromConfig($config), $this->transport, [$check]);

        return new CheckCommand(new CheckRunner($checker, new Vocabulary(configLocation: 'config/indexnow.php')), Runners::configSource($raw), $samples);
    }

    #[TestDox('indexnow:check with the options of Definitions::check()')]
    public function testDefinition(): void
    {
        $command = $this->command();

        self::assertSame('indexnow:check', $command->getName());
        self::assertSame([], $command->getDefinition()->getArguments());
        self::assertSame(['live', 'host', 'probe-url', 'json', 'strict', 'sample', 'sample-class'], array_keys($command->getDefinition()->getOptions()));
        self::assertTrue($command->getDefinition()->getOption('host')->isArray());
    }

    #[TestDox('H04 the configuration source is built strictly once per run: exit 0 with the report lines; an invalid value is exit 1 naming the configuration location')]
    public function testCheck(): void
    {
        $source = Runners::configSource(['key' => Factory::KEY, 'base_url' => 'https://www.example.com']);
        $config = $source->build();
        $checker = new Checker($config, StaticKeyProvider::fromConfig($config), $this->transport, []);
        $source->builds = 0;
        $tester = new CommandTester(new CheckCommand(new CheckRunner($checker, new Vocabulary(configLocation: 'config/indexnow.php')), $source));

        self::assertSame(ExitCode::SUCCESS, $tester->execute([]));
        self::assertSame(1, $source->builds, 'built once, when the command runs');
        self::assertStringContainsString('key file OK', $tester->getDisplay());
        self::assertStringContainsString('IndexNow is ready.', $tester->getDisplay());

        $invalid = new CommandTester(new CheckCommand(new CheckRunner($checker, new Vocabulary(configLocation: 'config/indexnow.php')), Runners::configSource(['key' => 'shor*'])));
        self::assertSame(ExitCode::FAILURE, $invalid->execute([]));
        self::assertStringContainsString('configuration:', $invalid->getDisplay());
        self::assertStringContainsString('config/indexnow.php', $invalid->getDisplay());
    }

    #[TestDox('--host repeated reaches the runner as a list, --json as the report, --strict fails on warnings')]
    public function testHostJsonAndStrict(): void
    {
        $tester = new CommandTester($this->command(['hosts' => ['b.example.com' => Factory::KEY]]));

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--host' => ['B.example.com', 'www.example.com'], '--json' => true]));
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame('warning', $report['status'], 'a hosts map without strict_hosts is a warning');
        self::assertSame(['b.example.com', 'www.example.com'], array_column(array_filter($report['items'], static fn(array $i): bool => $i['code'] === 'key_file.status'), 'host'), 'one key file line per requested host, in the requested order');
        self::assertContains('adapter: wired', array_column($report['items'], 'message'));

        self::assertSame(ExitCode::FAILURE, $tester->execute(['--strict' => true]), '--strict: the warning fails the run');
        self::assertStringContainsString('--strict treats the warnings above', $tester->getDisplay());
    }

    #[TestDox('--live and --probe-url reach the runner: a real probe goes to the engine with the given page')]
    public function testLiveAndProbeUrl(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--live' => true, '--probe-url' => 'https://www.example.com/about']));
        self::assertNotSame([], $this->transport->posts, '--live sends a probe');
        self::assertSame(['https://www.example.com/about'], $this->transport->posts[0]['body']['urlList']);
        self::assertStringContainsString('accepted probe (200)', $tester->getDisplay());
    }

    #[TestDox('--sample and --sample-class are written into the SampleOptions holder before the checker runs, empty values dropped; without a holder they are accepted and ignored')]
    public function testSamplesReachTheHolder(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--sample' => ['/a', '', '/b'], '--sample-class' => ['App\\Post', 'App\\Page:7']]));
        self::assertSame(['/a', '/b'], $this->samples->urls);
        self::assertSame(['App\\Post', 'App\\Page:7'], $this->samples->classes);
        self::assertStringContainsString('samples: 2 url(s), 2 class(es)', $tester->getDisplay(), 'the check read the holder the command filled');

        self::assertSame(ExitCode::SUCCESS, $tester->execute([]), 'the second run of the same process starts from an empty holder');
        self::assertTrue($this->samples->isEmpty());
        self::assertStringContainsString('samples: 0 url(s), 0 class(es)', $tester->getDisplay());

        $raw = ['key' => Factory::KEY, 'base_url' => 'https://www.example.com'];
        $config = Factory::config($raw);
        $checker = new Checker($config, StaticKeyProvider::fromConfig($config), $this->transport, []);
        $without = new CommandTester(new CheckCommand(new CheckRunner($checker), Runners::configSource($raw)));
        self::assertSame(ExitCode::SUCCESS, $without->execute(['--sample' => ['/a']]));
    }
}
