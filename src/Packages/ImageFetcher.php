<?php

declare(strict_types=1);

namespace Teme\Directory\Packages;

/**
 * Downloads an image a README shows from an https address.
 */
interface ImageFetcher
{
    /**
     * The image's bytes, or null when it cannot be fetched.
     */
    public function fetch(string $url): ?string;
}
