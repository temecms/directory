<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Index;

use PHPUnit\Framework\TestCase;
use Teme\Directory\Index\Poller;
use Teme\Directory\Index\PollReport;
use Teme\Directory\Index\State;
use Teme\Directory\Listings\Listing;
use Teme\Directory\Packages\ImageFetcher;
use Teme\Directory\Packages\PackageInspector;
use Teme\Directory\Teme\TemeVersions;
use Teme\Directory\Tests\Support\FakeGitHub;
use Teme\Directory\Tests\Support\PackageZip;

final class PollerTest extends TestCase
{
    private FakeGitHub $github;

    private State $state;

    private string $work;

    protected function setUp(): void
    {
        $this->github = new FakeGitHub;
        $this->state = State::empty();
        $this->work = sys_get_temp_dir().'/directory-poll-'.bin2hex(random_bytes(4));
    }

    public function test_a_new_listing_takes_only_its_newest_release_and_waits_for_the_install_check(): void
    {
        $this->github->release('v0.9.0', PackageZip::plugin(version: '0.9.0'));
        $this->github->release('v1.0.0', PackageZip::plugin(version: '1.0.0'));
        $this->github->tag('nightly');

        $report = $this->poll();

        $package = $this->state->package('acme/seo-audit');
        self::assertSame(['1.0.0'], array_keys($package['releases']));
        self::assertSame(['nightly', 'v0.9.0', 'v1.0.0'], $package['seen']);
        $release = $package['releases']['1.0.0'];
        self::assertSame('pending', $release['status']);
        self::assertSame(hash('sha256', $this->github->bytes['v1.0.0']), $release['dist']['sha256']);
        self::assertSame(sha1($this->github->bytes['v1.0.0']), $release['dist']['sha1']);
        self::assertSame('https://api.github.com/repos/acme/seo-audit/zipball/v1.0.0', $release['dist']['url']);
        self::assertSame('2026-11-01T10:00:00Z', $release['date']);
        self::assertSame(['1.1', '1.2'], $release['works_with']);
        self::assertSame([['name' => 'acme/seo-audit', 'version' => '1.0.0', 'tag' => 'v1.0.0', 'kind' => 'plugin', 'code' => 'seo-audit', 'archive' => $this->work.'/archives/acme-seo-audit-1.0.0.zip', 'teme' => ['v1.1.0', 'v1.2.0']]], $report->pending);
        self::assertSame($this->github->bytes['v1.0.0'], file_get_contents($this->work.'/archives/acme-seo-audit-1.0.0.zip'));
    }

    public function test_a_tag_already_seen_is_never_downloaded_again(): void
    {
        $this->listedRelease('1.0.0', ['content.read'], []);
        $this->github->downloads = [];

        $this->poll();

        self::assertSame([], $this->github->downloads);
    }

    public function test_listing_fields_and_github_figures_follow_each_poll(): void
    {
        $this->github->release('v1.0.0', PackageZip::plugin());
        $this->poll();
        $this->github->figures = ['stars' => 40, 'issues' => 1];

        $this->poll(new Listing('acme/seo-audit', 'acme', 'seo-audit', 'plugin', ['seo', 'forms'], true));

        $package = $this->state->package('acme/seo-audit');
        self::assertSame(['seo', 'forms'], $package['categories']);
        self::assertTrue($package['picked']);
        self::assertSame(40, $package['stars']);
        self::assertSame(1, $package['issues']);
    }

    public function test_a_release_that_fails_a_check_is_held(): void
    {
        $files = PackageZip::plugin();
        unset($files['README.md']);
        $this->github->release('v1.0.0', $files);

        $report = $this->poll();

        $release = $this->state->package('acme/seo-audit')['releases']['1.0.0'];
        self::assertSame('held', $release['status']);
        self::assertSame(['README.md is missing from the package\'s root.'], $release['hold']['reasons']);
        self::assertSame([], $report->pending);
    }

