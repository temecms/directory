<?php

declare(strict_types=1);

namespace Teme\Directory;

/**
 * Relative paths inside a package, made safe to look up.
 */
final class Paths
{
    /**
     * The path from the package's root with ./ and ../ worked out, or null when
     * it is empty, absolute on another drive, or leaves the package.
     */
    public static function normalize(string $path): ?string
    {
        $path = str_replace('\\', '/', rawurldecode($path));
        if ($path === '' || str_contains($path, "\0") || preg_match('#^[A-Za-z]:#', $path) === 1) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }
}
