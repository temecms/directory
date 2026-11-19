<?php

declare(strict_types=1);

namespace Teme\Directory\Listings;

/**
 * One package's listing: its Composer name, its GitHub repository, whether it
 * is a plugin or a theme, its categories, and whether the Teme team picked it.
 */
final class Listing
{
    /**
     * @param  list<string>  $categories
     */
    public function __construct(
        public readonly string $name,
        public readonly string $owner,
        public readonly string $repo,
        public readonly string $kind,
        public readonly array $categories,
        public readonly bool $picked,
    ) {}

    /**
     * The repository's address on GitHub.
     */
    public function repository(): string
    {
        return "https://github.com/{$this->owner}/{$this->repo}";
    }
}
