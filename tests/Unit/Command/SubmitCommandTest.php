<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Unit\Command;

use IndexNowKit\Console\Command\SubmitCommand;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Console\SubmitRunner;
use IndexNowKit\Console\Tests\Support\Runners;
use IndexNowKit\Http\Response;
use IndexNowKit\Testing\FakeTransport;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SubmitCommandTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function command(array $overrides = []): CommandTester
    {
        $kit = Runners::kit($this->transport, $overrides);

        return new CommandTester(new SubmitCommand(new SubmitRunner($kit, Runners::submitters($kit, $this->transport))));
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

    #[TestDox('indexnow:submit is the name; the urls argument, --force, --dry-run and --json are the ones of Definitions::submit()')]
    public function testDefinition(): void
    {
        $command = new SubmitCommand(new SubmitRunner(Runners::kit($this->transport), Runners::submitters(Runners::kit($this->transport), $this->transport)));

        self::assertSame('indexnow:submit', $command->getName());
        self::assertSame('Submit URLs to IndexNow immediately (synchronously, bypassing the queue)', $command->getDescription());
        self::assertSame(['urls'], array_keys($command->getDefinition()->getArguments()));
        self::assertTrue($command->getDefinition()->getArgument('urls')->isArray());
        self::assertSame(['force', 'dry-run', 'json'], array_keys($command->getDefinition()->getOptions()));
        self::assertSame('f', $command->getDefinition()->getOption('force')->getShortcut());
    }

    #[TestDox('the URLs reach the runner as a list: a table, exit 0; a failing engine gives exit 1')]
    public function testSubmit(): void
    {
        $tester = $this->command();

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['urls' => ['/a', 'https://www.example.com/b']]));
        self::assertMatchesRegularExpression('/\bapi\s+www\.example\.com\s+2\s+ok\b/', $tester->getDisplay());
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $this->sentUrls());

        $this->transport->willRespond(new Response(403));
        self::assertSame(ExitCode::FAILURE, $tester->execute(['urls' => ['/c']]));
    }

    #[TestDox('--json prints the results; --dry-run sends nothing; --force (and -f) bypasses the debounce window')]
    public function testOptions(): void
    {
        $tester = $this->command(['debounce' => ['per_url' => 600]]);

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['urls' => ['/d'], '--json' => true]));
        $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        self::assertSame('ok', $rows[0]['status']);
        self::assertSame(['https://www.example.com/d'], $rows[0]['urls']);
        self::assertCount(1, $this->transport->posts);

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['urls' => ['/d'], '--json' => true]));
        $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        self::assertSame('debounced', $rows[0]['reason'], 'the second run is inside the debounce window');
        self::assertCount(1, $this->transport->posts);

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['urls' => ['/d'], '-f' => true]));
        self::assertCount(2, $this->transport->posts, '-f is --force');

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['urls' => ['/e'], '--dry-run' => true, '--json' => true]));
        $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        self::assertSame('dry_run', $rows[0]['reason']);
        self::assertCount(2, $this->transport->posts, 'nothing sent');
    }
}
