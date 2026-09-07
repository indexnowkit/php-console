<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Unit\Command;

use IndexNowKit\Console\Command\ConfigCommand;
use IndexNowKit\Console\ConfigRunner;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Console\Tests\Support\Factory;
use IndexNowKit\Console\Tests\Support\Runners;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\Key\KeyValidator;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfigCommandTest extends TestCase
{
    #[TestDox('indexnow:config [--json]')]
    public function testDefinition(): void
    {
        $command = new ConfigCommand(new ConfigRunner(), Runners::configSource([]));

        self::assertSame('indexnow:config', $command->getName());
        self::assertSame([], $command->getDefinition()->getArguments());
        self::assertSame(['json'], array_keys($command->getDefinition()->getOptions()));
    }

    #[TestDox('the raw configuration, the strict build and the package blocks of the source reach the runner: keys masked, adapter-only keys and package sections printed')]
    public function testConfig(): void
    {
        $raw = ['key' => Factory::KEY, 'base_url' => 'https://www.example.com', 'messenger' => ['transport' => 'async'], 'history' => ['store' => 'pdo']];
        $packages = ['history' => ['store' => 'pdo', 'pdo' => ['dsn' => 'pgsql:host=db;dbname=app;user=app;password=s3cret-pass', 'table' => 'indexnow_submissions'], 'retention_days' => 30]];
        $source = Runners::configSource($raw, $packages);
        $tester = new CommandTester(new ConfigCommand(new ConfigRunner(new Vocabulary(configLocation: 'config/indexnow.php')), $source));

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--json' => true]));
        $display = $tester->getDisplay();
        $decoded = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(1, $source->builds);
        self::assertStringNotContainsString(Factory::KEY, $display);
        self::assertStringNotContainsString('s3cret-pass', $display);
        self::assertSame(KeyValidator::mask(Factory::KEY), $decoded['config']['key']);
        self::assertSame(['messenger' => ['transport' => 'async']], $decoded['adapter'], 'the adapter-only keys, minus the package blocks');
        self::assertSame('indexnow_submissions', $decoded['history']['pdo']['table'], 'the package block is a section of its own');

        self::assertSame(ExitCode::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('debounce.per_url', $tester->getDisplay());
        self::assertStringContainsString('config/indexnow.php', $tester->getDisplay());
        self::assertStringNotContainsString(Factory::KEY, $tester->getDisplay());
    }

    #[TestDox('a configuration that does not build is a FAILURE naming the error, in text and in JSON')]
    public function testInvalidConfiguration(): void
    {
        $tester = new CommandTester(new ConfigCommand(new ConfigRunner(), Runners::configSource(['key' => 'shor*', 'queue' => ['connection' => 'redis']])));

        self::assertSame(ExitCode::FAILURE, $tester->execute([]));
        self::assertStringContainsString('does not build', $tester->getDisplay());

        self::assertSame(ExitCode::FAILURE, $tester->execute(['--json' => true]));
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('error', $decoded);
        self::assertSame(['queue' => ['connection' => 'redis']], $decoded['adapter']);
    }
}
