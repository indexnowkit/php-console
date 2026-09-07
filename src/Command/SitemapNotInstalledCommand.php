<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `indexnow:sitemap` while `indexnowkit/sitemap` is not installed: the install line and exit 1 ({@see NotInstalledCommand}).
 */
#[AsCommand(name: 'indexnow:sitemap', description: 'Submit every URL of a sitemap (needs indexnowkit/sitemap, which is not installed)')]
final class SitemapNotInstalledCommand extends NotInstalledCommand {}
