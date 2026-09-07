<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Unit\Command;

use IndexNowKit\Console\Command\KeyGenerateCommand;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Console\KeyGenerateRunner;
use IndexNowKit\Console\Vocabulary;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class KeyGenerateCommandTest extends TestCase
{
    private string $dir;

    private string $cwd;

    /** Every run happens inside a temporary directory: what a command writes "to the current directory" lands there, never in the package. */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/indexnow-keygen-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        $this->cwd = $cwd;
        chdir($this->dir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        foreach (scandir($this->dir) ?: [] as $file) {
            if (is_file($this->dir . '/' . $file)) {
                unlink($this->dir . '/' . $file);
            }
        }
        rmdir($this->dir);
    }

    private function command(string $envFileName = '.env', ?string $envFile = null): KeyGenerateCommand
    {
        return new KeyGenerateCommand(new KeyGenerateRunner(new Vocabulary(cli: 'bin/console')), $envFileName, $envFile);
    }

    #[TestDox('indexnow:key:generate with the options of Definitions::keyGenerate(); the help names the adapter\'s env file')]
    public function testDefinition(): void
    {
        $command = $this->command('.env.local');

        self::assertSame('indexnow:key:generate', $command->getName());
        self::assertSame('Generate a new IndexNow key (optionally write INDEXNOW_KEY to .env.local)', $command->getDescription());
        self::assertSame(['length', 'alphanumeric', 'write-env', 'force', 'no-previous', 'yes'], array_keys($command->getDefinition()->getOptions()));
        self::assertStringContainsString('default .env.local', $command->getDefinition()->getOption('write-env')->getDescription());
        self::assertSame('l', $command->getDefinition()->getOption('length')->getShortcut());
        self::assertTrue($command->getDefinition()->getOption('write-env')->isValueOptional());

        self::assertStringContainsString('default .env)', $this->command()->getDefinition()->getOption('write-env')->getDescription(), '.env is the default file name');
    }

    #[TestDox('without --write-env the key is printed: 32 hex characters by default, --length and --alphanumeric change it, a non-numeric --length is the default')]
    public function testPrint(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(ExitCode::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/m', $tester->getDisplay());
        self::assertStringContainsString('bin/console indexnow:check', $tester->getDisplay());

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--length' => '16', '--alphanumeric' => true]));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/m', $tester->getDisplay());

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['-l' => '20']));
        self::assertMatchesRegularExpression('/^[a-f0-9]{20}$/m', $tester->getDisplay());

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--length' => 'long']));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/m', $tester->getDisplay());
        self::assertSame(['.', '..'], scandir($this->dir), 'nothing written');
    }

    #[TestDox('--write-env=FILE writes there; --write-env without a value writes to the pinned envFile, else to <cwd>/<envFileName>')]
    public function testWriteEnvTargets(): void
    {
        $explicit = $this->dir . '/explicit.env';
        $tester = new CommandTester($this->command('.env.local', $this->dir . '/.env.local'));
        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--write-env' => $explicit]));
        self::assertMatchesRegularExpression('/^INDEXNOW_KEY=[a-f0-9]{32}\n$/', (string) file_get_contents($explicit));
        self::assertFileDoesNotExist($this->dir . '/.env.local');

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--write-env' => null]));
        self::assertMatchesRegularExpression('/^INDEXNOW_KEY=[a-f0-9]{32}\n$/', (string) file_get_contents($this->dir . '/.env.local'), 'the pinned file');

        $relative = new CommandTester($this->command('.env.test'));
        self::assertSame(ExitCode::SUCCESS, $relative->execute(['--write-env' => null]));
        self::assertMatchesRegularExpression('/^INDEXNOW_KEY=[a-f0-9]{32}\n$/', (string) file_get_contents($this->dir . '/.env.test'), '<current directory>/<envFileName> when nothing is pinned');
    }

    #[TestDox('--force rotates and keeps the old key as INDEXNOW_PREVIOUS_KEY; --no-previous drops it; --yes overwrites a previous key still set')]
    public function testRotation(): void
    {
        $file = $this->dir . '/.env';
        $tester = new CommandTester($this->command('.env', $file));

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--write-env' => null]));
        $first = (string) file_get_contents($file);
        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--write-env' => null]));
        self::assertStringContainsString('nothing to do', $tester->getDisplay());
        self::assertSame($first, file_get_contents($file));

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--write-env' => null, '--force' => true]));
        self::assertStringContainsString('Rotating the key', $tester->getDisplay());
        preg_match('/^INDEXNOW_KEY=(.+)$/m', $first, $m);
        self::assertStringContainsString('INDEXNOW_PREVIOUS_KEY=' . $m[1], (string) file_get_contents($file));

        self::assertSame(ExitCode::FAILURE, $tester->execute(['--write-env' => null, '--force' => true]), 'refused while the previous key is still set');
        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--write-env' => null, '--force' => true, '--yes' => true]));
        self::assertStringContainsString('INDEXNOW_PREVIOUS_KEY=', (string) file_get_contents($file));

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--write-env' => null, '--force' => true, '--no-previous' => true]));
        self::assertMatchesRegularExpression('/^INDEXNOW_KEY=[a-f0-9]{32}\n$/', (string) file_get_contents($file), '--no-previous drops INDEXNOW_PREVIOUS_KEY');
    }
}
