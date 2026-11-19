<?php

declare(strict_types=1);

namespace Teme\Directory\Index;

use InvalidArgumentException;
use Teme\Directory\Paths;

/**
 * Writes the published site: the index, its signature, the detail files and
 * the Composer repository, replacing the last build's, and images, which are
 * kept for good once published.
 */
final class SiteWriter
{
    public function __construct(private readonly string $site) {}

    /**
     * @param  array<string, string>  $files  A build's files.
     */
    public function write(array $files, string $signature): void
    {
        foreach (['packages', 'composer'] as $folder) {
            foreach ($this->filesUnder($this->site.'/'.$folder) as $old) {
                $path = substr($old, strlen($this->site) + 1);
                if (! isset($files[$path])) {
                    unlink($old);
                }
            }
        }
        foreach ($files as $path => $bytes) {
            $this->put($path, $bytes);
        }
        $this->put('index.json.sig', $signature);
        $this->put('.nojekyll', '');
    }

    /**
     * @param  array<string, string>  $assets  Path, then the image's bytes.
     */
    public function assets(array $assets): void
    {
        foreach ($assets as $path => $bytes) {
            if (! is_file($this->target($path))) {
                $this->put($path, $bytes);
            }
        }
    }

    private function put(string $path, string $bytes): void
    {
        $target = $this->target($path);
        @mkdir(dirname($target), 0777, true);
        file_put_contents($target, $bytes);
    }

    private function target(string $path): string
    {
        $normalized = Paths::normalize($path);
        if ($normalized === null || $normalized !== $path) {
            throw new InvalidArgumentException("{$path} is not a path inside the site.");
        }

        return $this->site.'/'.$normalized;
    }

    /**
     * @return list<string>
     */
    private function filesUnder(string $folder): array
    {
        if (! is_dir($folder)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = (string) $file;
        }

        return $files;
    }
}
