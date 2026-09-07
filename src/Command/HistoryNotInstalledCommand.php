<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `indexnow:history` while `indexnowkit/history` is not installed: the install line and exit 1 ({@see NotInstalledCommand}).
 */
#[AsCommand(name: 'indexnow:history', description: 'List the recorded IndexNow submissions (needs indexnowkit/history, which is not installed)')]
final class HistoryNotInstalledCommand extends NotInstalledCommand {}
