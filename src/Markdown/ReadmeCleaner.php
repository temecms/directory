<?php

declare(strict_types=1);

namespace Teme\Directory\Markdown;

use Teme\Directory\Paths;

/**
 * Cleans a package's README.md (or CHANGELOG.md) for the directory: drops the
 * first heading, raw HTML and badges, keeps headings between levels two and
 * four, points relative links at the release tag, copies images, and cuts the
 * text at 100 KB. Code is left exactly as written.
 */
final class ReadmeCleaner
{
    public const MAX_BYTES = 100 * 1024;

    public const MAX_IMAGES = 10;

    private const REMOVED = "\x01";

    private const URL = '(<[^>\n]*>|(?:[^\s()<>]|\((?:[^\s()<>])*\))+)';

    private const TITLE = '(?:\s+("[^"\n]*"|\'[^\'\n]*\'|\([^)\n]*\)))?';

    private const TEXT = '((?:[^\[\]\n]|\[(?:[^\[\]\n]|\[[^\[\]\n]*\])*\])*)';

    private const BADGE_HOSTS = [
        'img.shields.io', 'shields.io', 'badgen.net', 'badge.fury.io', 'poser.pugx.org', 'travis-ci.org',
        'travis-ci.com', 'codecov.io', 'coveralls.io', 'circleci.com', 'app.codacy.com', 'api.codacy.com',
        'sonarcloud.io', 'ci.appveyor.com', 'app.netlify.com', 'img.badgesize.io', 'flat.badgen.net',
    ];

    private int $images = 0;

    /** @var array<string, string> */
    private array $references = [];

    private Location $at;

    public function __construct(private readonly ImageCopier $copier) {}

