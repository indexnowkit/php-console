<?php

declare(strict_types=1);

namespace IndexNowKit\Console\Tests\Support;

use IndexNowKit\Attribute\IndexNow;

#[IndexNow(url: 'url', when: 'published')]
final class ConsolePost
{
    public function __construct(public int $id, public string $slug, public bool $published = true) {}

    public function url(): string
    {
        return '/posts/' . $this->slug;
    }
}
