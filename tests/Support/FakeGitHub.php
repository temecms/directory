<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Support;

use RuntimeException;
use Teme\Directory\GitHub\GitHub;

/**
 * GitHub as the poller sees it, held in memory.
 */
final class FakeGitHub implements GitHub
{
    /** @var array<string, string> */
    public array $bytes = [];

    /** @var list<string> */
    public array $tagNames = [];

    /** @var list<string> */
    public array $downloads = [];

    /** @var array{stars: int, issues: int} */
    public array $figures = ['stars' => 3, 'issues' => 0];

    public bool $failing = false;

    /**
     * @param  array<string, string>  $files
     */
    public function release(string $tag, array $files): void
    {
        $this->tag($tag);
        $this->bytes[$tag] = PackageZip::bytes($files);
    }

    public function tag(string $tag): void
    {
        $this->tagNames[] = $tag;
    }

    public function tags(string $owner, string $repo): array
    {
        $this->fail();

        return array_map(static fn (string $name): array => ['name' => $name, 'sha' => sha1($name)], array_reverse($this->tagNames));
    }

    public function archive(string $owner, string $repo, string $tag): string
    {
        $this->fail();
        $this->downloads[] = $tag;

        return $this->bytes[$tag];
    }

    public function releaseDate(string $owner, string $repo, string $tag, string $sha): string
    {
        return '2026-11-01T10:00:00Z';
    }

    public function figures(string $owner, string $repo): array
    {
        $this->fail();

        return $this->figures;
    }

    private function fail(): void
    {
        if ($this->failing) {
            throw new RuntimeException('GitHub did not answer.');
        }
    }
}
