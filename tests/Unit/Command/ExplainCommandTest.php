<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Unit\Command;

use IndexNowKit\Console\Command\ExplainCommand;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Console\ExplainRunner;
use IndexNowKit\Console\Tests\Support\ConsoleArticle;
use IndexNowKit\Console\Tests\Support\ConsolePost;
use IndexNowKit\Console\Tests\Support\Runners;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Url\UrlNormalizer;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ExplainCommandTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    private function command(): ExplainCommand
    {
        $kit = Runners::kit($this->transport);
        $words = new Vocabulary('entity', 'entities', 'bin/console', 'indexnow:submit-entity');

        return new ExplainCommand(new ExplainRunner($kit, Runners::loader(), $kit->config, $kit->keys, new MemoryDebounceStore(), new UrlNormalizer($kit->config->baseUrl), $words), $words);
    }

    #[TestDox('indexnow:explain <class> <id> [--event] [--json]; the description names the subject of the vocabulary')]
    public function testDefinition(): void
    {
        $command = $this->command();

        self::assertSame('indexnow:explain', $command->getName());
        self::assertSame('Explain what IndexNow would do for one entity: rules, guards, URLs, key, debounce (sends nothing)', $command->getDescription());
        self::assertSame(['class', 'id'], array_keys($command->getDefinition()->getArguments()));
        self::assertTrue($command->getDefinition()->getArgument('id')->isRequired());
        self::assertSame(['event', 'json'], array_keys($command->getDefinition()->getOptions()));
    }

    #[TestDox('class, id and --event reach the runner; nothing is sent; --json is the machine-readable explanation')]
    public function testExplain(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['class' => ConsolePost::class, 'id' => '1']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('IndexNow explain: ' . ConsolePost::class . ' #1 (updated)', $display);
        self::assertStringContainsString('https://www.example.com/posts/one', $display);
        self::assertStringContainsString('bin/console indexnow:submit-entity ' . ConsolePost::class . ' 1', $display);
        self::assertSame([], $this->transport->posts, 'explain sends nothing');

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['class' => ConsolePost::class, 'id' => '1', '--event' => 'deleted']));
        self::assertStringContainsString('#1 (deleted)', $tester->getDisplay());

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['class' => ConsoleArticle::class, 'id' => '1', '--json' => true]));
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(ConsoleArticle::class, $decoded['class']);
        self::assertSame('1', $decoded['id']);
    }

    #[TestDox('an unknown id or event is INVALID')]
    public function testInvalidInput(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(ExitCode::INVALID, $tester->execute(['class' => ConsolePost::class, 'id' => '999']));
        self::assertSame(ExitCode::INVALID, $tester->execute(['class' => ConsolePost::class, 'id' => '1', '--event' => 'moved']));
    }
}
