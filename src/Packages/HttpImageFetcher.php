<?php

declare(strict_types=1);

namespace Teme\Directory\Packages;

use Teme\Directory\GitHub\Http;
use Throwable;

/**
 * Fetches a README's https image, giving up past 2 MB.
 */
final class HttpImageFetcher implements ImageFetcher
{
    public function fetch(string $url): ?string
    {
        try {
            $answer = Http::request('GET', $url, maxBytes: PackageInspector::MAX_IMAGE_BYTES, timeout: 20);
        } catch (Throwable) {
            return null;
        }

        return $answer['status'] === 200 ? $answer['body'] : null;
    }
}
