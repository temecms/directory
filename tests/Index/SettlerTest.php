<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Index;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Teme\Directory\Index\Settler;
use Teme\Directory\Index\State;
use Teme\Directory\Tests\Support\Records;

final class SettlerTest extends TestCase
{
    private State $state;

    protected function setUp(): void
    {
        $this->state = State::empty();
        $package = Records::package('acme/seo-audit', ['listed' => null, 'releases' => [
            '1.0.0' => Records::release('acme/seo-audit', '1.0.0', '2026-11-01T10:00:00Z', ['status' => 'pending']),
        ]]);
        $this->state->put($package);
    }

    public function test_a_pending_release_that_installs_is_listed_and_dates_the_listing(): void
    {
        $this->settle(['acme/seo-audit@1.0.0' => ['passed' => true, 'words' => []]]);

        $package = $this->state->package('acme/seo-audit');
        self::assertSame('listed', $package['releases']['1.0.0']['status']);
        self::assertSame('2026-11-19', $package['listed']);
    }

    public function test_a_pending_release_that_does_not_install_is_held_with_the_reason(): void
    {
        $this->settle(['acme/seo-audit@1.0.0' => ['passed' => false, 'words' => ['It does not install on Teme v1.2.0.']]]);

        $release = $this->state->package('acme/seo-audit')['releases']['1.0.0'];
        self::assertSame('held', $release['status']);
        self::assertSame(['It does not install on Teme v1.2.0.'], $release['hold']['reasons']);
    }

    public function test_a_pending_release_with_no_result_waits(): void
    {
        $this->settle([]);

        self::assertSame('pending', $this->state->package('acme/seo-audit')['releases']['1.0.0']['status']);
    }

    public function test_held_releases_without_an_issue_are_named_once_for_an_issue(): void
    {
        $this->settle(['acme/seo-audit@1.0.0' => ['passed' => false, 'words' => ['It does not install.']]]);

        $holds = (new Settler)->holdsWithoutIssue($this->state);

        self::assertSame('Held: acme/seo-audit 1.0.0', $holds[0]['title']);
        self::assertStringContainsString('It does not install.', $holds[0]['body']);
        self::assertStringContainsString('https://github.com/acme/seo-audit/releases/tag/v1.0.0', $holds[0]['body']);

        (new Settler)->recordIssue($this->state, 'acme/seo-audit', '1.0.0', 42);

        self::assertSame([], (new Settler)->holdsWithoutIssue($this->state));
        self::assertSame(42, $this->state->package('acme/seo-audit')['releases']['1.0.0']['hold']['issue']);
    }

    public function test_a_hold_whose_issue_is_closed_as_completed_is_listed(): void
    {
        $this->settle(['acme/seo-audit@1.0.0' => ['passed' => false, 'words' => ['It does not install.']]]);
        (new Settler)->recordIssue($this->state, 'acme/seo-audit', '1.0.0', 42);

        $this->settle([], [42 => 'not_planned']);
        self::assertSame('held', $this->state->package('acme/seo-audit')['releases']['1.0.0']['status']);

        $this->settle([], [42 => 'completed']);
        self::assertSame('listed', $this->state->package('acme/seo-audit')['releases']['1.0.0']['status']);
    }

    public function test_only_the_newest_listed_release_keeps_its_readme_and_changelog(): void
    {
        $package = $this->state->package('acme/seo-audit');
        $package['releases']['1.0.0']['status'] = 'listed';
        $package['releases']['1.1.0'] = Records::release('acme/seo-audit', '1.1.0', '2026-11-10T10:00:00Z', ['status' => 'pending']);
        $this->state->put($package);

        $this->settle(['acme/seo-audit@1.1.0' => ['passed' => true, 'words' => []]]);

        $releases = $this->state->package('acme/seo-audit')['releases'];
        self::assertNull($releases['1.0.0']['page']['readme']);
        self::assertNull($releases['1.0.0']['page']['changelog']);
        self::assertSame("Audits every post.\n", $releases['1.1.0']['page']['readme']);
    }

    /**
     * @param  array<string, array{passed: bool, words: list<string>}>  $results
     * @param  array<int, string>  $closed
     */
    private function settle(array $results, array $closed = []): void
    {
        (new Settler)->settle($this->state, $results, $closed, new DateTimeImmutable('2026-11-19T19:00:00Z'));
    }
}
