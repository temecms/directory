<?php

declare(strict_types=1);

namespace Teme\Directory\Index;

/**
 * What one poll found: releases waiting for the install check, the images to
 * publish, and the repositories that could not be read.
 */
final class PollReport
{
    /**
     * @param  list<array{name: string, version: string, tag: string, kind: string, code: string|null, archive: string, teme: list<string>}>  $pending
     * @param  array<string, string>  $assets  Published path, then the image's bytes.
     * @param  list<string>  $problems
     */
    public function __construct(
        public readonly array $pending,
        public readonly array $assets,
        public readonly array $problems,
    ) {}
}
