<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Command;

use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\SubmitSubjectsOptions;
use IndexNowKit\Console\SubmitSubjectsRunner;
use IndexNowKit\Console\Vocabulary;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `indexnow:submit-<subject> <class> [<ids>...] [--event=] [--limit=] [--explain] [-f|--force] [--dry-run] [--json]`:
 * the manual path after bulk updates that fire no ORM events. The name is the adapter's (`Vocabulary::$submitSubjects`:
 * `indexnow:submit-entity` in Symfony, `indexnow:submit-record` in Yii3), so there is no `#[AsCommand]` here — a
 * Symfony container registers it lazily with the `command` and `description` attributes of the `console.command`
 * tag; the description is {@see Definitions::submitSubjects()}'s, so the word "entity" / "record" comes from the
 * vocabulary in both places. The class argument is `class` unless the adapter's command always called it
 * otherwise (`model` in Laravel: `Artisan::call('indexnow:submit-model', ['model' => …])` keeps working).
 */
final class SubmitSubjectsCommand extends Command
{
    /**
     * @param string $classArgument the name of the class argument (`class`, `model`); positional on the command line either way
     */
    public function __construct(private readonly SubmitSubjectsRunner $runner, private readonly Vocabulary $words, private readonly string $classArgument = 'class')
    {
        parent::__construct($words->submitSubjects);
    }

    protected function configure(): void
    {
        Definitions::submitSubjects($this->words, $this->classArgument)->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $class = $input->getArgument($this->classArgument);
        $event = $input->getOption('event');
        $limit = $input->getOption('limit');
        /** @var list<string> $ids */
        $ids = $input->getArgument('ids');

        return $this->runner->run(new SymfonyStyle($input, $output), new SubmitSubjectsOptions(
            class: \is_string($class) ? $class : '',
            ids: $ids,
            event: \is_string($event) ? $event : '',
            limit: is_numeric($limit) ? (int) $limit : SubmitSubjectsOptions::DEFAULT_LIMIT,
            explain: (bool) $input->getOption('explain'),
            force: (bool) $input->getOption('force'),
            dryRun: (bool) $input->getOption('dry-run'),
            json: (bool) $input->getOption('json'),
        ));
    }
}
