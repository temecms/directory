<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Index;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Teme\Directory\Index\IndexBuilder;
use Teme\Directory\Tests\Support\Records;

final class IndexBuilderTest extends TestCase
{
    public function test_the_index_carries_its_format_sequence_and_a_fourteen_day_expiry(): void
    {
        $build = (new IndexBuilder)->build([Records::package()], 7, new DateTimeImmutable('2026-11-19T19:00:00Z'));

        $index = $this->json($build->files['index.json']);

        self::assertSame(1, $index['format']);
        self::assertSame(7, $index['sequence']);
        self::assertSame('2026-11-19T19:00:00Z', $index['generated']);
        self::assertSame('2026-12-03T19:00:00Z', $index['expires']);
    }

    public function test_each_detail_file_and_the_composer_repository_are_pinned_by_sha256(): void
    {
        $build = (new IndexBuilder)->build([Records::package()], 1, new DateTimeImmutable('2026-11-19T19:00:00Z'));
        $index = $this->json($build->files['index.json']);

        $entry = $index['packages'][0];
        self::assertSame('packages/acme/seo-audit.json', $entry['detail']);
        self::assertSame(hash('sha256', $build->files['packages/acme/seo-audit.json']), $entry['detail_sha256']);
        self::assertSame(['path' => 'composer/packages.json', 'sha256' => hash('sha256', $build->files['composer/packages.json'])], $index['composer']);
    }

    public function test_an_index_entry_has_the_fields_the_pages_filter_on(): void
    {
        $package = Records::package('acme/seo-audit', ['picked' => true, 'categories' => ['seo', 'content']]);

        $build = (new IndexBuilder)->build([$package], 1, new DateTimeImmutable('2026-11-19T19:00:00Z'));
        $entry = $this->json($build->files['index.json'])['packages'][0];

        self::assertSame([
            'name' => 'acme/seo-audit',
            'kind' => 'plugin',
            'categories' => ['seo', 'content'],
            'picked' => true,
            'label' => 'SEO Audit',
            'summary' => 'Audits every post.',
            'author' => ['name' => 'Acme', 'github' => 'acme'],
            'latest' => '1.0.0',
            'updated' => '2026-11-01',
            'listed' => '2026-11-01',
            'works_with' => ['1.1', '1.2'],
            'icon' => 'https://temecms.github.io/directory/assets/acme/seo-audit/1.0.0/icon.png',
            'detail' => 'packages/acme/seo-audit.json',
            'detail_sha256' => hash('sha256', $build->files['packages/acme/seo-audit.json']),
        ], $entry);
    }

    public function test_latest_is_the_highest_stable_listed_release(): void
    {
        $package = Records::package('acme/seo-audit', ['releases' => [
            '1.0.0' => Records::release('acme/seo-audit', '1.0.0', '2026-10-01T10:00:00Z'),
            '1.2.0' => Records::release('acme/seo-audit', '1.2.0', '2026-11-10T10:00:00Z'),
            '1.3.0-beta.1' => Records::release('acme/seo-audit', '1.3.0-beta.1', '2026-11-12T10:00:00Z'),
            '1.4.0' => Records::release('acme/seo-audit', '1.4.0', '2026-11-15T10:00:00Z', ['status' => 'held']),
        ]]);

        $build = (new IndexBuilder)->build([$package], 1, new DateTimeImmutable('2026-11-19T19:00:00Z'));
        $entry = $this->json($build->files['index.json'])['packages'][0];

        self::assertSame('1.2.0', $entry['latest']);
        self::assertSame('2026-11-10', $entry['updated']);
    }

    public function test_a_held_release_is_shown_as_held_but_never_offered_to_composer(): void
    {
        $package = Records::package('acme/seo-audit', ['releases' => [
            '1.0.0' => Records::release('acme/seo-audit', '1.0.0', '2026-10-01T10:00:00Z'),
            '1.1.0' => Records::release('acme/seo-audit', '1.1.0', '2026-11-10T10:00:00Z', ['status' => 'held']),
            '1.2.0' => Records::release('acme/seo-audit', '1.2.0', '2026-11-11T10:00:00Z', ['status' => 'pending']),
        ]]);

        $build = (new IndexBuilder)->build([$package], 1, new DateTimeImmutable('2026-11-19T19:00:00Z'));
        $detail = $this->json($build->files['packages/acme/seo-audit.json']);
        $composer = $this->json($build->files['composer/packages.json']);

        self::assertSame(['1.1.0', '1.0.0'], array_column($detail['releases'], 'version'));
        self::assertSame([true, false], array_column($detail['releases'], 'held'));
        self::assertSame(['1.0.0'], array_keys($composer['packages']['acme/seo-audit']));
    }

