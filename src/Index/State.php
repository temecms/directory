<?php

declare(strict_types=1);

namespace Teme\Directory\Index;

use RuntimeException;
use Teme\Directory\Json;

/**
 * What the index builder remembers between runs: every listed package's tags
 * seen and releases read, the last sequence number, and what was last
 * published. Kept as JSON files the workflow commits.
 */
final class State
{
    /**
     * @param  array<string, array<string, mixed>>  $packages
     * @param  array{content: string, figures: string, at: string}|null  $published
     */
    private function __construct(
        private array $packages,
        private int $sequence,
        private ?array $published,
    ) {}

    public static function empty(): self
    {
        return new self([], 0, null);
    }

    public static function load(string $directory): self
    {
        $main = Json::decode((string) @file_get_contents($directory.'/directory.json'));
        if ($main === null) {
            return self::empty();
        }

        $packages = [];
        foreach (glob($directory.'/packages/*/*.json') ?: [] as $file) {
            $package = Json::decode((string) file_get_contents($file));
            if ($package === null || ! is_string($package['name'] ?? null)) {
                throw new RuntimeException("{$file} is not a package record.");
            }
            $packages[$package['name']] = $package;
        }
        ksort($packages);

        /** @var array{content: string, figures: string, at: string}|null $published */
        $published = $main['published'] ?? null;

        return new self($packages, (int) ($main['sequence'] ?? 0), $published);
    }

    public function save(string $directory): void
    {
        @mkdir($directory.'/packages', 0777, true);
        foreach (glob($directory.'/packages/*/*.json') ?: [] as $file) {
            $name = basename(dirname($file)).'/'.basename($file, '.json');
            if (! isset($this->packages[$name])) {
                unlink($file);
            }
        }
        foreach ($this->packages as $name => $package) {
            @mkdir(dirname("{$directory}/packages/{$name}.json"), 0777, true);
            file_put_contents("{$directory}/packages/{$name}.json", Json::encode($package));
        }
        file_put_contents($directory.'/directory.json', Json::encode([
            'format' => 1,
            'sequence' => $this->sequence,
            'published' => $this->published,
        ]));
    }

    /**
     * The package's record, or null when it is not known.
     *
     * @return array<string, mixed>|null
     */
    public function package(string $name): ?array
    {
        return $this->packages[$name] ?? null;
    }

    /**
     * Every package's record, by name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function packages(): array
    {
        return $this->packages;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    public function put(array $package): void
    {
        $this->packages[(string) $package['name']] = $package;
        ksort($this->packages);
    }

    public function remove(string $name): void
    {
        unset($this->packages[$name]);
    }

    /**
     * The sequence number of the last index published.
     */
    public function sequence(): int
    {
        return $this->sequence;
    }

    /**
     * What the last published index held and when it was published.
     *
     * @return array{content: string, figures: string, at: string}|null
     */
    public function published(): ?array
    {
        return $this->published;
    }

    public function recordPublication(int $sequence, string $content, string $figures, string $at): void
    {
        $this->sequence = $sequence;
        $this->published = ['content' => $content, 'figures' => $figures, 'at' => $at];
    }
}
