<?php

declare(strict_types=1);

namespace Teme\Directory\Index;

use DateTimeImmutable;

/**
 * When to publish a new index: at once when anything a page shows changed,
 * within a day for new star and issue counts, and every week regardless, so
 * a published index never reaches its fourteen-day expiry.
 */
final class Publication
{
    public const FIGURES_AFTER = 'PT24H';

    public const RESIGN_AFTER = 'P7D';

    /**
     * Whether the build should be published.
     *
     * @param  array{content: string, figures: string, at: string}|null  $last
     */
    public static function due(?array $last, string $contentHash, string $figuresHash, DateTimeImmutable $now): bool
    {
        if ($last === null || $last['content'] !== $contentHash) {
            return true;
        }

        $at = new DateTimeImmutable($last['at']);
        if ($at->add(new \DateInterval(self::RESIGN_AFTER)) <= $now) {
            return true;
        }

        return $last['figures'] !== $figuresHash && $at->add(new \DateInterval(self::FIGURES_AFTER)) <= $now;
    }
}
