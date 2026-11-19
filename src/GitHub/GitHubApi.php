<?php

declare(strict_types=1);

namespace Teme\Directory\GitHub;

use RuntimeException;
use Teme\Directory\Json;

/**
 * GitHub's REST API. Lists are asked for with the last answer's ETag, so a
 * poll that finds nothing new costs no rate limit.
 */
final class GitHubApi implements GitHub
{
    private const API = 'https://api.github.com';

    /** @var array<string, array{etag: string, body: string}> */
    private array $cache = [];

    public function __construct(private readonly ?string $token, private readonly ?string $cacheFile = null)
    {
        if ($cacheFile !== null && is_file($cacheFile)) {
            /** @var array<string, array{etag: string, body: string}> $cache */
            $cache = Json::decode((string) file_get_contents($cacheFile)) ?? [];
            $this->cache = $cache;
        }
    }

    public function tags(string $owner, string $repo): array
    {
        $tags = [];
        for ($page = 1; $page <= 5; $page++) {
            $batch = $this->json("/repos/{$owner}/{$repo}/tags?per_page=100&page={$page}");
            foreach ($batch as $tag) {
                $tags[] = ['name' => (string) $tag['name'], 'sha' => (string) $tag['commit']['sha']];
            }
            if (count($batch) < 100) {
                break;
            }
        }

        return $tags;
    }

    public function archive(string $owner, string $repo, string $tag): string
    {
        $answer = Http::request('GET', self::API."/repos/{$owner}/{$repo}/zipball/".rawurlencode($tag), $this->headers(), maxBytes: 100 * 1024 * 1024, timeout: 300);
        if ($answer['status'] !== 200) {
            throw new RuntimeException("GitHub answered {$answer['status']} for the archive of {$tag}.");
        }

        return $answer['body'];
    }

    public function releaseDate(string $owner, string $repo, string $tag, string $sha): string
    {
        $release = $this->json("/repos/{$owner}/{$repo}/releases/tags/".rawurlencode($tag), allowMissing: true);
        $date = $release['published_at'] ?? null;
        if (! is_string($date)) {
            $commit = $this->json("/repos/{$owner}/{$repo}/commits/{$sha}");
            $date = (string) ($commit['commit']['committer']['date'] ?? '');
        }

        return gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($date));
    }

    public function figures(string $owner, string $repo): array
    {
        $repository = $this->json("/repos/{$owner}/{$repo}");
        if (($repository['private'] ?? false) === true) {
            throw new RuntimeException('The repository is private.');
        }

        return ['stars' => (int) ($repository['stargazers_count'] ?? 0), 'issues' => (int) ($repository['open_issues_count'] ?? 0)];
    }

    /**
     * Teme's own release tags.
     *
     * @return list<string>
     */
    public function temeTags(string $repository = 'temecms/teme'): array
    {
        [$owner, $repo] = explode('/', $repository, 2);

        return array_column($this->tags($owner, $repo), 'name');
    }

    public function saveCache(): void
    {
        if ($this->cacheFile !== null) {
            @mkdir(dirname($this->cacheFile), 0777, true);
            file_put_contents($this->cacheFile, Json::encode($this->cache === [] ? new \stdClass : $this->cache));
        }
    }

    /**
     * @return array<mixed>
     */
    private function json(string $path, bool $allowMissing = false): array
    {
        $headers = $this->headers();
        $cached = $this->cache[$path] ?? null;
        if ($cached !== null) {
            $headers[] = 'If-None-Match: '.$cached['etag'];
        }

        $answer = Http::request('GET', self::API.$path, $headers, maxBytes: 8 * 1024 * 1024);
        if ($answer['status'] === 304 && $cached !== null) {
            return Json::decode($cached['body']) ?? [];
        }
        if ($answer['status'] === 404 && $allowMissing) {
            return [];
        }
        if ($answer['status'] !== 200) {
            throw new RuntimeException("GitHub answered {$answer['status']} for {$path}.");
        }

        $etag = $answer['headers']['etag'] ?? null;
        if ($etag !== null && ! $allowMissing) {
            $this->cache[$path] = ['etag' => $etag, 'body' => $answer['body']];
        }

        return Json::decode($answer['body']) ?? [];
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
        if ($this->token !== null && $this->token !== '') {
            $headers[] = 'Authorization: Bearer '.$this->token;
        }

        return $headers;
    }
}
