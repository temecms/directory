<?php

declare(strict_types=1);

namespace Teme\Directory\Packages;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Teme\Directory\Json;
use Teme\Directory\Listings\Listing;
use Teme\Directory\Markdown\ImageCopier;
use Teme\Directory\Markdown\Location;
use Teme\Directory\Markdown\ReadmeCleaner;
use Teme\Directory\Teme\TemeVersions;
use Throwable;

/**
 * Reads one release of a listed package and runs the checks that need no
 * Teme: composer.json, the Teme manifest, README, licence, images and
 * capabilities. Installing on Teme is checked separately.
 */
final class PackageInspector
{
    public const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    private const IMAGE_TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];

    private const SCHEMAS = ['plugin' => 'urn:teme:plugin-manifest:1', 'theme' => 'urn:teme:theme-manifest:1'];

    /** @var list<array{passed: bool, words: string}> */
    private array $checks = [];

    /** @var array<string, string> */
    private array $assets = [];

    public function __construct(
        private readonly string $schemaDirectory,
        private readonly string $baseUrl,
        private readonly ImageFetcher $fetcher,
    ) {}

    public function inspect(Archive $archive, Listing $listing, string $version, string $tag, TemeVersions $teme): Inspection
    {
        $this->checks = [];
        $this->assets = [];
        $assetFolder = "assets/{$listing->name}/{$version}/";
        $manifestFile = "teme-{$listing->kind}.json";
        $type = "teme-{$listing->kind}";

        $composer = $this->composer($archive, $listing, $type);
        $manifest = $this->manifest($archive, $listing, $manifestFile);

        $license = $composer['license'] ?? null;
        $license = is_array($license) ? implode(' or ', array_filter($license, 'is_string')) : (is_string($license) ? trim($license) : '');
        $this->check($license !== '', "It is released under the {$license} licence.", 'composer.json must name a licence.');

        $range = $this->temeRange($composer, $manifest);
        $worksWith = $teme->worksWith($range);
        if ($range === null) {
            $this->fail("Neither composer.json nor {$manifestFile} names the Teme versions it works with.");
        } else {
            $this->check($worksWith !== [], 'It works with Teme '.implode(', ', $worksWith).'.', "The Teme range {$range} matches no released Teme version.");
        }

        [$capabilities, $hosts] = $this->capabilities($manifest, $listing->kind, $manifestFile);

        $icon = is_string($manifest['icon'] ?? null) ? $this->packageImage($archive, $manifest['icon'], 'icon', $assetFolder) : null;
        $screenshots = $this->screenshots($archive, $manifest, $assetFolder);

        $location = new Location($listing->owner, $listing->repo, $tag);
        $copier = $this->copier($archive, $assetFolder);
        $readmeSource = $archive->read('README.md');
        $this->check($readmeSource !== null, 'README.md is at the package\'s root.', 'README.md is missing from the package\'s root.');
        $readme = $readmeSource === null ? null : (new ReadmeCleaner($copier))->clean($readmeSource, $location);
        $changelogSource = $archive->read('CHANGELOG.md');
        $changelog = $changelogSource === null ? null : (new ReadmeCleaner($copier))->clean($changelogSource, $location);

        $summary = $this->text($manifest['description'] ?? null) ?? $this->text($composer['description'] ?? null) ?? ($readme === null ? null : ReadmeCleaner::firstParagraph($readme));
        $label = $this->text($listing->kind === 'plugin' ? ($manifest['label'] ?? null) : ($manifest['name'] ?? null)) ?? $listing->name;
        $support = is_array($composer['support'] ?? null) ? $composer['support'] : [];

        $page = [
            'label' => $label,
            'summary' => $summary,
            'author' => ['name' => $this->author($composer, $manifest, $listing)],
            'icon' => $icon,
            'screenshots' => $screenshots,
            'capabilities' => array_map(static fn (string $key): array => ['key' => $key, 'words' => Capabilities::words($key, $hosts)], $capabilities),
            'readme' => $readme === '' ? null : $readme,
            'changelog' => $changelog === '' ? null : $changelog,
            'links' => [
                'source' => $listing->repository(),
                'issues' => $this->https($support['issues'] ?? null),
                'docs' => $this->https($support['docs'] ?? null),
                'support' => $this->https($support['forum'] ?? null) ?? $this->https($support['chat'] ?? null) ?? $this->mail($support['email'] ?? null),
                'security' => $this->https($support['security'] ?? null),
            ],
            'license' => $license === '' ? null : $license,
            'php' => is_string($composer['require']['php'] ?? null) ? $composer['require']['php'] : null,
        ];

        return new Inspection(
            $composer,
            $page,
            $capabilities,
            $hosts,
            $range,
            $worksWith,
            $teme->tagsToTest($range),
            $this->text($manifest['code'] ?? null),
            $this->assets,
            $this->checks,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(Archive $archive, Listing $listing, string $type): array
    {
        $source = $archive->read('composer.json');
        if ($source === null) {
            $this->fail('composer.json is missing from the package\'s root.');

            return [];
        }
        $composer = Json::decode($source);
        if ($composer === null || array_is_list($composer)) {
            $this->fail('composer.json is not valid JSON.');

            return [];
        }

        $name = is_string($composer['name'] ?? null) ? $composer['name'] : '';
        $this->check($name === $listing->name, "composer.json names the package {$listing->name}.", "composer.json names the package {$name}, but the listing is {$listing->name}.");
        $this->check(($composer['type'] ?? null) === $type, "Its type is {$type}.", "composer.json's type must be {$type}.");

        return $composer;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(Archive $archive, Listing $listing, string $file): array
    {
        $source = $archive->read($file);
        if ($source === null) {
            $this->fail("{$file} is missing from the package's root.");

            return [];
        }
        try {
            $data = json_decode($source, false, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $data = null;
        }
        if (! $data instanceof \stdClass) {
            $this->fail("{$file} is not valid JSON.");

            return [];
        }

        $errors = $this->schemaErrors($data, $listing->kind);
        if ($errors === []) {
            $this->pass("{$file} follows Teme's manifest format.");
        }
        foreach (array_slice($errors, 0, 5) as $error) {
            $this->fail("{$file} does not follow Teme's manifest format: {$error}");
        }

        /** @var array<string, mixed> $manifest */
        $manifest = Json::decode($source);
        if ($listing->kind === 'plugin' && is_string($manifest['name'] ?? null) && $manifest['name'] !== $listing->name) {
            $this->fail("{$file} names the package {$manifest['name']}, but the listing is {$listing->name}.");
        }

        return $manifest;
    }

    /**
     * @return list<string>
     */
    private function schemaErrors(\stdClass $data, string $kind): array
    {
        $validator = new Validator;
        $validator->setMaxErrors(5);
        $validator->resolver()?->registerFile(self::SCHEMAS[$kind], "{$this->schemaDirectory}/teme-{$kind}.schema.json");
        $result = $validator->validate($data, self::SCHEMAS[$kind]);
        if ($result->isValid()) {
            return [];
        }

        $errors = [];
        foreach ((new ErrorFormatter)->format($result->error(), false) as $path => $message) {
            $where = trim(str_replace('/', '.', (string) $path), '.');
            $errors[] = ($where === '' ? '' : $where.': ').$message;
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $composer
     * @param  array<string, mixed>  $manifest
     */
    private function temeRange(array $composer, array $manifest): ?string
    {
        return $this->text($composer['require']['teme/teme'] ?? null) ?? $this->text($manifest['requires']['teme'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{list<string>, list<string>}
     */
    private function capabilities(array $manifest, string $kind, string $file): array
    {
        if ($kind !== 'plugin') {
            return [[], []];
        }
        if (! is_array($manifest['capabilities'] ?? null)) {
            $this->fail("{$file} must list its capabilities, as [] when it asks for none.");

            return [[], []];
        }

        $capabilities = array_values(array_unique(array_filter($manifest['capabilities'], 'is_string')));
        $hosts = in_array('http', $capabilities, true) && is_array($manifest['hosts'] ?? null)
            ? array_values(array_unique(array_filter($manifest['hosts'], 'is_string')))
            : [];
        $this->pass($capabilities === [] ? 'It asks for no capabilities.' : 'It lists what it asks for: '.implode(', ', [...$capabilities, ...$hosts]).'.');

        return [$capabilities, $hosts];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{url: string, caption: string|null}>
     */
    private function screenshots(Archive $archive, array $manifest, string $folder): array
    {
        $declared = is_array($manifest['screenshots'] ?? null) ? $manifest['screenshots'] : [];
        if (is_string($manifest['screenshot'] ?? null)) {
            $declared[] = $manifest['screenshot'];
        }

        $screenshots = [];
        foreach ($declared as $entry) {
            $path = is_string($entry) ? $entry : ($entry['path'] ?? $entry['file'] ?? null);
            if (! is_string($path)) {
                continue;
            }
            $url = $this->packageImage($archive, $path, 'screenshot', $folder);
            if ($url !== null) {
                $screenshots[] = ['url' => $url, 'caption' => is_array($entry) ? $this->text($entry['caption'] ?? null) : null];
            }
        }

        return $screenshots;
    }

    private function packageImage(Archive $archive, string $path, string $what, string $folder): ?string
    {
        $bytes = $archive->read($path);
        if ($bytes === null) {
            $this->fail("The {$what} {$path} is not in the package.");

            return null;
        }
        $type = $this->imageType($bytes);
        if ($type === null) {
            $this->fail("The {$what} {$path} must be a PNG, JPEG or WebP image.");

            return null;
        }
        if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
            $this->fail("The {$what} {$path} is over 2 MB.");

            return null;
        }
        $this->pass("The {$what} {$path} is an image under 2 MB.");

        return $this->publish($bytes, $type, $folder);
    }

    private function copier(Archive $archive, string $folder): ImageCopier
    {
        return new class($this, $archive, $this->fetcher, $folder) implements ImageCopier
        {
            public function __construct(
                private readonly PackageInspector $inspector,
                private readonly Archive $archive,
                private readonly ImageFetcher $fetcher,
                private readonly string $folder,
            ) {}

            public function copy(string $source, bool $inPackage): ?string
            {
                $bytes = $inPackage ? $this->archive->read($source) : $this->fetcher->fetch($source);

                return $bytes === null ? null : $this->inspector->publishImage($bytes, $this->folder);
            }
        };
    }

    /**
     * Publishes an image a README shows, or returns null when it is not a PNG,
     * JPEG or WebP image under 2 MB.
     *
     * @internal
     */
    public function publishImage(string $bytes, string $folder): ?string
    {
        $type = $this->imageType($bytes);

        return $type === null || strlen($bytes) > self::MAX_IMAGE_BYTES ? null : $this->publish($bytes, $type, $folder);
    }

    private function publish(string $bytes, string $extension, string $folder): string
    {
        $path = $folder.substr(hash('sha256', $bytes), 0, 16).'.'.$extension;
        $this->assets[$path] = $bytes;

        return $this->baseUrl.$path;
    }

    private function imageType(string $bytes): ?string
    {
        $size = @getimagesizefromstring($bytes);

        return $size === false ? null : self::IMAGE_TYPES[$size[2]] ?? null;
    }

    /**
     * @param  array<string, mixed>  $composer
     * @param  array<string, mixed>  $manifest
     */
    private function author(array $composer, array $manifest, Listing $listing): string
    {
        $first = is_array($composer['authors'] ?? null) ? ($composer['authors'][0] ?? null) : null;

        return $this->text(is_array($first) ? ($first['name'] ?? null) : null) ?? $this->text($manifest['author'] ?? null) ?? $listing->owner;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function https(mixed $value): ?string
    {
        return is_string($value) && str_starts_with($value, 'https://') ? $value : null;
    }

    private function mail(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? 'mailto:'.$value : null;
    }

    private function check(bool $passed, string $pass, string $fail): void
    {
        $this->checks[] = ['passed' => $passed, 'words' => $passed ? $pass : $fail];
    }

    private function pass(string $words): void
    {
        $this->checks[] = ['passed' => true, 'words' => $words];
    }

    private function fail(string $words): void
    {
        $this->checks[] = ['passed' => false, 'words' => $words];
    }
}
