<?php

declare(strict_types=1);

namespace Teme\Directory\Listings;

use Teme\Directory\Json;

/**
 * Checks one listing file and says what is wrong with it in plain words.
 */
final class ListingValidator
{
    public const PATH = '#^listings/([a-z0-9]([_.-]?[a-z0-9]+)*)/([a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*)\.json$#';

    public const REPOSITORY = '#^https://github\.com/([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))/([A-Za-z0-9._-]{1,100})$#';

    private const FIELDS = ['repository', 'kind', 'categories', 'picked'];

    public function __construct(private readonly Categories $categories) {}

    /**
     * What is wrong with the listing, or nothing.
     *
     * @param  string  $path  The file's path from the repository's root.
     * @param  string|null  $before  The file as it was before this change, if it existed.
     * @param  bool  $byMaintainer  Whether a maintainer made the change.
     * @return list<string>
     */
    public function validate(string $path, string $contents, ?string $before, bool $byMaintainer): array
    {
        $problems = [];
        if (preg_match(self::PATH, $path) !== 1) {
            $problems[] = 'Name the file listings/<vendor>/<name>.json, after the package\'s Composer name, in lower case.';
        }

        $listing = Json::decode($contents);
        if ($listing === null || array_is_list($listing) && $listing !== []) {
            return [...$problems, 'The file is not valid JSON.'];
        }

        foreach (array_keys($listing) as $key) {
            if (! in_array($key, [...self::FIELDS, '$schema'], true)) {
                $problems[] = "Unknown field: {$key}.";
            }
        }
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $listing)) {
                $problems[] = "{$field} is missing.";
            }
        }

        if (array_key_exists('repository', $listing) && (! is_string($listing['repository']) || preg_match(self::REPOSITORY, $listing['repository']) !== 1)) {
            $problems[] = 'repository must be a public GitHub address, such as https://github.com/<owner>/<repo>.';
        }

        $kind = $listing['kind'] ?? null;
        if (array_key_exists('kind', $listing) && ! in_array($kind, ['plugin', 'theme'], true)) {
            $problems[] = 'kind must be plugin or theme.';
        }

        if (array_key_exists('categories', $listing)) {
            $problems = [...$problems, ...$this->categoryProblems($listing['categories'], is_string($kind) ? $kind : null)];
        }

        if (array_key_exists('picked', $listing) && ! is_bool($listing['picked'])) {
            $problems[] = 'picked must be true or false.';
        } elseif (($listing['picked'] ?? false) === true && ! $byMaintainer && (Json::decode((string) $before)['picked'] ?? false) !== true) {
            $problems[] = 'Only the directory\'s maintainers set picked to true. Set it to false.';
        }

        return $problems;
    }

    /**
     * The listing as read from a file that validates.
     */
    public static function read(string $path, string $contents): Listing
    {
        preg_match(self::PATH, $path, $name);
        /** @var array{repository: string, kind: string, categories: list<string>, picked: bool} $listing */
        $listing = Json::decode($contents);
        preg_match(self::REPOSITORY, $listing['repository'], $repository);

        return new Listing($name[1].'/'.$name[3], $repository[1], $repository[2], $listing['kind'], array_values($listing['categories']), $listing['picked']);
    }

    /**
     * @return list<string>
     */
    private function categoryProblems(mixed $categories, ?string $kind): array
    {
        if (! is_array($categories) || ! array_is_list($categories) || $categories === [] || count($categories) > 3 || count(array_unique(array_filter($categories, 'is_string'))) !== count($categories)) {
            return ['categories must name one to three categories.'];
        }
        if (! in_array($kind, ['plugin', 'theme'], true)) {
            return [];
        }

        $problems = [];
        foreach ($categories as $category) {
            if (! $this->categories->has($kind, $category)) {
                $problems[] = "{$category} is not a {$kind} category. The {$kind} categories are: ".implode(', ', $this->categories->keys($kind)).'.';
            }
        }

        return $problems;
    }
}
