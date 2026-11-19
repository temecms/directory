<?php

declare(strict_types=1);

namespace Teme\Directory;

use JsonException;

/**
 * Reads and writes the directory's JSON, always in the same byte-for-byte form,
 * so a file that did not change hashes the same.
 */
final class Json
{
    /**
     * The value as pretty JSON with a trailing new line.
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * The JSON as an array, or null when it is not a JSON object or list.
     *
     * @return array<mixed>|null
     */
    public static function decode(string $json): ?array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($value) ? $value : null;
    }
}