    public function test_a_package_with_no_listed_release_is_left_out(): void
    {
        $waiting = Records::package('acme/waiting', ['releases' => [
            '1.0.0' => Records::release('acme/waiting', '1.0.0', '2026-11-01T10:00:00Z', ['status' => 'held']),
        ]]);

        $build = (new IndexBuilder)->build([Records::package(), $waiting], 1, new DateTimeImmutable('2026-11-19T19:00:00Z'));

        self::assertSame(['acme/seo-audit'], array_column($this->json($build->files['index.json'])['packages'], 'name'));
        self::assertArrayNotHasKey('packages/acme/waiting.json', $build->files);
    }

    public function test_composer_receives_the_archive_url_its_sha1_shasum_and_the_commit(): void
    {
        $build = (new IndexBuilder)->build([Records::package()], 1, new DateTimeImmutable('2026-11-19T19:00:00Z'));
        $version = $this->json($build->files['composer/packages.json'])['packages']['acme/seo-audit']['1.0.0'];

        self::assertSame('acme/seo-audit', $version['name']);
        self::assertSame('1.0.0', $version['version']);
        self::assertSame('1.0.0.0', $version['version_normalized']);
        self::assertSame('teme-plugin', $version['type']);
        self::assertSame([
            'type' => 'zip',
            'url' => 'https://api.github.com/repos/acme/seo-audit/zipball/v1.0.0',
            'reference' => sha1('acme/seo-audit1.0.0'),
            'shasum' => sha1('acme/seo-audit1.0.0zip'),
        ], $version['dist']);
        self::assertSame('2026-11-01T10:00:00Z', $version['time']);
    }

    public function test_the_detail_file_holds_everything_a_page_needs(): void
    {
        $build = (new IndexBuilder)->build([Records::package()], 1, new DateTimeImmutable('2026-11-19T19:00:00Z'));
        $detail = $this->json($build->files['packages/acme/seo-audit.json']);

        foreach (['name', 'kind', 'categories', 'picked', 'repository', 'label', 'summary', 'author', 'latest', 'updated', 'listed', 'works_with', 'icon', 'readme', 'changelog', 'screenshots', 'capabilities', 'links', 'stars', 'issues', 'releases', 'license', 'php'] as $key) {
            self::assertArrayHasKey($key, $detail, $key);
        }
        self::assertSame("Audits every post.\n", $detail['readme']);
        self::assertSame(['version' => '1.0.0', 'date' => '2026-11-01', 'works_with' => ['1.1', '1.2'], 'dist' => ['url' => 'https://api.github.com/repos/acme/seo-audit/zipball/v1.0.0', 'sha256' => hash('sha256', 'acme/seo-audit1.0.0zip')], 'held' => false], $detail['releases'][0]);
    }

    public function test_the_content_hash_ignores_time_sequence_and_github_figures(): void
    {
        $builder = new IndexBuilder;
        $first = $builder->build([Records::package()], 1, new DateTimeImmutable('2026-11-19T19:00:00Z'));
        $later = $builder->build([Records::package('acme/seo-audit', ['stars' => 99])], 2, new DateTimeImmutable('2026-11-20T19:00:00Z'));
        $changed = $builder->build([Records::package('acme/seo-audit', ['picked' => true])], 1, new DateTimeImmutable('2026-11-19T19:00:00Z'));

        self::assertSame($first->contentHash, $later->contentHash);
        self::assertNotSame($first->figuresHash, $later->figuresHash);
        self::assertNotSame($first->contentHash, $changed->contentHash);
    }

    public function test_the_same_input_builds_the_same_bytes(): void
    {
        $builder = new IndexBuilder;
        $now = new DateTimeImmutable('2026-11-19T19:00:00Z');

        self::assertSame(
            $builder->build([Records::package('b/two'), Records::package('a/one')], 3, $now)->files,
            $builder->build([Records::package('a/one'), Records::package('b/two')], 3, $now)->files,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $bytes): array
    {
        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }
}
