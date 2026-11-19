<?php

declare(strict_types=1);

namespace Teme\Directory\Markdown;

/**
 * A repository at a release tag: where a README's relative links point.
 */
final class Location
{
    public function __construct(
        public readonly string $owner,
        public readonly string $repo,
        public readonly string $tag,
    ) {}

    /**
     * GitHub's page for a file at the tag.
     */
    public function blob(string $path): string
    {
        $encoded = implode('/', array_map(static fn (string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $path)));

        return "https://github.com/{$this->owner}/{$this->repo}/blob/".rawurlencode($this->tag).'/'.$encoded;
    }
}
