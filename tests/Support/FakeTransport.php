<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Support;

use Teme\Directory\Review\Transport;

/**
 * Records requests instead of sending them.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, headers: list<string>, body: string}> */
    public array $requests = [];

    /** @var array{status: int, body: string} */
    public array $answer = ['status' => 500, 'body' => ''];

    public function post(string $url, array $headers, string $body): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

        return $this->answer;
    }
}
