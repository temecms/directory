<?php

declare(strict_types=1);

namespace Teme\Directory\Index;

use DateTimeImmutable;
use Teme\Directory\Json;
use Teme\Directory\Signing\Verifier;
use Throwable;

/**
 * Checks a published index the way a Teme site does before trusting it: the
 * signature, the sequence, the expiry, and every file's checksum.
 */
final class IndexVerifier
{
    public function __construct(private readonly Verifier $verifier) {}

    /**
     * What is wrong with the index, or nothing.
     *
     * @param  callable(string): ?string  $read  Reads a published file by its path, or gives null.
     * @return list<string>
     */
    public function verify(string $index, string $signature, callable $read, int $lastSequence, DateTimeImmutable $now): array
    {
        if (! $this->verifier->verify($index, $signature)) {
            return ['The signature does not match index.json.'];
        }

        $data = Json::decode($index);
        if ($data === null || ($data['format'] ?? null) !== IndexBuilder::FORMAT) {
            return ['index.json is not an index this tool reads.'];
        }

        $problems = [];
        $sequence = (int) ($data['sequence'] ?? -1);
        if ($sequence < $lastSequence) {
            $problems[] = "The index is older than one already seen ({$sequence} is lower than {$lastSequence}).";
        }

        try {
            $expires = new DateTimeImmutable((string) ($data['expires'] ?? ''));
        } catch (Throwable) {
            $expires = null;
        }
        if ($expires === null || $expires <= $now) {
            $problems[] = 'The index expired at '.($data['expires'] ?? 'an unknown time').'.';
        }

        $files = [[$data['composer']['path'] ?? '', $data['composer']['sha256'] ?? '']];
        foreach ($data['packages'] ?? [] as $package) {
            $files[] = [$package['detail'] ?? '', $package['detail_sha256'] ?? ''];
        }
        foreach ($files as [$path, $sha256]) {
            $bytes = $read((string) $path);
            if ($bytes === null) {
                $problems[] = "{$path} is missing.";
            } elseif (! hash_equals((string) $sha256, hash('sha256', $bytes))) {
                $problems[] = "{$path} does not match its checksum.";
            }
        }

        return $problems;
    }
}
