<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * Builds a release archive shaped like GitHub's: every file under one top folder.
 */
final class PackageZip
{
    /** A 1 by 1 PNG. */
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public static function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }

    /**
     * @param  array<string, string>  $files
     */
    public static function bytes(array $files, string $top = 'acme-seo-audit-0123abc'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pkg');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open a zip.');
        }
        $zip->addEmptyDir($top);
        foreach ($files as $name => $contents) {
            $zip->addFromString($top.'/'.$name, $contents);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * A plugin that passes every static check.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $composer
     * @return array<string, string>
     */
    public static function plugin(string $name = 'acme/seo-audit', string $version = '1.0.0', array $manifest = [], array $composer = []): array
    {
        return [
            'composer.json' => self::json(array_replace([
                'name' => $name,
                'type' => 'teme-plugin',
                'description' => 'Audits every post.',
                'license' => 'MIT',
                'authors' => [['name' => 'Acme Ltd']],
                'support' => ['issues' => 'https://github.com/acme/seo-audit/issues', 'docs' => 'https://acme.example/docs'],
                'require' => ['php' => '^8.4', 'teme/teme' => '^1.1'],
                'autoload' => ['psr-4' => ['Acme\\SeoAudit\\' => 'src/']],
            ], $composer)),
            'teme-plugin.json' => self::json(array_replace([
                'manifest' => 1,
                'name' => $name,
                'code' => 'seo-audit',
                'label' => 'SEO Audit',
                'description' => 'An audit of every post.',
                'version' => $version,
                'requires' => ['teme' => '^1.1'],
                'plugin' => 'Acme\\SeoAudit\\Plugin',
                'capabilities' => ['content.read'],
                'icon' => 'art/icon.png',
                'screenshots' => [['path' => 'art/audit.png', 'caption' => 'The audit table']],
            ], $manifest)),
            'README.md' => "# SEO Audit\n\nAn audit of every post.\n\n![Table](art/audit.png)\n",
            'CHANGELOG.md' => "# Changelog\n\n## {$version} - 2026-11-01\n\n- First release.\n",
            'art/icon.png' => self::png(),
            'art/audit.png' => self::png(),
            'src/Plugin.php' => "<?php\n",
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
