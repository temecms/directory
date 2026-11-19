<?php

declare(strict_types=1);

namespace Teme\Directory\Listings;

use RuntimeException;
use Teme\Directory\Json;

/**
 * The directory's fixed list of categories, one list for plugins and one for
 * themes, from categories.json.
 */
final class Categories
{
    /**
     * @param  array<string, array<string, string>>  $byKind  Kind, then category key, then its name.
     */
    public function __construct(private readonly array $byKind) {}

    public static function fromFile(string $path): self
    {
        $value = Json::decode((string) @file_get_contents($path));
        if ($value === null) {
            throw new RuntimeException("{$path} is not valid JSON.");
        }

        /** @var array<string, array<string, string>> $value */
        return new self($value);
    }

    /**
     * The category keys for a kind, in their order.
     *
     * @return list<string>
     */
    public function keys(string $kind): array
    {
        return array_keys($this->byKind[$kind] ?? []);
    }

    /**
     * Whether the key is one of the kind's categories.
     */
    public function has(string $kind, string $key): bool
    {
        return isset($this->byKind[$kind][$key]);
    }
}
