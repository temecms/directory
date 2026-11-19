<?php

declare(strict_types=1);

namespace Teme\Directory\Index;

use Composer\Semver\VersionParser;
use Teme\Directory\GitHub\GitHub;
use Teme\Directory\Listings\Listing;
use Teme\Directory\Packages\Archive;
use Teme\Directory\Packages\PackageInspector;
use Teme\Directory\Teme\TemeVersions;
use Throwable;

/**
 * Reads every listed repository's tags and takes in each new release once:
 * downloads its archive, records its checksums, reads and checks it, and holds
 * it when a check fails or it asks for more than the last listed release did.
 * A release that passes waits for the install check.
 */
final class Poller
{
    private const COMPOSER_KEYS = [
        'name', 'description', 'keywords', 'homepage', 'license', 'authors', 'support', 'funding', 'type',
        'require', 'conflict', 'provide', 'replace', 'suggest', 'autoload', 'extra', 'bin',
    ];

    /** @var list<array{name: string, version: string, tag: string, kind: string, code: string|null, archive: string, teme: list<string>}> */
    private array $pending = [];

    /** @var array<string, string> */
    private array $assets = [];

    public function __construct(
        private readonly GitHub $github,
        private readonly PackageInspector $inspector,
        private readonly string $workDirectory,
    ) {}

    /**
     * @param  list<Listing>  $listings
     */
    public function poll(array $listings, State $state, TemeVersions $teme): PollReport
    {
        $this->pending = [];
        $this->assets = [];
        $problems = [];

        $listed = array_map(static fn (Listing $listing): string => $listing->name, $listings);
        foreach (array_keys($state->packages()) as $name) {
            if (! in_array($name, $listed, true)) {
                $state->remove($name);
            }
        }

        foreach ($listings as $listing) {
            $package = $this->withListing($state->package($listing->name), $listing);
            try {
                $package = $this->pollPackage($package, $listing, $teme);
            } catch (Throwable $exception) {
                $problems[] = "{$listing->name}: {$exception->getMessage()}";
            }
            $state->put($package);
        }

        return new PollReport($this->pending, $this->assets, $problems);
    }

