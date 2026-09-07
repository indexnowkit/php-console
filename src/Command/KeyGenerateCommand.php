<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Command;

use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\KeyGenerateRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `indexnow:key:generate [-l|--length=] [--alphanumeric] [--write-env[=FILE]] [--force] [--no-previous] [--yes]`.
 * `--write-env` without a value writes to the adapter's env file: `$envFile` when the adapter pins one
 * (`%kernel.project_dir%/.env.local` in Symfony), else `<current directory>/$envFileName` (Yii3, plain PHP: the
 * application is run from its root). `$envFileName` is also the file the help text names.
 */
#[AsCommand(name: 'indexnow:key:generate', description: 'Generate a new IndexNow key (optionally write INDEXNOW_KEY to the env file)')]
final class KeyGenerateCommand extends Command
{
    /** The key length when `--length` is not given or not a number. */
    public const DEFAULT_LENGTH = 32;

    /**
     * @param string      $envFileName the file `--write-env` without a value means, relative to the current directory, and as printed in the help (`.env`, `.env.local`)
     * @param string|null $envFile     an absolute path that wins over `$envFileName` for `--write-env` without a value; null = `<current directory>/$envFileName`
     */
    public function __construct(private readonly KeyGenerateRunner $runner, private readonly string $envFileName = '.env', private readonly ?string $envFile = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::keyGenerate($this->envFileName)->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $length = $input->getOption('length');
        $writeEnv = $input->getOption('write-env');
        $envFile = match (true) {
            $writeEnv === false => null,
            \is_string($writeEnv) && $writeEnv !== '' => $writeEnv,
            default => $this->envFile ?? self::cwd() . '/' . $this->envFileName,
        };

        return $this->runner->run(new SymfonyStyle($input, $output), is_numeric($length) ? (int) $length : self::DEFAULT_LENGTH, !(bool) $input->getOption('alphanumeric'), $envFile, (bool) $input->getOption('force'), (bool) $input->getOption('no-previous'), (bool) $input->getOption('yes'));
    }

    private static function cwd(): string
    {
        $cwd = getcwd();

        return $cwd === false ? '.' : $cwd;
    }
}
