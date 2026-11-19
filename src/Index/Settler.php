<?php

declare(strict_types=1);

namespace Teme\Directory\Index;

use DateTimeImmutable;

/**
 * Decides what becomes of each waiting release: listed when it installed on
 * Teme, held when it did not, and listed when a maintainer closes its hold's
 * issue as completed. Also names the held releases that still need an issue.
 */
final class Settler
{
    /**
     * @param  array<string, array{passed: bool, words: list<string>}>  $results  "vendor/name@version", then how the install check went.
     * @param  array<int, string>  $closedIssues  Issue number, then why it was closed: completed or not_planned.
     */
    public function settle(State $state, array $results, array $closedIssues, DateTimeImmutable $now): void
    {
        foreach ($state->packages() as $name => $package) {
            $listedOne = false;
            foreach ($package['releases'] as $version => $release) {
                $result = $results["{$name}@{$version}"] ?? null;
                if ($release['status'] === 'pending' && $result !== null) {
                    $release['status'] = $result['passed'] ? 'listed' : 'held';
                    if (! $result['passed']) {
                        $release['hold'] = ['reasons' => $result['words'], 'issue' => null];
                    }
                }
                $issue = $release['hold']['issue'] ?? null;
                if ($release['status'] === 'held' && $issue !== null && ($closedIssues[$issue] ?? null) === 'completed') {
                    $release['status'] = 'listed';
                    $release['hold']['released'] = $now->format('Y-m-d');
                }
                $listedOne = $listedOne || $release['status'] === 'listed';
                $package['releases'][$version] = $release;
            }

            if ($listedOne && $package['listed'] === null) {
                $package['listed'] = $now->format('Y-m-d');
            }
            $state->put($this->trim($package));
        }
    }

    /**
     * The held releases that have no issue yet, each with an issue's title and body.
     *
     * @return list<array{name: string, version: string, title: string, body: string}>
     */
    public function holdsWithoutIssue(State $state): array
    {
        $holds = [];
        foreach ($state->packages() as $name => $package) {
            foreach ($package['releases'] as $version => $release) {
                if ($release['status'] !== 'held' || ($release['hold']['issue'] ?? null) !== null) {
                    continue;
                }
                $reasons = implode("\n", array_map(static fn (string $reason): string => "- {$reason}", $release['hold']['reasons'] ?? []));
                $holds[] = [
                    'name' => $name,
                    'version' => (string) $version,
                    'title' => "Held: {$name} {$version}",
                    'body' => "The release [{$version}]({$package['repository']}/releases/tag/".rawurlencode($release['tag']).") of {$name} is held and not offered to sites.\n\n"
                        ."Why:\n\n{$reasons}\n\n"
                        ."Close this issue as completed to list the release. Close it as not planned to keep it out of the directory.\n",
                ];
            }
        }

        return $holds;
    }

    public function recordIssue(State $state, string $name, string $version, int $issue): void
    {
        $package = $state->package($name);
        if ($package === null || ! isset($package['releases'][$version])) {
            return;
        }
        $package['releases'][$version]['hold']['issue'] = $issue;
        $state->put($package);
    }

    /**
     * Keeps the README and changelog only on the latest listed release, the
     * one the page shows.
     *
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function trim(array $package): array
    {
        $latest = Releases::latest($package['releases']);

        foreach ($package['releases'] as $version => $release) {
            if ($release['status'] === 'listed' && (string) $version !== $latest && isset($release['page'])) {
                $package['releases'][$version]['page']['readme'] = null;
                $package['releases'][$version]['page']['changelog'] = null;
            }
        }

        return $package;
    }
}
