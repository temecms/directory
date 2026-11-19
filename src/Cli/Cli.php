<?php

declare(strict_types=1);

namespace Teme\Directory\Cli;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Teme\Directory\Checks\Comment;
use Teme\Directory\Checks\InstallResults;
use Teme\Directory\GitHub\GitHubApi;
use Teme\Directory\Index\IndexBuilder;
use Teme\Directory\Index\IndexVerifier;
use Teme\Directory\Index\Poller;
use Teme\Directory\Index\Publication;
use Teme\Directory\Index\Releases;
use Teme\Directory\Index\Settler;
use Teme\Directory\Index\SiteWriter;
use Teme\Directory\Index\State;
use Teme\Directory\Json;
use Teme\Directory\Listings\Categories;
use Teme\Directory\Listings\Listing;
use Teme\Directory\Listings\Listings;
use Teme\Directory\Listings\ListingValidator;
use Teme\Directory\Packages\Archive;
use Teme\Directory\Packages\HttpImageFetcher;
use Teme\Directory\Packages\PackageInspector;
use Teme\Directory\Review\Advisor;
use Teme\Directory\Review\HttpTransport;
use Teme\Directory\Review\Material;
use Teme\Directory\Signing\InvalidKey;
use Teme\Directory\Signing\Signer;
use Teme\Directory\Signing\Verifier;
use Teme\Directory\Teme\TemeVersions;
use Throwable;

/**
 * The directory's command line, run by its GitHub Actions: validate, poll,
 * settle, record-issue, build, verify, pr-inspect, pr-report and advise.
 */
final class Cli
{
    private const DEFAULT_BASE = 'https://temecms.github.io/directory/';

    private const MATERIAL_LIMIT = 300 * 1024;

    /** @var array<string, string> */
    private array $options = [];

    /** @var list<string> */
    private array $arguments = [];

    public function __construct(private readonly string $root) {}

