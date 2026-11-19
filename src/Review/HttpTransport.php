<?php

declare(strict_types=1);

namespace Teme\Directory\Review;

use Teme\Directory\GitHub\Http;

/**
 * Sends a provider request over HTTPS.
 */
final class HttpTransport implements Transport
{
    public function post(string $url, array $headers, string $body): array
    {
        $answer = Http::request('POST', $url, [...$headers, 'Content-Type: application/json'], $body, 4 * 1024 * 1024, 180);

        return ['status' => $answer['status'], 'body' => $answer['body']];
    }
}
