<?php

declare(strict_types=1);

namespace Teme\Directory\Markdown;

/**
 * Copies an image a README shows into the directory's own assets, so pages
 * never load it from the author's repository or a third party.
 */
interface ImageCopier
{
    /**
     * The copy's address, or null when the image cannot be copied and must be
     * dropped.
     *
     * @param  string  $source  A path inside the package, or an https address.
     * @param  bool  $inPackage  Whether the source is a path inside the package.
     */
    public function copy(string $source, bool $inPackage): ?string;
}
