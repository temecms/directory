<?php

declare(strict_types=1);

namespace Teme\Directory\Review;

use Teme\Directory\Packages\Archive;

/**
 * The text a reviewer reads: a release's code, or what changed since the
 * listed release, cut at a size limit.
 */
final class Material
{
    private const TEXT = '/\.(php|phtml|js|mjs|cjs|ts|tsx|jsx|vue|json|ya?ml|neon|xml|sh|css|scss|twig|txt)$/i';

    private const CUT = "\n(cut here: the rest is over the size limit)\n";

    /**
     * Every text file of the release, each under its path, smallest first so
     * one large generated file does not crowd out the rest.
     */
    public static function fromArchive(Archive $archive, int $limit): string
    {
        $paths = self::textFiles($archive);
        usort($paths, static fn (string $a, string $b): int => [strlen((string) $archive->read($a)), $a] <=> [strlen((string) $archive->read($b)), $b]);

        $out = '';
        foreach ($paths as $path) {
            $out .= "=== {$path} ===\n".$archive->read($path)."\n";
        }

        return self::limit($out, $limit);
    }

    /**
     * A unified diff of the text files from the listed release to the new one.
     */
    public static function diff(Archive $old, Archive $new, int $limit): string
    {
        $paths = array_unique([...self::textFiles($old), ...self::textFiles($new)]);
        sort($paths);

        $out = '';
        foreach ($paths as $path) {
            $before = $old->read($path);
            $after = $new->read($path);
            if ($before === $after) {
                continue;
            }
            $out .= "=== {$path} ===\n".self::unified((string) $before, (string) $after)."\n";
        }

        return self::limit($out === '' ? "No code changed.\n" : $out, $limit);
    }

    /**
     * @return list<string>
     */
    private static function textFiles(Archive $archive): array
    {
        $paths = array_values(array_filter($archive->paths(), static fn (string $path): bool => preg_match(self::TEXT, $path) === 1
            && ! preg_match('#(^|/)(vendor|node_modules)/#', $path)
            && ! preg_match('/\.min\.(js|css)$/', $path)));
        sort($paths);

        return $paths;
    }

    private static function unified(string $before, string $after): string
    {
        $a = $before === '' ? [] : explode("\n", $before);
        $b = $after === '' ? [] : explode("\n", $after);
        $start = 0;
        while ($start < count($a) && $start < count($b) && $a[$start] === $b[$start]) {
            $start++;
        }
        $endA = count($a) - 1;
        $endB = count($b) - 1;
        while ($endA >= $start && $endB >= $start && $a[$endA] === $b[$endB]) {
            $endA--;
            $endB--;
        }

        $out = '@@ line '.($start + 1)." @@\n";
        for ($i = max(0, $start - 3); $i < $start; $i++) {
            $out .= ' '.$a[$i]."\n";
        }
        for ($i = $start; $i <= $endA; $i++) {
            $out .= '-'.$a[$i]."\n";
        }
        for ($i = $start; $i <= $endB; $i++) {
            $out .= '+'.$b[$i]."\n";
        }

        return $out;
    }

    private static function limit(string $text, int $limit): string
    {
        return strlen($text) <= $limit ? $text : substr($text, 0, $limit).self::CUT;
    }
}
