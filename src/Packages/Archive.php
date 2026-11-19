<?php

declare(strict_types=1);

namespace Teme\Directory\Packages;

use RuntimeException;
use Teme\Directory\Paths;
use ZipArchive;

/**
 * A release archive as GitHub serves it, with its checksums, read from memory.
 * Paths are relative to the package's root, below the archive's top folder.
 */
final class Archive
{
    /**
     * @param  array<string, string>  $files  Path from the package's root, then its bytes.
     */
    private function __construct(
        public readonly string $sha256,
        public readonly string $sha1,
        private readonly array $files,
    ) {}

    public static function fromBytes(string $bytes): self
    {
        $path = tempnam(sys_get_temp_dir(), 'release');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            unlink($path);
            throw new RuntimeException('The release archive is not a zip file.');
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! str_ends_with($name, '/')) {
                $names[$i] = $name;
            }
        }
        $top = self::topFolder($names);

        $files = [];
        foreach ($names as $index => $name) {
            $relative = Paths::normalize(substr($name, strlen($top)));
            if ($relative !== null) {
                $files[$relative] = (string) $zip->getFromIndex($index);
            }
        }
        $zip->close();
        unlink($path);

        return new self(hash('sha256', $bytes), sha1($bytes), $files);
    }

    /**
     * The file's bytes, or null when the package has no such file.
     */
    public function read(string $path): ?string
    {
        $normalized = Paths::normalize($path);

        return $normalized === null ? null : $this->files[$normalized] ?? null;
    }

    /**
     * Every file's path, from the package's root.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->files);
    }

    /**
     * @param  array<int, string>  $names
     */
    private static function topFolder(array $names): string
    {
        $first = reset($names);
        if ($first === false || ! str_contains($first, '/')) {
            return '';
        }
        $top = substr($first, 0, strpos($first, '/') + 1);
        foreach ($names as $name) {
            if (! str_starts_with($name, $top)) {
                return '';
            }
        }

        return $top;
    }
}
