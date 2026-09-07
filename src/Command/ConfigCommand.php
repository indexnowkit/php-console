<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Command;

use IndexNowKit\Config;
use IndexNowKit\Console\ConfigRunner;
use IndexNowKit\Console\ConfigSourceInterface;
use IndexNowKit\Console\Definitions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `indexnow:config [--json]`: the effective configuration (defaults and environment applied), the adapter-only keys
 * of the raw configuration, the blocks of the installed optional packages — keys and DSN passwords masked by
 * {@see ConfigRunner}. What a bug report pastes.
 */
#[AsCommand(name: 'indexnow:config', description: 'Print the effective IndexNow configuration: defaults and environment applied, keys masked (paste it into a bug report)')]
final class ConfigCommand extends Command
{
    public function __construct(private readonly ConfigRunner $runner, private readonly ConfigSourceInterface $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::config()->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runner->run(new SymfonyStyle($input, $output), fn(): Config => $this->config->build(), $this->config->raw(), (bool) $input->getOption('json'), $this->config->packages());
    }
}
