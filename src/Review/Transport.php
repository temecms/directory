<?php

declare(strict_types=1);

namespace Teme\Directory\Review;

/**
 * Sends one JSON request to a provider.
 */
interface Transport
{
    /**
     * @param  list<string>  $headers
     * @return array{status: int, body: string}
     */
    public function post(string $url, array $headers, string $body): array;
}
