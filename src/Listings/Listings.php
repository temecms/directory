<?php

declare(strict_types=1);

namespace Teme\Directory\Listings;

/**
 * Every listing under listings/, read from disk.
 */
final class Listings
{
    /**
     * The listings that validate, and what is wrong with the rest.
     *
     * @return array{listings: list<Listing>, problems: list<string>}
     */
    public static function load(string $root, Categories $categories): array
    {
        $validator = new ListingValidator($categories);
        $listings = [];
        $problems = [];
        $files = glob($root.'/listings/*/*.json') ?: [];
        sort($files);

        foreach ($files as $file) {
            $path = substr($file, strlen($root) + 1);
            $contents = (string) file_get_contents($file);
            $found = $validator->validate($path, $contents, $contents, true);
            if ($found !== []) {
                $problems[] = $path.': '.implode(' ', $found);

                continue;
            }
            $listings[] = ListingValidator::read($path, $contents);
        }

        return ['listings' => $listings, 'problems' => $problems];
    }
}
