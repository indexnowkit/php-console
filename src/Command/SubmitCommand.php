<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Command;

use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\SubmitRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `indexnow:submit <urls...> [-f|--force] [--dry-run] [--json]`: the URLs, sent now, synchronously, past the queue.
 * The one command of the family every adapter on symfony/console registers as it is: only the runner varies.
 */
#[AsCommand(name: 'indexnow:submit', description: 'Submit URLs to IndexNow immediately (synchronously, bypassing the queue)')]
final class SubmitCommand extends Command
{
    public function __construct(private readonly SubmitRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::submit()->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $urls */
        $urls = $input->getArgument('urls');

        return $this->runner->run(new SymfonyStyle($input, $output), $urls, (bool) $input->getOption('force'), (bool) $input->getOption('dry-run'), (bool) $input->getOption('json'));
    }
}
