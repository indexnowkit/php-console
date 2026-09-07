<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `indexnow:status` while `indexnowkit/history` is not installed: the install line and exit 1 ({@see NotInstalledCommand}).
 */
#[AsCommand(name: 'indexnow:status', description: 'Print the IndexNow status (needs indexnowkit/history, which is not installed)')]
final class StatusNotInstalledCommand extends NotInstalledCommand {}
