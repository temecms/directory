<?php

declare(strict_types=1);

namespace Teme\Directory\Index;

use Composer\Semver\VersionParser;

/**
 * Picks among a package's releases.
 */
final class Releases
{
    /**
     * The version a page shows as the latest: the highest listed stable
     * release, or the highest listed release when none is stable.
     *
     * @param  array<string, array<string, mixed>>  $releases
     */
    public static function latest(array $releases): ?string
    {
        $listed = array_map('strval', array_keys(array_filter($releases, static fn (array $release): bool => $release['status'] === 'listed')));
        if ($listed === []) {
            return null;
        }
        usort($listed, 'version_compare');
        $stable = array_values(array_filter($listed, static fn (string $version): bool => VersionParser::parseStability($version) === 'stable'));

        return end($stable) ?: end($listed);
    }
}
