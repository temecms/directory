<?php

declare(strict_types=1);

namespace Teme\Directory\Index;

/**
 * One build of the published files, with the hashes that tell whether it
 * differs from the last one published.
 */
final class Build
{
    /**
     * @param  array<string, string>  $files  Path from the site's root, then its bytes.
     * @param  string  $contentHash  Everything but the time, the sequence and GitHub's figures.
     * @param  string  $figuresHash  GitHub's stars and open issues.
     */
    public function __construct(
        public readonly array $files,
        public readonly string $contentHash,
        public readonly string $figuresHash,
    ) {}
}
