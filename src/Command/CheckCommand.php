<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Command;

use IndexNowKit\Check\SampleOptions;
use IndexNowKit\Config;
use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\ConfigSourceInterface;
use IndexNowKit\Console\Definitions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `indexnow:check [--live] [--host=] [--probe-url=] [--json] [--strict] [--sample=] [--sample-class=]`: the
 * configuration validated strictly ({@see ConfigSourceInterface::build()}), the key files fetched, every check of
 * the adapter printed. `--sample` and `--sample-class` go into the graph's {@see SampleOptions} before the checker
 * runs; the sampler that turns a class into URLs is already inside that holder — the adapter wires it, this command
 * knows nothing of the ORM.
 */
#[AsCommand(name: 'indexnow:check', description: 'Validate the IndexNow configuration, verify the key file is reachable, report how submissions are wired')]
final class CheckCommand extends Command
{
    /**
     * @param SampleOptions|null $samples the holder the `--sample` / `--sample-class` values are written to, null when the adapter has no sample check
     */
    public function __construct(private readonly CheckRunner $runner, private readonly ConfigSourceInterface $config, private readonly ?SampleOptions $samples = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::check()->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hosts = $input->getOption('host');
        $probeUrl = $input->getOption('probe-url');
        if ($this->samples !== null) {
            $this->samples->urls = self::strings($input->getOption('sample'));
            $this->samples->classes = self::strings($input->getOption('sample-class'));
        }

        return $this->runner->run(
            new SymfonyStyle($input, $output),
            fn(): Config => $this->config->build(),
            (bool) $input->getOption('live'),
            \is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : (\is_string($hosts) ? $hosts : null),
            \is_string($probeUrl) ? $probeUrl : null,
            (bool) $input->getOption('json'),
            (bool) $input->getOption('strict'),
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $option): array
    {
        return \is_array($option) ? array_values(array_filter($option, static fn(mixed $v): bool => \is_string($v) && $v !== '')) : [];
    }
}
