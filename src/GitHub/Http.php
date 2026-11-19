<?php

declare(strict_types=1);

namespace Teme\Directory\GitHub;

use RuntimeException;

/**
 * A small HTTPS client over curl, with a size limit and https only.
 */
final class Http
{
    /**
     * @param  list<string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $maxBytes = 64 * 1024 * 1024, int $timeout = 60): array
    {
        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Only https addresses are fetched.');
        }

        $received = '';
        $responseHeaders = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [...$headers, 'User-Agent: teme-directory'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$received, $maxBytes): int {
                $received .= $chunk;

                return strlen($received) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($ok === false) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            throw new RuntimeException(strlen($received) > $maxBytes ? "The answer from {$host} was too large." : "{$host} did not answer: {$error}");
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $received];
    }
}
