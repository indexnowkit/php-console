<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Command;

use IndexNowKit\Console\ExitCode;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The body of the stubs that stand in for a command of an optional package that is not installed
 * ({@see SitemapNotInstalledCommand}, {@see HistoryNotInstalledCommand}, {@see StatusNotInstalledCommand}): a cron or
 * a runbook that names the command gets one sentence — `OptionalPackage::notInstalledMessage()` of the core — and
 * exit 1 instead of "command not found". Every argument and option of the real command is accepted and ignored.
 *
 * One final subclass per command name, each carrying its `#[AsCommand]`: a registry keyed by class (the command
 * map of yiisoft/yii-console, the lazy command loader of Symfony) needs a class of its own per name. Extend it for a
 * stub of your own optional command; the message is the only constructor argument.
 */
abstract class NotInstalledCommand extends Command
{
    /**
     * @param string $message what to print: `Adapter\OptionalPackage::notInstalledMessage()` of the package
     */
    public function __construct(private readonly string $message)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>' . $this->message . '</error>'); // one line, not a wrapped block: a cron log greps it

        return ExitCode::FAILURE;
    }
}