    /**
     * @param  list<string>  $argv
     */
    public function run(array $argv): int
    {
        $command = array_shift($argv) ?? 'help';
        $this->parse($argv);

        try {
            return match ($command) {
                'validate' => $this->validate(),
                'poll' => $this->poll(),
                'settle' => $this->settle(),
                'record-issue' => $this->recordIssue(),
                'build' => $this->build(),
                'verify' => $this->verify(),
                'pr-inspect' => $this->prInspect(),
                'pr-report' => $this->prReport(),
                'advise' => $this->advise(),
                default => $this->help(),
            };
        } catch (InvalidKey $exception) {
            return $this->error($exception->getMessage());
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage());
        }
    }

    private function validate(): int
    {
        $loaded = Listings::load($this->root, $this->categories());
        foreach ($loaded['problems'] as $problem) {
            $this->say("::error::{$problem}");
        }
        $this->say(count($loaded['listings']).' listings are valid.');

        return $loaded['problems'] === [] ? 0 : 1;
    }

    private function poll(): int
    {
        $state = State::load($this->option('state'));
        $work = $this->option('work');
        $github = $this->github($this->option('state').'/http-cache.json');
        $loaded = Listings::load($this->root, $this->categories());
        foreach ($loaded['problems'] as $problem) {
            $this->say("::warning::{$problem}");
        }

        $teme = TemeVersions::fromTags($github->temeTags($this->temeRepository()));
        $report = (new Poller($github, $this->inspector(), $work))->poll($loaded['listings'], $state, $teme);
        (new SiteWriter($this->option('site')))->assets($report->assets);
        foreach ($report->problems as $problem) {
            $this->say("::warning::{$problem}");
        }

        @mkdir($work, 0777, true);
        file_put_contents($work.'/pending.json', Json::encode($report->pending));
        $state->save($this->option('state'));
        $github->saveCache();
        $this->say(count($report->pending).' releases wait for the install check.');

        return 0;
    }

    private function settle(): int
    {
        $state = State::load($this->option('state'));
        $work = $this->option('work');
        $results = InstallResults::fromDirectory($work.'/results');

        $settled = [];
        foreach (Json::decode((string) @file_get_contents($work.'/pending.json')) ?? [] as $pending) {
            $result = $results->settled($pending['name'], $pending['version'], $pending['teme']);
            if ($result !== null) {
                $settled["{$pending['name']}@{$pending['version']}"] = $result;
            }
        }

        $closed = [];
        foreach (Json::decode((string) @file_get_contents($this->option('closed', $work.'/closed.json'))) ?? [] as $issue) {
            if (is_int($issue['number'] ?? null) && is_string($issue['stateReason'] ?? null)) {
                $closed[$issue['number']] = strtolower($issue['stateReason']);
            }
        }

        $settler = new Settler;
        $settler->settle($state, $settled, $closed, $this->now());
        $holds = $settler->holdsWithoutIssue($state);
        foreach ($holds as $index => $hold) {
            file_put_contents("{$work}/hold-{$index}.md", $hold['body']);
            $holds[$index]['body_file'] = "{$work}/hold-{$index}.md";
        }
        file_put_contents($work.'/holds.json', Json::encode($holds));
        $state->save($this->option('state'));
        $this->say(count($settled).' releases settled; '.count($holds).' holds need an issue.');

        return 0;
    }

    private function recordIssue(): int
    {
        [$name, $version, $issue] = $this->arguments + [null, null, null];
        if (! is_string($name) || ! is_string($version) || ! ctype_digit((string) $issue)) {
            return $this->error('Give the package, the version and the issue number.');
        }
        $state = State::load($this->option('state'));
        (new Settler)->recordIssue($state, $name, $version, (int) $issue);
        $state->save($this->option('state'));

        return 0;
    }

    private function build(): int
    {
        $key = (string) getenv('DIRECTORY_SIGNING_KEY');
        if (trim($key) === '') {
            return $this->error('DIRECTORY_SIGNING_KEY is not set.');
        }
        $signer = new Signer($key);
        $expected = trim((string) getenv('DIRECTORY_PUBLIC_KEY'));
        if ($expected !== '' && ! in_array($signer->publicKey(), array_map('trim', explode(',', $expected)), true)) {
            return $this->error('The signing key does not match DIRECTORY_PUBLIC_KEY.');
        }

        $state = State::load($this->option('state'));
        $now = $this->now();
        $packages = array_values($state->packages());
        $builder = new IndexBuilder;

        $probe = $builder->build($packages, $state->sequence(), $now);
        if (! isset($this->options['force']) && ! Publication::due($state->published(), $probe->contentHash, $probe->figuresHash, $now)) {
            $this->say('Nothing new to publish.');

            return 0;
        }

        $sequence = $state->sequence() + 1;
        $build = $builder->build($packages, $sequence, $now);
        $signature = $signer->sign($build->files['index.json']);
        $problems = (new IndexVerifier(new Verifier([$signer->publicKey()])))->verify(
            $build->files['index.json'],
            $signature,
            static fn (string $path): ?string => $build->files[$path] ?? null,
            $state->sequence(),
            $now,
        );
        if ($problems !== []) {
            return $this->error('The new index does not verify: '.implode(' ', $problems));
        }

        (new SiteWriter($this->option('site')))->write($build->files, $signature);
        $state->recordPublication($sequence, $build->contentHash, $build->figuresHash, $now->format('Y-m-d\TH:i:s\Z'));
        $state->save($this->option('state'));
        $this->say("Published index {$sequence}.");

        return 0;
    }

    private function verify(): int
    {
        $keys = array_values(array_filter(array_map('trim', explode(',', $this->options['public-key'] ?? (string) getenv('DIRECTORY_PUBLIC_KEY')))));
        if ($keys === []) {
            return $this->error('Give the public key with --public-key or DIRECTORY_PUBLIC_KEY.');
        }
        $site = $this->option('site');
        $read = static function (string $path) use ($site): ?string {
            $file = $site.'/'.$path;

            return $path !== '' && ! str_contains($path, '..') && is_file($file) ? (string) file_get_contents($file) : null;
        };

        $index = $read('index.json');
        $signature = $read('index.json.sig');
        if ($index === null || $signature === null) {
            return $this->error('The site has no index.json and index.json.sig.');
        }

        $problems = (new IndexVerifier(new Verifier($keys)))->verify($index, $signature, $read, (int) ($this->options['last-sequence'] ?? 0), $this->now());
        foreach ($problems as $problem) {
            $this->say("::error::{$problem}");
        }
        if ($problems === []) {
            $this->say('The index verifies.');
        }

        return $problems === [] ? 0 : 1;
    }

    /**
     * Checks the listings a pull request adds or changes. The pull request's
     * files are read from --pr, a checkout of its merge commit, and never run.
     */
    private function prInspect(): int
    {
        $pr = $this->option('pr');
        $out = $this->option('out');
        @mkdir($out.'/archives', 0777, true);
        $byMaintainer = in_array(strtoupper($this->options['association'] ?? ''), ['OWNER', 'MEMBER', 'COLLABORATOR'], true);
        $validator = new ListingValidator($this->categories());
        $github = $this->github(null);
        $teme = null;

        $report = ['listings' => [], 'other' => []];
        $installs = [];
        foreach (explode("\n", trim((string) file_get_contents($this->option('changed')))) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = explode("\t", $line);
            $status = $parts[0][0] ?? 'M';
            $path = end($parts);
            if (! str_starts_with($path, 'listings/') || ! str_ends_with($path, '.json')) {
                $report['other'][] = $path;

                continue;
            }

            $entry = ['path' => $path, 'name' => null, 'status' => 'invalid', 'version' => null, 'problems' => [], 'checks' => [], 'teme' => []];
            if ($status === 'D') {
                $report['listings'][] = ['status' => 'removed'] + $entry;

                continue;
            }

            $contents = (string) @file_get_contents($pr.'/'.$path);
            $before = $this->gitShow($pr, 'HEAD^1:'.$path);
            $entry['problems'] = $validator->validate($path, $contents, $before, $byMaintainer);
            if ($entry['problems'] !== []) {
                $report['listings'][] = $entry;

                continue;
            }

            $listing = ListingValidator::read($path, $contents);
            $entry['name'] = $listing->name;
            try {
                $teme ??= TemeVersions::fromTags($github->temeTags($this->temeRepository()));
                $entry = $this->inspectListing($listing, $entry, $github, $teme, $out, $installs);
            } catch (Throwable $exception) {
                $entry['problems'][] = "The repository could not be read: {$exception->getMessage()}";
            }
            $report['listings'][] = $entry;
        }

        file_put_contents($out.'/report.json', Json::encode($report));
        file_put_contents($out.'/installs.json', json_encode($installs, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->say(count($report['listings']).' listings inspected; '.count($installs).' installs to check.');

        return 0;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<array<string, mixed>>  $installs
     * @return array<string, mixed>
     */
    private function inspectListing(Listing $listing, array $entry, GitHubApi $github, TemeVersions $teme, string $out, array &$installs): array
    {
        $github->figures($listing->owner, $listing->repo);
        $newest = null;
        foreach ($github->tags($listing->owner, $listing->repo) as $tag) {
            $normalized = $this->normalize($tag['name']);
            if ($normalized !== null && ($newest === null || version_compare($normalized, $newest[1]) > 0)) {
                $newest = [$tag['name'], $normalized];
            }
        }
        if ($newest === null) {
            $entry['status'] = 'invalid';
            $entry['problems'][] = 'The repository has no release yet. Tag a version, such as v1.0.0, and the checks run on it.';

            return $entry;
        }

        [$tag] = $newest;
        $version = (string) preg_replace('/^v(?=\d)/i', '', $tag);
        $bytes = $github->archive($listing->owner, $listing->repo, $tag);
        $archive = str_replace('/', '-', $listing->name).'-'.$version.'.zip';
        file_put_contents("{$out}/archives/{$archive}", $bytes);
        $inspection = $this->inspector()->inspect(Archive::fromBytes($bytes), $listing, $version, $tag, $teme);

        foreach ($inspection->temeTags as $index => $temeTag) {
            $installs[] = [
                'name' => $listing->name,
                'version' => $version,
                'kind' => $listing->kind,
                'code' => $inspection->code ?? '',
                'teme' => $temeTag,
                'archive' => $archive,
                'tests' => $index === 0 ? '1' : '0',
            ];
        }

        return array_replace($entry, [
            'status' => 'checked',
            'version' => $version,
            'checks' => $inspection->checks,
            'teme' => $inspection->temeTags,
        ]);
    }

    private function prReport(): int
    {
        $report = Json::decode((string) file_get_contents($this->option('report')));
        if ($report === null) {
            return $this->error('The inspection report is missing.');
        }
        /** @var array{listings: list<array{path: string, name: string|null, status: string, version: string|null, problems: list<string>, checks: list<array{passed: bool, words: string}>, teme: list<string>}>, other: list<string>} $report */
        $comment = Comment::forPullRequest($report, InstallResults::fromDirectory($this->option('results')));
        file_put_contents($this->option('out'), $comment->markdown);
        $this->say($comment->passed ? 'Every check passed.' : 'Some checks need attention.');

        return $comment->passed ? 0 : 1;
    }

    /**
     * Writes advisory notes for a release when a provider is configured; never
     * fails the run.
     */
    private function advise(): int
    {
        $advisor = Advisor::configured(new HttpTransport, (string) getenv('AI_REVIEW_KEY'), (string) getenv('AI_REVIEW_PROVIDER'), (string) getenv('AI_REVIEW_MODEL'));
        if ($advisor === null) {
            $this->say('Advisory notes are not configured; skipped.');

            return 0;
        }

        try {
            [$material, $what, $declared] = isset($this->options['archive'])
                ? $this->materialFromFile()
                : $this->materialFromState();
        } catch (Throwable $exception) {
            $this->say("Advisory notes skipped: {$exception->getMessage()}");

            return 0;
        }

        $notes = $advisor->advise($material, $what, $declared);
        if ($notes === null) {
            $this->say('No advisory notes came back.');

            return 0;
        }

        file_put_contents($this->option('out'), "<!-- directory-advice -->\n### Advisory notes on {$what}\n\nAutomated notes for the maintainers. They advise only and decide nothing.\n\n{$notes}\n");
        $this->say('Advisory notes written.');

        return 0;
    }

    /**
     * @return array{string, string, string}
     */
    private function materialFromFile(): array
    {
        $archive = Archive::fromBytes((string) file_get_contents($this->option('archive')));
        $manifest = Json::decode((string) ($archive->read('teme-plugin.json') ?? $archive->read('teme-theme.json') ?? '')) ?? [];

        return [Material::fromArchive($archive, self::MATERIAL_LIMIT), $this->option('what'), $this->declared($manifest['capabilities'] ?? [], $manifest['hosts'] ?? [])];
    }

    /**
     * @return array{string, string, string}
     */
    private function materialFromState(): array
    {
        $state = State::load($this->option('state'));
        $name = $this->option('name');
        $version = $this->option('version');
        $package = $state->package($name) ?? throw new RuntimeException("{$name} is not in the state.");
        $release = $package['releases'][$version] ?? throw new RuntimeException("{$name} {$version} is not in the state.");

        $github = $this->github(null);
        $new = $this->download($github, $package, $release);
        $listed = array_filter($package['releases'], static fn (array $other): bool => $other['status'] === 'listed' && version_compare((string) $other['version'], $version) < 0);
        $previous = Releases::latest($listed);
        $material = $previous === null
            ? Material::fromArchive($new, self::MATERIAL_LIMIT)
            : "Changes since {$previous}, the release sites have now:\n\n".Material::diff($this->download($github, $package, $package['releases'][$previous]), $new, self::MATERIAL_LIMIT);

        return [$material, "{$name} {$version}", $this->declared($release['capabilities'] ?? [], $release['hosts'] ?? [])];
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  array<string, mixed>  $release
     */
    private function download(GitHubApi $github, array $package, array $release): Archive
    {
        $bytes = $github->archive((string) $package['owner'], basename((string) $package['repository']), (string) $release['tag']);
        if (hash('sha256', $bytes) !== $release['dist']['sha256']) {
            throw new RuntimeException("the archive of {$release['tag']} changed after it was first read.");
        }

        return Archive::fromBytes($bytes);
    }

    private function declared(mixed $capabilities, mixed $hosts): string
    {
        $capabilities = is_array($capabilities) ? array_filter($capabilities, 'is_string') : [];
        $hosts = is_array($hosts) ? array_filter($hosts, 'is_string') : [];

        return $capabilities === [] ? 'none' : implode(', ', $capabilities).($hosts === [] ? '' : ' (hosts: '.implode(', ', $hosts).')');
    }

    private function help(): int
    {
        $this->say(<<<'HELP'
            Usage: bin/directory <command> [options]

              validate                                     Check every listing file
              poll --state DIR --site DIR --work DIR       Take in new releases
              settle --state DIR --work DIR                Settle install results and closed holds
              record-issue --state DIR NAME VERSION ISSUE  Remember a hold's issue
              build --state DIR --site DIR [--force]       Build and sign the index when due
              verify --site DIR [--public-key K1,K2]       Verify a built site
              pr-inspect --pr DIR --changed FILE --out DIR [--association A]
              pr-report --report FILE --results DIR --out FILE
              advise (--archive FILE --what TEXT | --state DIR --name N --version V) --out FILE
            HELP);

        return 0;
    }

    /**
     * @param  list<string>  $argv
     */
    private function parse(array $argv): void
    {
        $this->options = [];
        $this->arguments = [];
        for ($i = 0; $i < count($argv); $i++) {
            if (str_starts_with($argv[$i], '--')) {
                $name = substr($argv[$i], 2);
                if (str_contains($name, '=')) {
                    [$name, $value] = explode('=', $name, 2);
                    $this->options[$name] = $value;
                } elseif (isset($argv[$i + 1]) && ! str_starts_with($argv[$i + 1], '--')) {
                    $this->options[$name] = $argv[++$i];
                } else {
                    $this->options[$name] = '1';
                }
            } else {
                $this->arguments[] = $argv[$i];
            }
        }
    }

    private function option(string $name, ?string $default = null): string
    {
        $value = $this->options[$name] ?? $default;
        if ($value === null || $value === '') {
            throw new RuntimeException("Give --{$name}.");
        }

        return $value;
    }

    private function categories(): Categories
    {
        return Categories::fromFile($this->root.'/categories.json');
    }

    private function inspector(): PackageInspector
    {
        return new PackageInspector($this->root.'/schema', $this->baseUrl(), new HttpImageFetcher);
    }

    private function github(?string $cache): GitHubApi
    {
        $token = getenv('GITHUB_TOKEN');

        return new GitHubApi($token === false ? null : $token, $cache);
    }

    private function baseUrl(): string
    {
        $base = trim((string) getenv('DIRECTORY_BASE_URL'));

        return $base === '' ? self::DEFAULT_BASE : rtrim($base, '/').'/';
    }

    private function temeRepository(): string
    {
        $repository = trim((string) getenv('TEME_REPOSITORY'));

        return $repository === '' ? 'temecms/teme' : $repository;
    }

    private function normalize(string $tag): ?string
    {
        try {
            return (new \Composer\Semver\VersionParser)->normalize($tag);
        } catch (Throwable) {
            return null;
        }
    }

    private function gitShow(string $repository, string $object): ?string
    {
        $process = proc_open(['git', '-C', $repository, 'show', $object], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return null;
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? $output : null;
    }

    private function now(): DateTimeImmutable
    {
        $now = getenv('DIRECTORY_NOW');

        return new DateTimeImmutable($now === false || $now === '' ? 'now' : $now, new DateTimeZone('UTC'));
    }

    private function say(string $line): void
    {
        fwrite(STDOUT, $line."\n");
    }

    private function error(string $message): int
    {
        fwrite(STDOUT, "::error::{$message}\n");

        return 1;
    }
}