    public function test_a_release_that_asks_for_more_than_the_last_listed_one_is_held(): void
    {
        $this->listedRelease('1.0.0', ['content.read'], []);
        $this->github->release('v1.1.0', PackageZip::plugin(version: '1.1.0', manifest: ['capabilities' => ['content.read', 'http'], 'hosts' => ['api.example.com']]));

        $this->poll();

        $release = $this->state->package('acme/seo-audit')['releases']['1.1.0'];
        self::assertSame('held', $release['status']);
        self::assertSame(['It asks for more than 1.0.0 did: http, api.example.com.'], $release['hold']['reasons']);
    }

    public function test_a_new_host_alone_is_asking_for_more(): void
    {
        $this->listedRelease('1.0.0', ['http'], ['api.example.com']);
        $this->github->release('v1.1.0', PackageZip::plugin(version: '1.1.0', manifest: ['capabilities' => ['http'], 'hosts' => ['api.example.com', 'tracker.example.net']]));

        $this->poll();

        self::assertSame(['It asks for more than 1.0.0 did: tracker.example.net.'], $this->state->package('acme/seo-audit')['releases']['1.1.0']['hold']['reasons']);
    }

    public function test_after_the_first_every_new_release_is_taken(): void
    {
        $this->listedRelease('1.0.0', ['content.read'], []);
        $this->github->release('v1.1.0', PackageZip::plugin(version: '1.1.0'));
        $this->github->release('v1.2.0', PackageZip::plugin(version: '1.2.0'));

        $report = $this->poll();

        self::assertSame(['1.1.0', '1.2.0'], array_column($report->pending, 'version'));
    }

    public function test_a_pending_release_whose_archive_changed_is_held(): void
    {
        $this->github->release('v1.0.0', PackageZip::plugin());
        $this->poll();
        $this->github->bytes['v1.0.0'] = PackageZip::bytes(PackageZip::plugin(), 'other-top');

        $report = $this->poll();

        $release = $this->state->package('acme/seo-audit')['releases']['1.0.0'];
        self::assertSame('held', $release['status']);
        self::assertSame(['The release archive changed after it was first read.'], $release['hold']['reasons']);
        self::assertSame([], $report->pending);
    }

    public function test_a_pending_release_is_offered_for_checking_again(): void
    {
        $this->github->release('v1.0.0', PackageZip::plugin());
        $this->poll();

        self::assertSame(['1.0.0'], array_column($this->poll()->pending, 'version'));
    }

    public function test_a_removed_listing_leaves_the_index(): void
    {
        $this->github->release('v1.0.0', PackageZip::plugin());
        $this->poll();

        (new Poller($this->github, $this->inspector(), $this->work))->poll([], $this->state, $this->teme());

        self::assertNull($this->state->package('acme/seo-audit'));
    }

    public function test_a_repository_that_cannot_be_read_keeps_what_was_known(): void
    {
        $this->github->release('v1.0.0', PackageZip::plugin());
        $this->poll();
        $this->github->failing = true;

        $report = $this->poll();

        self::assertSame(['acme/seo-audit: GitHub did not answer.'], $report->problems);
        self::assertArrayHasKey('1.0.0', $this->state->package('acme/seo-audit')['releases']);
    }

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $hosts
     */
    private function listedRelease(string $version, array $capabilities, array $hosts): void
    {
        $this->github->release('v'.$version, PackageZip::plugin(version: $version, manifest: ['capabilities' => $capabilities, 'hosts' => $hosts]));
        $this->poll();
        $package = $this->state->package('acme/seo-audit');
        $package['releases'][$version]['status'] = 'listed';
        $this->state->put($package);
    }

    private function poll(?Listing $listing = null): PollReport
    {
        $listing ??= new Listing('acme/seo-audit', 'acme', 'seo-audit', 'plugin', ['seo'], false);

        return (new Poller($this->github, $this->inspector(), $this->work))->poll([$listing], $this->state, $this->teme());
    }

    private function teme(): TemeVersions
    {
        return TemeVersions::fromTags(['v1.0.0', 'v1.1.0', 'v1.2.0']);
    }

    private function inspector(): PackageInspector
    {
        return new PackageInspector(dirname(__DIR__, 2).'/schema', 'https://temecms.github.io/directory/', new class implements ImageFetcher
        {
            public function fetch(string $url): ?string
            {
                return null;
            }
        });
    }
}
