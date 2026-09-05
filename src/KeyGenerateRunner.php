<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Key\KeyGenerator;
use IndexNowKit\Key\KeyValidator;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Body of `indexnow:key:generate`: prints a fresh key, or writes `INDEXNOW_KEY=<key>` to an env file. Writing is
 * idempotent: an existing line is kept unless `--force` rotates it. A rotation keeps the old key as
 * `INDEXNOW_PREVIOUS_KEY` (the key file keeps answering for it while the engines catch up) and refuses to run while
 * that variable still holds the key of an earlier rotation, unless told what to do with it.
 */
final class KeyGenerateRunner
{
    /** The refusal of a second rotation while INDEXNOW_PREVIOUS_KEY is still set (spec 17 §5.2). */
    public const PREVIOUS_KEY_STILL_SET = 'INDEXNOW_PREVIOUS_KEY is still set from an earlier rotation; engines may still verify against it. Remove it first, or pass --no-previous to drop it, or --yes to overwrite';

    private const KEY_LINE = '/^([ \t]*)INDEXNOW_KEY[ \t]*=[ \t]*(.*?)[ \t]*$/m';
    private const PREVIOUS_LINE = '/^([ \t]*)INDEXNOW_PREVIOUS_KEY[ \t]*=[ \t]*(.*?)[ \t]*$/m';

    public function __construct(private readonly Vocabulary $words = new Vocabulary()) {}

    /**
     * @param int         $length     key length (8-128)
     * @param bool        $hex        hex alphabet; false = the full alphanumeric alphabet
     * @param string|null $envFile    file to write `INDEXNOW_KEY=` to; null = print only
     * @param bool        $force      replace an existing INDEXNOW_KEY line (key rotation): the old key becomes INDEXNOW_PREVIOUS_KEY
     * @param bool        $noPrevious rotate without keeping the old key (drops an INDEXNOW_PREVIOUS_KEY line too)
     * @param bool        $yes        rotate even when INDEXNOW_PREVIOUS_KEY is still set from an earlier rotation, overwriting it
     *
     * @return int exit code ({@see ExitCode})
     */
    public function run(SymfonyStyle $io, int $length = 32, bool $hex = true, ?string $envFile = null, bool $force = false, bool $noPrevious = false, bool $yes = false): int
    {
        $key = KeyGenerator::generate($length, $hex);

        if ($envFile === null) {
            $io->writeln($key);
            $io->newLine();
            $io->text(['Add to your environment:', '  INDEXNOW_KEY=' . $key, \sprintf('Then run: %s %s', $this->words->cli, $this->words->check)]);

            return ExitCode::SUCCESS;
        }

        $contents = is_file($envFile) ? (string) file_get_contents($envFile) : '';
        $line = 'INDEXNOW_KEY=' . $key;
        if (preg_match(self::KEY_LINE, $contents, $current) === 1) {
            if (!$force) {
                $io->writeln(\sprintf('<info>%s already defines INDEXNOW_KEY, nothing to do (use --force to rotate the key).</info>', $envFile));

                return ExitCode::SUCCESS;
            }
            $previous = self::unquote($current[2]);
            if (!$noPrevious && !$yes && self::previousKeyIsSet($contents)) {
                $io->error(self::PREVIOUS_KEY_STILL_SET . '.');

                return ExitCode::FAILURE;
            }
            $contents = (string) preg_replace(self::KEY_LINE, '$1' . $line, $contents, 1);
            $contents = $noPrevious ? self::withoutPreviousKey($contents) : self::withPreviousKey($contents, $previous);
            $io->warning(\sprintf('Rotating the key: submissions fail with 403 until the new key file is reachable (CDN caches!). Run %s afterwards.', $this->words->check));
        } else {
            $contents .= ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n") . $line . "\n";
        }
        if (@file_put_contents($envFile, $contents) === false) {
            $io->error(\sprintf('Cannot write %s.', $envFile));

            return ExitCode::FAILURE;
        }
        $io->writeln(\sprintf('<info>INDEXNOW_KEY written to %s.</info>', $envFile));
        if (isset($previous) && !$noPrevious && $previous !== '') {
            $io->text(\sprintf('The old key %s is kept as INDEXNOW_PREVIOUS_KEY: the key file keeps answering for it while the engines pick up the new key. Remove the variable once %s --live is green.', KeyValidator::mask($previous), $this->words->check));
        }
        $io->text(\sprintf('The key file is served at /<key>.txt %s. Verify with: %s %s', $this->words->keyFileServedBy, $this->words->cli, $this->words->check));

        return ExitCode::SUCCESS;
    }

    private static function previousKeyIsSet(string $contents): bool
    {
        return preg_match(self::PREVIOUS_LINE, $contents, $m) === 1 && self::unquote($m[2]) !== '';
    }

    /** Sets INDEXNOW_PREVIOUS_KEY to $previous: replaces the existing line, else adds one right after INDEXNOW_KEY. */
    private static function withPreviousKey(string $contents, string $previous): string
    {
        if ($previous === '') {
            return $contents;
        }
        $line = 'INDEXNOW_PREVIOUS_KEY=' . $previous;
        if (preg_match(self::PREVIOUS_LINE, $contents) === 1) {
            return (string) preg_replace(self::PREVIOUS_LINE, '$1' . $line, $contents, 1);
        }

        return (string) preg_replace(self::KEY_LINE, '$0' . "\n" . '$1' . $line, $contents, 1);
    }

    private static function withoutPreviousKey(string $contents): string
    {
        return (string) preg_replace('/^[ \t]*INDEXNOW_PREVIOUS_KEY[ \t]*=.*(?:\n|$)/m', '', $contents);
    }

    private static function unquote(string $value): string
    {
        return trim($value, " \t\"'");
    }
}
