<?php

declare(strict_types=1);

namespace Teme\Directory\Packages;

/**
 * What was read from one release and how its checks went: the page's fields,
 * the images to publish, and each check in plain words.
 */
final class Inspection
{
    /**
     * @param  array<string, mixed>  $composer  composer.json as written.
     * @param  array<string, mixed>  $page  The fields a directory page shows.
     * @param  list<string>  $capabilities
     * @param  list<string>  $hosts
     * @param  list<string>  $worksWith  Teme's minor series, such as 1.2.
     * @param  list<string>  $temeTags  The Teme versions to install the release on.
     * @param  array<string, string>  $assets  Published path, then the image's bytes.
     * @param  list<array{passed: bool, words: string}>  $checks
     */
    public function __construct(
        public readonly array $composer,
        public readonly array $page,
        public readonly array $capabilities,
        public readonly array $hosts,
        public readonly ?string $temeRange,
        public readonly array $worksWith,
        public readonly array $temeTags,
        public readonly ?string $code,
        public readonly array $assets,
        public readonly array $checks,
    ) {}

    /**
     * Whether every check passed.
     */
    public function passed(): bool
    {
        return $this->failures() === [];
    }

    /**
     * The checks that failed, in plain words.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        return array_values(array_map(
            static fn (array $check): string => $check['words'],
            array_filter($this->checks, static fn (array $check): bool => ! $check['passed']),
        ));
    }
}
