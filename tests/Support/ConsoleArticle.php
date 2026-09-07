<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Support;

use IndexNowKit\Attribute\IndexNow;

#[IndexNow(url: 'url', when: 'status')]
#[IndexNow(urls: ['/articles'], when: new \IndexNowKit\Attribute\Param\Equals('status', 'published'), name: 'index')]
final class ConsoleArticle
{
    public function __construct(public int $id, public string $status = 'draft') {}

    public function url(): string
    {
        return '/articles/' . $this->id;
    }
}