    /**
     * @param  array<string, mixed>|null  $package
     * @return array<string, mixed>
     */
    private function withListing(?array $package, Listing $listing): array
    {
        return array_replace([
            'name' => $listing->name,
            'listed' => null,
            'stars' => 0,
            'issues' => 0,
            'seen' => [],
            'releases' => [],
        ], $package ?? [], [
            'kind' => $listing->kind,
            'categories' => $listing->categories,
            'picked' => $listing->picked,
            'repository' => $listing->repository(),
            'owner' => $listing->owner,
        ]);
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function pollPackage(array $package, Listing $listing, TemeVersions $teme): array
    {
        $firstSight = $package['seen'] === [] && $package['releases'] === [];
        $tags = $this->github->tags($listing->owner, $listing->repo);
        $package = array_replace($package, $this->github->figures($listing->owner, $listing->repo));

        foreach ($package['releases'] as $version => $release) {
            if ($release['status'] === 'pending') {
                $package['releases'][$version] = $this->recheck($release, $listing);
            }
        }

        $seen = $package['seen'];
        $new = [];
        $parser = new VersionParser;
        foreach ($tags as $tag) {
            if (in_array($tag['name'], $seen, true)) {
                continue;
            }
            $seen[] = $tag['name'];
            try {
                $new[$tag['name']] = ['sha' => $tag['sha'], 'normalized' => $parser->normalize($tag['name'])];
            } catch (Throwable) {
                continue;
            }
        }
        sort($seen);
        $package['seen'] = $seen;

        uasort($new, static fn (array $a, array $b): int => version_compare($a['normalized'], $b['normalized']));
        if ($firstSight) {
            $new = array_slice($new, -1, 1, true);
        }

        foreach ($new as $tag => $found) {
            $version = (string) preg_replace('/^v(?=\d)/i', '', (string) $tag);
            if (isset($package['releases'][$version])) {
                continue;
            }
            $package['releases'][$version] = $this->take($package, $listing, $version, (string) $tag, $found['sha'], $teme);
        }

        uksort($package['releases'], 'version_compare');

        return $package;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function take(array $package, Listing $listing, string $version, string $tag, string $sha, TemeVersions $teme): array
    {
        $bytes = $this->github->archive($listing->owner, $listing->repo, $tag);
        $release = [
            'version' => $version,
            'tag' => $tag,
            'reference' => $sha,
            'date' => $this->github->releaseDate($listing->owner, $listing->repo, $tag, $sha),
            'dist' => [
                'url' => "https://api.github.com/repos/{$listing->owner}/{$listing->repo}/zipball/".rawurlencode($tag),
                'sha256' => hash('sha256', $bytes),
                'sha1' => sha1($bytes),
            ],
        ];

        try {
            $archive = Archive::fromBytes($bytes);
        } catch (Throwable) {
            return $release + ['status' => 'held', 'hold' => ['reasons' => ['The release archive could not be read as a zip file.'], 'issue' => null]];
        }

        $inspection = $this->inspector->inspect($archive, $listing, $version, $tag, $teme);
        $this->assets += $inspection->assets;
        $release += [
            'works_with' => $inspection->worksWith,
            'teme_range' => $inspection->temeRange,
            'teme_tags' => $inspection->temeTags,
            'code' => $inspection->code,
            'capabilities' => $inspection->capabilities,
            'hosts' => $inspection->hosts,
            'composer' => array_intersect_key($inspection->composer, array_flip(self::COMPOSER_KEYS)),
            'page' => $inspection->page,
        ];

        $reasons = $inspection->failures();
        if ($reasons === []) {
            $reasons = $this->widening($package, $inspection->capabilities, $inspection->hosts);
        }
        if ($reasons !== []) {
            return $release + ['status' => 'held', 'hold' => ['reasons' => $reasons, 'issue' => null]];
        }

        $release['status'] = 'pending';
        $this->offer($release, $listing, $bytes);

        return $release;
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    private function recheck(array $release, Listing $listing): array
    {
        $bytes = $this->github->archive($listing->owner, $listing->repo, $release['tag']);
        if (hash('sha256', $bytes) !== $release['dist']['sha256']) {
            return array_replace($release, ['status' => 'held', 'hold' => ['reasons' => ['The release archive changed after it was first read.'], 'issue' => null]]);
        }
        $this->offer($release, $listing, $bytes);

        return $release;
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function offer(array $release, Listing $listing, string $bytes): void
    {
        $path = $this->workDirectory.'/archives/'.str_replace('/', '-', $listing->name).'-'.$release['version'].'.zip';
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $bytes);

        $this->pending[] = [
            'name' => $listing->name,
            'version' => $release['version'],
            'tag' => $release['tag'],
            'kind' => $listing->kind,
            'code' => $release['code'] ?? null,
            'archive' => $path,
            'teme' => $release['teme_tags'] ?? [],
        ];
    }

    /**
     * What the release asks for beyond the newest listed release, in plain
     * words, or nothing.
     *
     * @param  array<string, mixed>  $package
     * @param  list<string>  $capabilities
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private function widening(array $package, array $capabilities, array $hosts): array
    {
        $listed = array_filter($package['releases'], static fn (array $release): bool => $release['status'] === 'listed');
        if ($listed === []) {
            return [];
        }
        uksort($listed, 'version_compare');
        $last = end($listed);

        $more = [
            ...array_diff($capabilities, $last['capabilities'] ?? []),
            ...array_diff($hosts, $last['hosts'] ?? []),
        ];

        return $more === [] ? [] : ["It asks for more than {$last['version']} did: ".implode(', ', $more).'.'];
    }
}
