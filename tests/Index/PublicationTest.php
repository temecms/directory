<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Index;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Teme\Directory\Index\Publication;

final class PublicationTest extends TestCase
{
    private const NOW = '2026-11-19T19:00:00Z';

    public function test_the_first_build_is_published(): void
    {
        self::assertTrue(Publication::due(null, 'a', 'f', new DateTimeImmutable(self::NOW)));
    }

    public function test_a_change_in_content_is_published_at_once(): void
    {
        self::assertTrue(Publication::due($this->last('2026-11-19T18:45:00Z'), 'b', 'f', new DateTimeImmutable(self::NOW)));
    }

    public function test_nothing_new_is_not_published(): void
    {
        self::assertFalse(Publication::due($this->last('2026-11-19T18:45:00Z'), 'a', 'f', new DateTimeImmutable(self::NOW)));
    }

    public function test_new_star_and_issue_counts_wait_a_day(): void
    {
        self::assertFalse(Publication::due($this->last('2026-11-19T08:00:00Z'), 'a', 'g', new DateTimeImmutable(self::NOW)));
        self::assertTrue(Publication::due($this->last('2026-11-18T18:59:00Z'), 'a', 'g', new DateTimeImmutable(self::NOW)));
    }

    public function test_an_unchanged_index_is_signed_again_a_week_on_so_it_never_expires(): void
    {
        self::assertFalse(Publication::due($this->last('2026-11-13T19:00:00Z'), 'a', 'f', new DateTimeImmutable(self::NOW)));
        self::assertTrue(Publication::due($this->last('2026-11-12T19:00:00Z'), 'a', 'f', new DateTimeImmutable(self::NOW)));
    }

    /**
     * @return array{content: string, figures: string, at: string}
     */
    private function last(string $at): array
    {
        return ['content' => 'a', 'figures' => 'f', 'at' => $at];
    }
}
