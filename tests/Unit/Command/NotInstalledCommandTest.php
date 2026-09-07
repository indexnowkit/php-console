<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Unit\Command;

use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Console\Command\HistoryNotInstalledCommand;
use IndexNowKit\Console\Command\NotInstalledCommand;
use IndexNowKit\Console\Command\SitemapNotInstalledCommand;
use IndexNowKit\Console\Command\StatusNotInstalledCommand;
use IndexNowKit\Console\ExitCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class NotInstalledCommandTest extends TestCase
{
    /**
     * @return iterable<string, array{0: class-string<NotInstalledCommand>, 1: string, 2: OptionalPackage, 3: array<string, mixed>}>
     */
    public static function stubs(): iterable
    {
        yield 'sitemap' => [SitemapNotInstalledCommand::class, 'indexnow:sitemap', OptionalPackage::sitemap(), ['sitemap' => 'https://www.example.com/sitemap.xml', '--dry-run' => true, '--changed-since' => '1 day']];
        yield 'history' => [HistoryNotInstalledCommand::class, 'indexnow:history', OptionalPackage::history(), ['--json' => true, '--purge' => '7', '--limit' => '3']];
        yield 'status' => [StatusNotInstalledCommand::class, 'indexnow:status', OptionalPackage::history(), ['--json' => true]];
    }

    /**
     * @param class-string<NotInstalledCommand> $class
     * @param array<string, mixed>              $input
     */
    #[DataProvider('stubs')]
    #[TestDox('$_dataName: the stub carries the name of the command it replaces, accepts every argument and option of it, prints the message on one line and exits 1')]
    public function testStub(string $class, string $name, OptionalPackage $package, array $input): void
    {
        $command = new $class($package->notInstalledMessage());
        self::assertSame($name, $command->getName());
        self::assertStringContainsString('not installed', $command->getDescription());
        self::assertInstanceOf(NotInstalledCommand::class, $command);

        $tester = new CommandTester($command);
        self::assertSame(ExitCode::FAILURE, $tester->execute($input));
        self::assertSame($package->notInstalledMessage(), trim($tester->getDisplay()), 'one line: a cron log greps it');

        self::assertSame(ExitCode::FAILURE, $tester->execute($input, ['decorated' => true]));
        self::assertSame("\033[37;41m" . $package->notInstalledMessage() . "\033[39;49m", trim($tester->getDisplay()), 'the whole line in the error style on a terminal');
    }
}
