<?php

declare(strict_types=1);

namespace Teme\Directory\Checks;

use Teme\Directory\Json;

/**
 * What the install checks reported: one result per release and Teme version,
 * each written by scripts/install-check.
 */
final class InstallResults
{
    /**
     * @param  list<array{name: string, version: string, teme: string, passed: bool, checks: list<array{passed: bool, words: string, skipped?: bool}>, log: string}>  $results
     */
    public function __construct(private readonly array $results) {}

    public static function fromDirectory(string $directory): self
    {
        $results = [];
        foreach (glob($directory.'/*.json') ?: [] as $file) {
            $result = Json::decode((string) file_get_contents($file));
            if (is_string($result['name'] ?? null) && is_string($result['version'] ?? null) && is_string($result['teme'] ?? null)) {
                $results[] = [
                    'name' => $result['name'],
                    'version' => $result['version'],
                    'teme' => $result['teme'],
                    'passed' => ($result['passed'] ?? false) === true,
                    'checks' => array_values(array_map(
                        static fn (array $check): array => ['passed' => ($check['passed'] ?? false) === true, 'words' => (string) ($check['words'] ?? '')] + (($check['skipped'] ?? false) === true ? ['skipped' => true] : []),
                        array_filter((array) ($result['checks'] ?? []), 'is_array'),
                    )),
                    'log' => is_string($result['log'] ?? null) ? $result['log'] : '',
                ];
            }
        }

        return new self($results);
    }

    /**
     * The result for one release on one Teme version, or null when none came.
     *
     * @return array{name: string, version: string, teme: string, passed: bool, checks: list<array{passed: bool, words: string, skipped?: bool}>, log: string}|null
     */
    public function for(string $name, string $version, string $teme): ?array
    {
        foreach ($this->results as $result) {
            if ($result['name'] === $name && $result['version'] === $version && $result['teme'] === $teme) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Whether the release installed on every Teme version it was checked on,
     * with what failed; null while any version has not answered.
     *
     * @param  list<string>  $temeTags
     * @return array{passed: bool, words: list<string>}|null
     */
    public function settled(string $name, string $version, array $temeTags): ?array
    {
        $words = [];
        foreach ($temeTags as $teme) {
            $result = $this->for($name, $version, $teme);
            if ($result === null) {
                return null;
            }
            foreach ($result['checks'] as $check) {
                if (! $check['passed']) {
                    $words[] = $check['words'];
                }
            }
            if (! $result['passed'] && $result['checks'] === []) {
                $words[] = "It did not install on Teme {$teme}.";
            }
        }

        return ['passed' => $words === [], 'words' => $words];
    }
}
