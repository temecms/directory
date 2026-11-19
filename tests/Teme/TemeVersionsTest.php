<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Teme;

use PHPUnit\Framework\TestCase;
use Teme\Directory\Teme\TemeVersions;

final class TemeVersionsTest extends TestCase
{
    private TemeVersions $versions;

    protected function setUp(): void
    {
        $this->versions = TemeVersions::fromTags(['v1.0.0', 'v1.0.3', 'v1.1.0', '1.2.0-beta.1', 'v1.2.0', 'v1.2.1', 'nightly', 'v0.7.0-alpha.1']);
    }

    public function test_works_with_lists_each_minor_series_with_a_release_inside_the_range(): void
    {
        self::assertSame(['1.1', '1.2'], $this->versions->worksWith('^1.1'));
        self::assertSame(['1.0', '1.1', '1.2'], $this->versions->worksWith('>=1.0 <2.0'));
        self::assertSame(['1.0'], $this->versions->worksWith('~1.0.2'));
        self::assertSame(['0.7'], $this->versions->worksWith('^0.7'));
    }

    public function test_a_range_that_matches_nothing_or_cannot_be_read_works_with_nothing(): void
    {
        self::assertSame([], $this->versions->worksWith('^3.0'));
        self::assertSame([], $this->versions->worksWith('not a range'));
        self::assertSame([], $this->versions->worksWith(null));
    }

    public function test_the_versions_to_install_on_are_the_newest_of_each_series_in_range(): void
    {
        self::assertSame(['v1.1.0', 'v1.2.1'], $this->versions->tagsToTest('^1.1'));
        self::assertSame(['v1.0.3'], $this->versions->tagsToTest('1.0.*'));
    }
}
