<?php

declare(strict_types=1);

namespace Teme\Directory\Packages;

/**
 * What each capability a plugin asks for means, in the words Teme's own
 * consent screen uses.
 */
final class Capabilities
{
    /**
     * The capability in plain words.
     *
     * @param  list<string>  $hosts  The hosts a plugin asking for http names.
     */
    public static function words(string $key, array $hosts): string
    {
        return match ($key) {
            'content.read' => 'Read your posts, pages and other content, including drafts',
            'media.read' => 'Read the media library',
            'http' => 'Connect to services outside this site: '.implode(', ', $hosts),
            default => $key,
        };
    }
}
