<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Support;

/**
 * Package records shaped as the state keeps them, for the index tests.
 */
final class Records
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function package(string $name = 'acme/seo-audit', array $overrides = []): array
    {
        [$owner, $repo] = explode('/', $name);

        return array_replace([
            'name' => $name,
            'kind' => 'plugin',
            'categories' => ['seo'],
            'picked' => false,
            'repository' => "https://github.com/{$owner}/{$repo}",
            'owner' => $owner,
            'listed' => '2026-11-01',
            'stars' => 12,
            'issues' => 3,
            'releases' => [
                '1.0.0' => self::release($name, '1.0.0', '2026-11-01T10:00:00Z'),
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function release(string $name, string $version, string $date, array $overrides = []): array
    {
        [$owner, $repo] = explode('/', $name);

        return array_replace([
            'version' => $version,
            'tag' => 'v'.$version,
            'reference' => sha1($name.$version),
            'date' => $date,
            'status' => 'listed',
            'works_with' => ['1.1', '1.2'],
            'capabilities' => ['content.read'],
            'hosts' => [],
            'dist' => [
                'url' => "https://api.github.com/repos/{$owner}/{$repo}/zipball/v{$version}",
                'sha256' => hash('sha256', $name.$version.'zip'),
                'sha1' => sha1($name.$version.'zip'),
            ],
            'composer' => [
                'name' => $name,
                'type' => 'teme-plugin',
                'description' => 'Audits every post.',
                'license' => 'MIT',
                'require' => ['php' => '^8.4', 'teme/teme' => '^1.1'],
                'autoload' => ['psr-4' => ['Acme\\SeoAudit\\' => 'src/']],
            ],
            'page' => [
                'label' => 'SEO Audit',
                'summary' => 'Audits every post.',
                'author' => ['name' => 'Acme'],
                'icon' => "https://temecms.github.io/directory/assets/{$name}/{$version}/icon.png",
                'screenshots' => [],
                'capabilities' => [['key' => 'content.read', 'words' => 'Read your posts, pages and other content, including drafts']],
                'readme' => "Audits every post.\n",
                'changelog' => null,
                'links' => ['source' => "https://github.com/{$owner}/{$repo}", 'issues' => null, 'docs' => null, 'support' => null],
                'license' => 'MIT',
                'php' => '^8.4',
            ],
        ], $overrides);
    }
}
