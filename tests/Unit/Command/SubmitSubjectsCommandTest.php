<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Unit\Command;

use IndexNowKit\Console\Command\SubmitSubjectsCommand;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Console\SubmitSubjectsOptions;
use IndexNowKit\Console\SubmitSubjectsRunner;
use IndexNowKit\Console\Tests\Support\ArraySubjectLoader;
use IndexNowKit\Console\Tests\Support\ConsolePost;
use IndexNowKit\Console\Tests\Support\Runners;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\Event;
use IndexNowKit\Testing\FakeTransport;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SubmitSubjectsCommandTest extends TestCase
{
    private FakeTransport $transport;

    private ArraySubjectLoader $loader;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->loader = Runners::loader();
    }

    private function command(?Vocabulary $words = null, string $classArgument = 'class'): SubmitSubjectsCommand
    {
        $words ??= new Vocabulary('entity', 'entities', 'bin/console', 'indexnow:submit-entity');
        $kit = Runners::kit($this->transport);

        return new SubmitSubjectsCommand(new SubmitSubjectsRunner($kit, $this->loader, Runners::submitters($kit, $this->transport), words: $words), $words, $classArgument);
    }

    /**
     * @return list<string>
     */
    private function sentUrls(): array
    {
        $urls = [];
        foreach ($this->transport->posts as $post) {
            $urls = [...$urls, ...$post['body']['urlList']];
        }

        return $urls;
    }

    #[TestDox('the name is the vocabulary\'s submitSubjects (indexnow:submit-entity, indexnow:submit-record); the description says the subject')]
    public function testNameAndDescriptionComeFromTheVocabulary(): void
    {
        $entity = $this->command();
        self::assertSame('indexnow:submit-entity', $entity->getName());
        self::assertSame('Resolve the URLs of entities through their #[IndexNow] rules and submit them (the manual path after bulk updates)', $entity->getDescription());

        $record = $this->command(new Vocabulary('record', 'records', './yii', 'indexnow:submit-record'));
        self::assertSame('indexnow:submit-record', $record->getName());
        self::assertStringContainsString('URLs of records', $record->getDescription());
        self::assertSame(['class', 'ids'], array_keys($record->getDefinition()->getArguments()));
        self::assertSame(['event', 'limit', 'explain', 'force', 'dry-run', 'json'], array_keys($record->getDefinition()->getOptions()));
        self::assertSame((string) SubmitSubjectsOptions::DEFAULT_LIMIT, $record->getDefinition()->getOption('limit')->getDefault());
    }

    #[TestDox('the class argument is named by the adapter (model in Laravel): the definition and the runner both read it')]
    public function testClassArgumentName(): void
    {
        $command = $this->command(new Vocabulary('model', 'models', 'php artisan', 'indexnow:submit-model'), 'model');
        self::assertSame(['model', 'ids'], array_keys($command->getDefinition()->getArguments()));
        self::assertSame('Model class (FQCN or short name)', $command->getDefinition()->getArgument('model')->getDescription());

        $tester = new CommandTester($command);
        self::assertSame(ExitCode::SUCCESS, $tester->execute(['model' => ConsolePost::class, 'ids' => ['1']]));
        self::assertSame(['https://www.example.com/posts/one'], $this->sentUrls());
    }

    #[TestDox('class and ids reach the runner: every object of the class up to --limit, or the given ids; --explain sends nothing')]
    public function testClassIdsLimitAndExplain(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['class' => ConsolePost::class]));
        self::assertStringContainsString('3 entities -> 2 URL(s)', $tester->getDisplay());
        self::assertEqualsCanonicalizing(['https://www.example.com/posts/one', 'https://www.example.com/posts/two'], $this->sentUrls());

        $this->transport->posts = [];
        self::assertSame(ExitCode::SUCCESS, $tester->execute(['class' => ConsolePost::class, '--limit' => '2']));
        self::assertStringContainsString('2 entities -> 2 URL(s)', $tester->getDisplay(), '--limit reaches the runner as an integer');

        $this->transport->posts = [];
        self::assertSame(ExitCode::SUCCESS, $tester->execute(['class' => ConsolePost::class, '--limit' => 'many']));
        self::assertStringContainsString('3 entities', $tester->getDisplay(), 'a non-numeric --limit falls back to the default');

        $this->transport->posts = [];
        self::assertSame(ExitCode::SUCCESS, $tester->execute(['class' => ConsolePost::class, 'ids' => ['1'], '--explain' => true, '--json' => true]));
        $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        self::assertSame('/posts/one', $rows[0]['url'], 'the URL as the rule produced it');
        self::assertSame([], $this->transport->posts, '--explain sends nothing');
    }

    #[TestDox('--event reaches the loader; an unknown event, an unknown class or a missing id is INVALID')]
    public function testEventAndInvalidInput(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['class' => ConsolePost::class, 'ids' => ['1'], '--event' => 'deleted']));
        self::assertSame(Event::Deleted, end($this->loader->events));

        self::assertSame(ExitCode::INVALID, $tester->execute(['class' => ConsolePost::class, '--event' => 'moved']));
        self::assertStringContainsString('--event must be', $tester->getDisplay());
        self::assertSame(ExitCode::INVALID, $tester->execute(['class' => 'Nope']));
        self::assertSame(ExitCode::INVALID, $tester->execute(['class' => ConsolePost::class, 'ids' => ['999']]));
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    #[TestDox('--dry-run and --force reach the submitter; --json prints results')]
    public function testDryRunForceAndJson(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['class' => ConsolePost::class, 'ids' => ['1'], '--dry-run' => true, '--json' => true]));
        $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        self::assertSame('dry_run', $rows[0]['reason']);
        self::assertSame([], $this->transport->posts);

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['class' => ConsolePost::class, 'ids' => ['1'], '-f' => true]));
        self::assertSame(['https://www.example.com/posts/one'], $this->sentUrls());
    }
}