    /**
     * The cleaned Markdown, ending with one new line, or an empty string.
     */
    public function clean(string $markdown, Location $at, bool $dropFirstHeading = true): string
    {
        $this->at = $at;
        $this->images = 0;
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $this->references = $this->references($lines);

        $out = [];
        $fence = null;
        $seenHeading = false;
        $lastKind = 'blank';
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if ($fence !== null) {
                $out[] = ['code', $line];
                if (preg_match('/^ {0,3}('.preg_quote($fence[0], '/').'{'.strlen($fence).',})\s*$/', $line) === 1) {
                    $fence = null;
                }

                continue;
            }

            if (preg_match('/^ {0,3}(`{3,}(?=[^`]*$)|~{3,})/', $line, $open) === 1) {
                $fence = $open[1];
                $out[] = ['code', $line];
                $lastKind = 'code';

                continue;
            }

            if (trim($line) === '') {
                $out[] = ['blank', ''];
                if ($lastKind !== 'list' && $lastKind !== 'icode') {
                    $lastKind = 'blank';
                }

                continue;
            }

            if (preg_match('/^( {4}|\t)/', $line) === 1 && ($lastKind === 'icode' || $lastKind === 'blank' && ($out === [] || end($out)[0] === 'blank'))) {
                $out[] = ['code', $line];
                $lastKind = 'icode';

                continue;
            }

            if (preg_match('/^ {0,3}<(script|pre|style|textarea)(\s|>|$)/i', $line, $raw) === 1) {
                $i = $this->skipUntil($lines, $i, '</'.strtolower($raw[1]).'>');
                $out[] = ['removed', ''];

                continue;
            }

            if (preg_match('/^ {0,3}<!--/', $line) === 1) {
                $i = $this->skipUntil($lines, $i, '-->');
                $out[] = ['removed', ''];

                continue;
            }

            if (preg_match('/^ {0,3}<\/?[A-Za-z][A-Za-z0-9-]*(\s|\/?>|$)/', $line) === 1) {
                for (; $i < $count && trim($lines[$i]) !== ''; $i++) {
                    $text = trim($this->stripTags($lines[$i]));
                    $out[] = $text === '' ? ['removed', ''] : ['text', $text];
                }
                $i--;
                $lastKind = 'para';

                continue;
            }

            $next = $lines[$i + 1] ?? '';
            if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $atx) === 1) {
                $level = strlen($atx[1]);
                $text = $atx[2] ?? '';
            } elseif ($lastKind !== 'para' && $lastKind !== 'list' && preg_match('/^ {0,3}=+[ \t]*$/', $next) === 1) {
                $level = 1;
                $text = trim($line);
                $i++;
            } else {
                $level = 0;
                $text = '';
            }

            if ($level > 0) {
                if (! $seenHeading && $level === 1 && $dropFirstHeading) {
                    $seenHeading = true;
                    $out[] = ['removed', ''];
                    $lastKind = 'blank';

                    continue;
                }
                $seenHeading = true;
                $level = max(2, min(4, $level));
                $out[] = ['text', str_repeat('#', $level).' '.$this->inline($text)];
                $lastKind = 'heading';

                continue;
            }

            if (preg_match('/^ {0,3}\[([^\]\n]+)\]:[ \t]*'.self::URL.'(.*)$/', $line, $definition) === 1 && ! str_starts_with($definition[1], '^')) {
                $url = $this->link($definition[2]);
                $out[] = $url === null ? ['removed', ''] : ['text', "[{$definition[1]}]: {$url}{$definition[3]}"];
                $lastKind = 'para';

                continue;
            }

            $isList = preg_match('/^\s*([-*+]|\d{1,9}[.)])\s/', $line) === 1;
            if ($isList) {
                $lastKind = 'list';
            } elseif ($lastKind !== 'list' || preg_match('/^\s/', $line) !== 1) {
                $lastKind = 'para';
            }
            $out[] = ['text', $this->inline($line)];
        }

        return $this->cut($this->join($out));
    }

    /**
     * The first paragraph as plain text, for a summary, or null when there is none.
     */
    public static function firstParagraph(string $markdown): ?string
    {
        $paragraph = [];
        $fence = false;
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line) === 1) {
                $fence = ! $fence;

                continue;
            }
            if ($fence) {
                continue;
            }
            if (trim($line) === '') {
                if ($paragraph !== []) {
                    break;
                }

                continue;
            }
            if ($paragraph === [] && preg_match('/^\s*(#|>|[-*+]\s|\d+[.)]\s|\||!\[|\[[^\]]+\]:|( {4}|\t))/', $line) === 1) {
                continue;
            }
            $paragraph[] = trim($line);
        }

        if ($paragraph === []) {
            return null;
        }

        $text = implode(' ', $paragraph);
        $text = (string) preg_replace('/!\['.self::TEXT.'\]\([^)]*\)/', '', $text);
        $text = (string) preg_replace('/\['.self::TEXT.'\]\([^)]*\)/', '$1', $text);
        $text = (string) preg_replace('/\['.self::TEXT.'\]\[[^\]]*\]/', '$1', $text);
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = (string) preg_replace('/(?<![\w*])[*_](\S(?:.*?\S)?)[*_](?![\w*])/', '$1', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return $text === '' ? null : $text;
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, string>
     */
    private function references(array $lines): array
    {
        $references = [];
        foreach ($lines as $line) {
            if (preg_match('/^ {0,3}\[([^\]\n]+)\]:[ \t]*'.self::URL.'/', $line, $match) === 1) {
                $references[mb_strtolower(trim($match[1]))] ??= trim($match[2], '<>');
            }
        }

        return $references;
    }

    /**
     * @param  list<string>  $lines
     */
    private function skipUntil(array $lines, int $from, string $end): int
    {
        $count = count($lines);
        for ($i = $from; $i < $count; $i++) {
            if (stripos($lines[$i], $end) !== false && ($i > $from || stripos($lines[$i], $end) > 0)) {
                return $i;
            }
        }

        return $count - 1;
    }

    /**
     * One line of text with its badges, images, links and raw HTML dealt with.
     */
    private function inline(string $line): string
    {
        $kept = [];
        $keep = static function (string $value) use (&$kept): string {
            $kept[] = $value;

            return "\x02".(count($kept) - 1)."\x03";
        };

        $line = (string) preg_replace_callback('/(`+)(?!`).+?(?<!`)\1(?!`)/', static fn (array $m): string => $keep($m[0]), $line);
        $line = (string) preg_replace_callback('/<(?:https:\/\/[^\s<>]+|mailto:[^\s<>]+|[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)>/', static fn (array $m): string => $keep($m[0]), $line);

        $image = '!\['.self::TEXT.'\]\(\s*'.self::URL.self::TITLE.'\s*\)';
        $line = (string) preg_replace_callback('/\[\s*'.$image.'\s*\]\(\s*'.self::URL.self::TITLE.'\s*\)/', function (array $m) use ($keep): string {
            if ($this->isBadge(trim($m[2], '<>'))) {
                return self::REMOVED;
            }

            return $m[0];
        }, $line);

        $line = (string) preg_replace_callback('/'.$image.'/', fn (array $m): string => $this->image($m[1], $m[2], $m[3] ?? '', $keep), $line);
        $line = (string) preg_replace_callback('/!\['.self::TEXT.'\]\[([^\]\n]*)\]/', function (array $m) use ($keep): string {
            $label = mb_strtolower(trim($m[2] === '' ? $m[1] : $m[2]));

            return isset($this->references[$label]) ? $this->image($m[1], $this->references[$label], '', $keep) : self::REMOVED;
        }, $line);

        $line = (string) preg_replace_callback('/(?<!!)\['.self::TEXT.'\]\(\s*'.self::URL.self::TITLE.'\s*\)/', function (array $m) use ($keep): string {
            $text = $m[1];
            if (trim(str_replace(self::REMOVED, '', $text)) === '') {
                return self::REMOVED;
            }
            $url = $this->link($m[2]);
            if ($url === null) {
                return $text;
            }

            return $keep('['.$text.']('.$url.(isset($m[3]) && $m[3] !== '' ? ' '.$m[3] : '').')');
        }, $line);

        $line = $this->stripTags($line);

        while (preg_match('/\x02\d+\x03/', $line) === 1) {
            $line = (string) preg_replace_callback('/\x02(\d+)\x03/', static fn (array $m): string => $kept[(int) $m[1]], $line);
        }

        $line = (string) preg_replace('/^([ \t]*)'.self::REMOVED.'[ \t]*/', '$1', $line);
        $line = (string) preg_replace('/[ \t]*'.self::REMOVED.'[ \t]*$/', '', $line);

        return (string) preg_replace('/[ \t]*'.self::REMOVED.'[ \t]*/', ' ', $line);
    }

    /**
     * @param  callable(string): string  $keep
     */
    private function image(string $alt, string $url, string $title, callable $keep): string
    {
        $url = trim($url, '<>');
        if ($this->isBadge($url) || $this->images >= self::MAX_IMAGES) {
            return self::REMOVED;
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $scheme) === 1) {
            $copy = strtolower($scheme[1]) === 'https' ? $this->copier->copy($url, false) : null;
        } else {
            $path = Paths::normalize((string) preg_replace('/[?#].*$/', '', $url));
            $copy = $path === null || str_starts_with($url, '//') ? null : $this->copier->copy($path, true);
        }

        if ($copy === null) {
            return self::REMOVED;
        }
        $this->images++;

        return $keep('!['.$alt.']('.$copy.($title !== '' ? ' '.$title : '').')');
    }

    /**
     * The address a link should point at, or null when only its words are kept.
     */
    private function link(string $url): ?string
    {
        $url = trim($url, '<>');
        if (str_starts_with($url, '#')) {
            return $url;
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $scheme) === 1) {
            return in_array(strtolower($scheme[1]), ['https', 'mailto'], true) ? $url : null;
        }
        if (str_starts_with($url, '//')) {
            return null;
        }

        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $path = Paths::normalize((string) preg_replace('/\?.*$/', '', $url));

        return $path === null ? null : $this->at->blob($path).$fragment;
    }

    private function isBadge(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return in_array($host, self::BADGE_HOSTS, true)
            || str_ends_with($path, 'badge.svg')
            || str_contains($path, '/badge/')
            || str_contains($path, '/badges/')
            || $host === 'github.com' && str_contains($path, '/workflows/');
    }

    private function stripTags(string $text): string
    {
        $text = (string) preg_replace('/<!--.*?-->/s', '', $text);

        return (string) preg_replace('/<\/?[A-Za-z][A-Za-z0-9-]*(?:\s(?:[^>"\']|"[^"]*"|\'[^\']*\')*)?\/?>/', '', $text);
    }

    /**
     * @param  list<array{string, string}>  $lines
     */
    private function join(array $lines): string
    {
        $out = [];
        $blank = true;
        foreach ($lines as [$kind, $line]) {
            if ($kind === 'removed' || $kind === 'text' && trim($line) === '') {
                continue;
            }
            if ($kind === 'blank') {
                if (! $blank) {
                    $out[] = '';
                }
                $blank = true;

                continue;
            }
            $out[] = $line;
            $blank = false;
        }
        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }

        return $out === [] ? '' : implode("\n", $out)."\n";
    }

    private function cut(string $markdown): string
    {
        if (strlen($markdown) <= self::MAX_BYTES) {
            return $markdown;
        }

        $more = "[Read the rest on GitHub](".$this->at->blob('README.md').")\n";
        $limit = self::MAX_BYTES - strlen($more) - 8;
        $kept = '';
        $lastBreak = 0;
        $fence = false;
        foreach (explode("\n", $markdown) as $line) {
            if (strlen($kept) + strlen($line) + 1 > $limit) {
                break;
            }
            $kept .= $line."\n";
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line) === 1) {
                $fence = ! $fence;
            }
            if (! $fence && trim($line) === '') {
                $lastBreak = strlen($kept);
            }
        }

        if ($lastBreak > 0) {
            $kept = substr($kept, 0, $lastBreak);
        } elseif ($fence) {
            $kept .= "```\n\n";
        } else {
            $kept .= "\n";
        }

        return $kept.$more;
    }
}
