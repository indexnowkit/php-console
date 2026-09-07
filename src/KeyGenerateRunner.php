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

        $existed = is_file($envFile);
        if (!$existed && !self::createPrivate($envFile)) {
            $io->error(\sprintf('Cannot write %s.', $envFile));

            return ExitCode::FAILURE;
        }
        $contents = $existed ? (string) file_get_contents($envFile) : '';
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
            $contents = (string) preg_replace(self::KEY_LINE, '$1' . self::replacement($line), $contents, 1);
            $contents = $noPrevious ? self::withoutPreviousKey($contents) : self::withPreviousKey($contents, $previous);
            $io->warning(\sprintf('Rotating the key: submissions fail with 403 until the new key file is reachable (CDN caches!). Run %s afterwards.', $this->words->check));
        } else {
            $contents .= ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n") . $line . "\n";
        }
        $wideMode = $existed ? self::widerThanOwner($envFile) : null;
        if (@file_put_contents($envFile, $contents) === false) {
            $io->error(\sprintf('Cannot write %s.', $envFile));

            return ExitCode::FAILURE;
        }
        $io->writeln(\sprintf('<info>INDEXNOW_KEY written to %s.</info>', $envFile));
        if ($wideMode !== null) {
            // Narrowing an existing file is the application's call (a deploy may rely on the group reading it): say it, do not do it.
            $io->warning(\sprintf('%s is readable beyond its owner (mode %s) and now holds the key: run chmod 600 %s.', $envFile, $wideMode, $envFile));
        }
        if (isset($previous) && !$noPrevious && $previous !== '') {
            $io->text(\sprintf('The old key %s is kept as INDEXNOW_PREVIOUS_KEY: the key file keeps answering for it while the engines pick up the new key. Remove the variable once %s --live is green.', KeyValidator::mask($previous), $this->words->check));
        }
        $io->text(\sprintf('The key file is served at /<key>.txt %s. Verify with: %s %s', $this->words->keyFileServedBy, $this->words->cli, $this->words->check));

        return ExitCode::SUCCESS;
    }

    /**
     * Creates $envFile empty and readable by its owner only, before the key is written into it: `file_put_contents()`
     * alone would create it under the umask (usually 0644) and hold the key for as long as the `chmod` takes.
     */
    private static function createPrivate(string $envFile): bool
    {
        $handle = @fopen($envFile, 'x');
        if ($handle === false) {
            return false;
        }
        fclose($handle);

        return @chmod($envFile, 0o600);
    }

    /** The mode of $envFile as `0644` when it lets anyone but the owner read it, null when it is 0600 or narrower. */
    private static function widerThanOwner(string $envFile): ?string
    {
        $mode = @fileperms($envFile);
        if ($mode === false) {
            return null;
        }
        $mode &= 0o777;

        return ($mode & 0o077) === 0 ? null : \sprintf('0%03o', $mode);
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
        $line = 'INDEXNOW_PREVIOUS_KEY=' . self::replacement($previous);
        if (preg_match(self::PREVIOUS_LINE, $contents) === 1) {
            return (string) preg_replace(self::PREVIOUS_LINE, '$1' . $line, $contents, 1);
        }

        return (string) preg_replace(self::KEY_LINE, '$0' . "\n" . '$1' . $line, $contents, 1);
    }

    /**
     * A value going into the replacement string of `preg_replace()`: the key read from the env file is not ours, and
     * `INDEXNOW_KEY=$0` would otherwise substitute the match instead of being kept verbatim.
     */
    private static function replacement(string $value): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $value);
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
