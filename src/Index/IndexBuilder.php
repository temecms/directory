<?php

declare(strict_types=1);

namespace Teme\Directory\Index;

use Composer\Semver\VersionParser;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Teme\Directory\Json;

/**
 * Builds the files the directory publishes from the package records:
 * index.json, a detail file per package and the static Composer repository.
 * Only listed releases are offered to Composer; held ones are shown as held.
 */
final class IndexBuilder
{
    public const FORMAT = 1;

    public const LIFETIME = 'P14D';

    public const COMPOSER_PATH = 'composer/packages.json';

    /**
     * @param  list<array<string, mixed>>  $packages  Package records as the state keeps them.
     */
    public function build(array $packages, int $sequence, DateTimeImmutable $now): Build
    {
        usort($packages, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $now = $now->setTimezone(new DateTimeZone('UTC'));

        $entries = [];
        $details = [];
        $composer = [];
        foreach ($packages as $package) {
            $latest = Releases::latest($package['releases']);
            if ($latest === null) {
                continue;
            }
            $detail = $this->detail($package, $latest);
            $details["packages/{$package['name']}.json"] = $detail;
            $entries[] = $this->entry($detail);
            $composer[$package['name']] = $this->composerVersions($package);
        }

        $files = [self::COMPOSER_PATH => Json::encode(['packages' => $composer === [] ? new \stdClass : $composer])];
        foreach ($details as $path => $detail) {
            $files[$path] = Json::encode($detail);
        }

        $index = [
            'format' => self::FORMAT,
            'sequence' => $sequence,
            'generated' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires' => $now->add(new DateInterval(self::LIFETIME))->format('Y-m-d\TH:i:s\Z'),
            'composer' => ['path' => self::COMPOSER_PATH, 'sha256' => hash('sha256', $files[self::COMPOSER_PATH])],
            'packages' => array_map(static fn (array $entry): array => $entry + ['detail_sha256' => hash('sha256', $files[$entry['detail']])], $entries),
        ];
        $files = ['index.json' => Json::encode($index)] + $files;

        $withoutFigures = array_map(static function (array $detail): array {
            unset($detail['stars'], $detail['issues']);

            return $detail;
        }, $details);
        $figures = array_map(static fn (array $detail): array => [$detail['name'], $detail['stars'], $detail['issues']], array_values($details));

        return new Build(
            $files,
            hash('sha256', Json::encode([$entries, $withoutFigures, $files[self::COMPOSER_PATH]])),
            hash('sha256', Json::encode($figures)),
        );
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function detail(array $package, string $latest): array
    {
        $release = $package['releases'][$latest];
        $page = $release['page'];
        $releases = array_filter($package['releases'], static fn (array $release): bool => in_array($release['status'], ['listed', 'held'], true));
        uksort($releases, static fn (string|int $a, string|int $b): int => version_compare((string) $b, (string) $a));

        return [
            'name' => $package['name'],
            'kind' => $package['kind'],
            'categories' => $package['categories'],
            'picked' => $package['picked'],
            'repository' => $package['repository'],
            'label' => $page['label'],
            'summary' => $page['summary'],
            'author' => ['name' => $page['author']['name'], 'github' => $package['owner']],
            'latest' => $latest,
            'updated' => substr($release['date'], 0, 10),
            'listed' => $package['listed'],
            'works_with' => $release['works_with'],
            'icon' => $page['icon'],
            'readme' => $page['readme'],
            'changelog' => $page['changelog'],
            'screenshots' => $page['screenshots'],
            'capabilities' => $page['capabilities'],
            'links' => $page['links'],
            'license' => $page['license'],
            'php' => $page['php'],
            'stars' => $package['stars'],
            'issues' => $package['issues'],
            'releases' => array_values(array_map(static fn (array $release): array => [
                'version' => $release['version'],
                'date' => substr($release['date'], 0, 10),
                'works_with' => $release['works_with'] ?? [],
                'dist' => ['url' => $release['dist']['url'], 'sha256' => $release['dist']['sha256']],
                'held' => $release['status'] === 'held',
            ], $releases)),
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function entry(array $detail): array
    {
        return [
            'name' => $detail['name'],
            'kind' => $detail['kind'],
            'categories' => $detail['categories'],
            'picked' => $detail['picked'],
            'label' => $detail['label'],
            'summary' => $detail['summary'],
            'author' => $detail['author'],
            'latest' => $detail['latest'],
            'updated' => $detail['updated'],
            'listed' => $detail['listed'],
            'works_with' => $detail['works_with'],
            'icon' => $detail['icon'],
            'detail' => "packages/{$detail['name']}.json",
        ];
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, array<string, mixed>>
     */
    private function composerVersions(array $package): array
    {
        $parser = new VersionParser;
        $listed = array_filter($package['releases'], static fn (array $release): bool => $release['status'] === 'listed');
        uksort($listed, static fn (string|int $a, string|int $b): int => version_compare((string) $a, (string) $b));

        $versions = [];
        foreach ($listed as $version => $release) {
            $versions[(string) $version] = array_replace($release['composer'], [
                'name' => $package['name'],
                'version' => (string) $version,
                'version_normalized' => $parser->normalize((string) $version),
                'dist' => [
                    'type' => 'zip',
                    'url' => $release['dist']['url'],
                    'reference' => $release['reference'],
                    'shasum' => $release['dist']['sha1'],
                ],
                'source' => [
                    'type' => 'git',
                    'url' => $package['repository'].'.git',
                    'reference' => $release['reference'],
                ],
                'time' => $release['date'],
            ]);
        }

        return $versions;
    }
}
