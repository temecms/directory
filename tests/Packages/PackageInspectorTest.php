<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Packages;

use PHPUnit\Framework\TestCase;
use Teme\Directory\Listings\Listing;
use Teme\Directory\Packages\Archive;
use Teme\Directory\Packages\ImageFetcher;
use Teme\Directory\Packages\Inspection;
use Teme\Directory\Packages\PackageInspector;
use Teme\Directory\Teme\TemeVersions;
use Teme\Directory\Tests\Support\PackageZip;

final class PackageInspectorTest extends TestCase
{
    public function test_a_good_plugin_passes_every_check(): void
    {
        $inspection = $this->inspect(PackageZip::plugin());

        self::assertTrue($inspection->passed(), implode("\n", $inspection->failures()));
        self::assertSame(['1.1', '1.2'], $inspection->worksWith);
        self::assertSame('^1.1', $inspection->temeRange);
        self::assertSame(['content.read'], $inspection->capabilities);
    }

    public function test_the_archive_is_pinned_by_its_checksums(): void
    {
        $bytes = PackageZip::bytes(PackageZip::plugin());
        $archive = Archive::fromBytes($bytes);

        self::assertSame(hash('sha256', $bytes), $archive->sha256);
        self::assertSame(sha1($bytes), $archive->sha1);
        self::assertSame("<?php\n", $archive->read('src/Plugin.php'));
        self::assertNull($archive->read('../etc/passwd'));
    }

    public function test_the_page_is_read_from_the_manifest_composer_and_readme(): void
    {
        $page = $this->inspect(PackageZip::plugin())->page;
        $assetBase = 'https://temecms.github.io/directory/assets/acme/seo-audit/1.0.0/';
        $png = substr(hash('sha256', PackageZip::png()), 0, 16).'.png';

        self::assertSame('SEO Audit', $page['label']);
        self::assertSame('An audit of every post.', $page['summary']);
        self::assertSame(['name' => 'Acme Ltd'], $page['author']);
        self::assertSame($assetBase.$png, $page['icon']);
        self::assertSame([['url' => $assetBase.$png, 'caption' => 'The audit table']], $page['screenshots']);
        self::assertSame([['key' => 'content.read', 'words' => 'Read your posts, pages and other content, including drafts']], $page['capabilities']);
        self::assertSame("An audit of every post.\n\n![Table]({$assetBase}{$png})\n", $page['readme']);
        self::assertSame("## 1.0.0 - 2026-11-01\n\n- First release.\n", $page['changelog']);
        self::assertSame([
            'source' => 'https://github.com/acme/seo-audit',
            'issues' => 'https://github.com/acme/seo-audit/issues',
            'docs' => 'https://acme.example/docs',
            'support' => null,
            'security' => null,
        ], $page['links']);
        self::assertSame('MIT', $page['license']);
        self::assertSame('^8.4', $page['php']);
    }

    public function test_copied_images_are_handed_back_to_publish_under_the_release(): void
    {
        $inspection = $this->inspect(PackageZip::plugin());
        $png = substr(hash('sha256', PackageZip::png()), 0, 16).'.png';

        self::assertSame(['assets/acme/seo-audit/1.0.0/'.$png => PackageZip::png()], $inspection->assets);
    }

    public function test_http_hosts_are_shown_with_the_capability(): void
    {
        $inspection = $this->inspect(PackageZip::plugin(manifest: ['capabilities' => ['http'], 'hosts' => ['api.example.com']]));

        self::assertSame([['key' => 'http', 'words' => 'Connect to services outside this site: api.example.com']], $inspection->page['capabilities']);
        self::assertSame(['api.example.com'], $inspection->hosts);
    }

