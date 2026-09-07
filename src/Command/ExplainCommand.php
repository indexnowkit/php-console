<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Command;

use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\ExplainRunner;
use IndexNowKit\Console\Vocabulary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `indexnow:explain <class> <id> [--event=] [--json]`: "why was this object not submitted?" — the decision path of one
 * object: rules -> event subscription -> `when` guard -> resolved URLs -> normalization -> host/key -> debounce ->
 * dispatch. Sends nothing. The description here names an "object"; {@see Definitions::explain()} rewrites it with
 * the adapter's word in `configure()`. The class argument is `class` unless the adapter's command always called it
 * otherwise (`model` in Laravel).
 */
#[AsCommand(name: 'indexnow:explain', description: 'Explain what IndexNow would do for one object: rules, guards, URLs, key, debounce (sends nothing)')]
final class ExplainCommand extends Command
{
    /**
     * @param string $classArgument the name of the class argument (`class`, `model`); positional on the command line either way
     */
    public function __construct(private readonly ExplainRunner $runner, private readonly Vocabulary $words, private readonly string $classArgument = 'class')
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::explain($this->words, $this->classArgument)->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $class = $input->getArgument($this->classArgument);
        $id = $input->getArgument('id');
        $event = $input->getOption('event');

        return $this->runner->run(new SymfonyStyle($input, $output), \is_string($class) ? $class : '', \is_scalar($id) ? (string) $id : '', \is_string($event) ? $event : '', (bool) $input->getOption('json'));
    }
}
