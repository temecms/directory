<?php

declare(strict_types=1);

namespace Teme\Directory\Teme;

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Throwable;

/**
 * Teme's released versions, read from its tags, and which of them a package's
 * Teme range takes in.
 */
final class TemeVersions
{
    /**
     * @param  array<string, string>  $tags  Tag, then its normalised version, oldest first.
     */
    private function __construct(private readonly array $tags) {}

    /**
     * @param  list<string>  $tags  Teme's Git tags; tags that are not versions are ignored.
     */
    public static function fromTags(array $tags): self
    {
        $parser = new VersionParser;
        $versions = [];
        foreach ($tags as $tag) {
            try {
                $versions[$tag] = $parser->normalize($tag);
            } catch (Throwable) {
                continue;
            }
        }
        uasort($versions, 'version_compare');

        return new self($versions);
    }

    /**
     * The tags, oldest first.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return array_keys($this->tags);
    }

    /**
     * Each minor series, such as 1.2, with at least one release inside the range.
     *
     * @return list<string>
     */
    public function worksWith(?string $range): array
    {
        return array_values(array_unique(array_map(
            static fn (string $tag): string => self::series($tag),
            $this->matching($range),
        )));
    }

    /**
     * The newest release of each series inside the range: the Teme versions a
     * package is installed on to check it.
     *
     * @return list<string>
     */
    public function tagsToTest(?string $range): array
    {
        $newest = [];
        foreach ($this->matching($range) as $tag) {
            $newest[self::series($tag)] = $tag;
        }

        return array_values($newest);
    }

    /**
     * @return list<string>
     */
    private function matching(?string $range): array
    {
        $constraint = $this->constraint($range);
        if ($constraint === null) {
            return [];
        }

        $parser = new VersionParser;
        $matching = [];
        foreach ($this->tags as $tag => $normalized) {
            if ($constraint->matches($parser->parseConstraints($normalized))) {
                $matching[] = (string) $tag;
            }
        }

        return $matching;
    }

    private function constraint(?string $range): ?ConstraintInterface
    {
        if ($range === null || trim($range) === '') {
            return null;
        }
        try {
            return (new VersionParser)->parseConstraints($range);
        } catch (Throwable) {
            return null;
        }
    }

    private static function series(string $tag): string
    {
        preg_match('/^v?(\d+)\.(\d+)/', $tag, $match);

        return $match[1].'.'.$match[2];
    }
}
