<?php

declare(strict_types=1);

namespace Teme\Directory\GitHub;

/**
 * What the directory reads from GitHub about a listed repository.
 */
interface GitHub
{
    /**
     * The repository's tags, each with the commit it points at.
     *
     * @return list<array{name: string, sha: string}>
     */
    public function tags(string $owner, string $repo): array;

    /**
     * GitHub's zip archive of the tag, as Composer will download it.
     */
    public function archive(string $owner, string $repo, string $tag): string;

    /**
     * When the release was published (or, with no GitHub release, when its
     * commit was made), as an ISO 8601 time in UTC.
     */
    public function releaseDate(string $owner, string $repo, string $tag, string $sha): string;

    /**
     * The repository's stars and open issues.
     *
     * @return array{stars: int, issues: int}
     */
    public function figures(string $owner, string $repo): array;
}
