<?php

declare(strict_types=1);

namespace Teme\Directory\Checks;

/**
 * The comment the checks leave on a pull request, in plain words.
 */
final class Comment
{
    public const MARKER = '<!-- directory-checks -->';

    private function __construct(
        public readonly string $markdown,
        public readonly bool $passed,
    ) {}

    /**
     * @param  array{listings: list<array{path: string, name: string|null, status: string, version: string|null, problems: list<string>, checks: list<array{passed: bool, words: string}>, teme: list<string>}>, other: list<string>}  $report
     */
    public static function forPullRequest(array $report, InstallResults $results): self
    {
        $out = self::MARKER."\n## Directory checks\n\n";
        $passed = true;

        if ($report['listings'] === []) {
            $out .= "This pull request changes no listing.\n\n";
        }

        foreach ($report['listings'] as $listing) {
            if ($listing['status'] === 'removed') {
                $out .= "### {$listing['path']}: removed\n\nThe package leaves the directory once this is merged.\n\n";

                continue;
            }
            if ($listing['status'] !== 'checked') {
                $passed = false;
                $out .= "### {$listing['path']}: the listing needs changes\n\n".self::lines(array_map(
                    static fn (string $problem): array => ['passed' => false, 'words' => $problem],
                    $listing['problems'],
                ))."\n";

                continue;
            }

            $lines = $listing['checks'];
            $logs = '';
            foreach ($listing['teme'] as $teme) {
                $result = $results->for((string) $listing['name'], (string) $listing['version'], $teme);
                if ($result === null) {
                    $lines[] = ['passed' => false, 'words' => "The install on Teme {$teme} did not finish. A maintainer can run the checks again."];

                    continue;
                }
                $lines = [...$lines, ...$result['checks']];
                if (! $result['passed'] && trim($result['log']) !== '') {
                    $logs .= "<details><summary>What Teme {$teme} said</summary>\n\n```text\n".str_replace('```', "'''", self::tail($result['log']))."\n```\n\n</details>\n\n";
                }
            }

            $failures = count(array_filter($lines, static fn (array $line): bool => ! $line['passed']));
            $passed = $passed && $failures === 0;
            $heading = $failures === 0 ? 'every check passed' : ($failures === 1 ? '1 check needs attention' : "{$failures} checks need attention");
            $out .= "### {$listing['name']} {$listing['version']}: {$heading}\n\n".self::lines($lines)."\n".$logs;
        }

        if ($report['other'] !== []) {
            $out .= 'This pull request also changes files outside listings/: '.implode(', ', $report['other']).". A maintainer looks at those by hand.\n\n";
        }

        $out .= $passed
            ? "A maintainer reviews the listing next.\n"
            : "Push a fix to this pull request, or tag a new release of the package, and the checks run again.\n";

        return new self($out, $passed);
    }

    /**
     * @param  list<array{passed: bool, words: string, skipped?: bool}>  $lines  A skipped check was not run and holds nothing.
     */
    private static function lines(array $lines): string
    {
        return implode('', array_map(static fn (array $line): string => '- '.(($line['skipped'] ?? false) === true ? '➖' : ($line['passed'] ? '✅' : '❌')).' '.$line['words']."\n", $lines));
    }

    private static function tail(string $log): string
    {
        $lines = explode("\n", trim($log));

        return implode("\n", array_slice($lines, -40));
    }
}