    public function test_each_failing_check_is_named_in_plain_words(): void
    {
        $files = PackageZip::plugin(manifest: ['code' => 'X'], composer: ['name' => 'acme/other', 'type' => 'library', 'license' => '']);
        unset($files['README.md'], $files['art/icon.png']);
        $files['art/audit.png'] = 'not an image';

        $failures = $this->inspect($files)->failures();

        self::assertContains('composer.json names the package acme/other, but the listing is acme/seo-audit.', $failures);
        self::assertContains('composer.json\'s type must be teme-plugin.', $failures);
        self::assertContains('composer.json must name a licence.', $failures);
        self::assertContains('README.md is missing from the package\'s root.', $failures);
        self::assertContains('The icon art/icon.png is not in the package.', $failures);
        self::assertContains('The screenshot art/audit.png must be a PNG, JPEG or WebP image.', $failures);
        self::assertContains('teme-plugin.json does not follow Teme\'s manifest format: code: The string should match pattern: ^[a-z0-9][a-z0-9-]{1,39}$', $failures);
    }

    public function test_an_image_over_2_mb_fails(): void
    {
        $files = PackageZip::plugin();
        $files['art/icon.png'] = PackageZip::png().str_repeat("\0", 2 * 1024 * 1024);

        self::assertContains('The icon art/icon.png is over 2 MB.', $this->inspect($files)->failures());
    }

    public function test_a_plugin_must_list_its_capabilities_even_when_it_asks_for_none(): void
    {
        $files = PackageZip::plugin();
        $manifest = json_decode($files['teme-plugin.json'], true);
        unset($manifest['capabilities']);
        $files['teme-plugin.json'] = PackageZip::json($manifest);

        self::assertContains('teme-plugin.json must list its capabilities, as [] when it asks for none.', $this->inspect($files)->failures());
    }

    public function test_a_range_matching_no_released_teme_fails(): void
    {
        $files = PackageZip::plugin(manifest: ['requires' => ['teme' => '^9.0']], composer: ['require' => ['teme/teme' => '^9.0']]);

        self::assertContains('The Teme range ^9.0 matches no released Teme version.', $this->inspect($files)->failures());
    }

    public function test_a_missing_manifest_fails(): void
    {
        $files = PackageZip::plugin();
        unset($files['teme-plugin.json']);

        self::assertContains('teme-plugin.json is missing from the package\'s root.', $this->inspect($files)->failures());
    }

    public function test_a_theme_is_read_from_its_own_manifest(): void
    {
        $files = [
            'composer.json' => PackageZip::json(['name' => 'temecms/theme-temecms', 'type' => 'teme-theme', 'license' => 'MIT', 'description' => 'The temecms.com theme.', 'require' => ['teme/teme' => '^1.2']]),
            'teme-theme.json' => PackageZip::json(['manifest' => 1, 'code' => 'temecms', 'name' => 'Teme site', 'version' => '1.0.0', 'description' => 'The theme of temecms.com.', 'requires' => ['teme' => '^1.2'], 'screenshot' => 'screenshot.png']),
            'README.md' => "# Teme site\n\nThe theme.\n",
            'screenshot.png' => PackageZip::png(),
        ];

        $inspection = $this->inspect($files, new Listing('temecms/theme-temecms', 'temecms', 'theme-temecms', 'theme', ['documentation'], false));

        self::assertTrue($inspection->passed(), implode("\n", $inspection->failures()));
        self::assertSame('Teme site', $inspection->page['label']);
        self::assertSame('The theme of temecms.com.', $inspection->page['summary']);
        self::assertNull($inspection->page['icon']);
        self::assertCount(1, $inspection->page['screenshots']);
        self::assertSame([], $inspection->page['capabilities']);
        self::assertSame('temecms', $inspection->code);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function inspect(array $files, ?Listing $listing = null): Inspection
    {
        $listing ??= new Listing('acme/seo-audit', 'acme', 'seo-audit', 'plugin', ['seo'], false);
        $fetcher = new class implements ImageFetcher
        {
            public function fetch(string $url): ?string
            {
                return null;
            }
        };

        return (new PackageInspector(dirname(__DIR__, 2).'/schema', 'https://temecms.github.io/directory/', $fetcher))->inspect(
            Archive::fromBytes(PackageZip::bytes($files)),
            $listing,
            '1.0.0',
            'v1.0.0',
            TemeVersions::fromTags(['v1.0.0', 'v1.1.0', 'v1.2.0']),
        );
    }
}
