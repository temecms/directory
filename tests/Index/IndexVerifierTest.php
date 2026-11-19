<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Index;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Teme\Directory\Index\Build;
use Teme\Directory\Index\IndexBuilder;
use Teme\Directory\Index\IndexVerifier;
use Teme\Directory\Signing\Signer;
use Teme\Directory\Signing\Verifier;
use Teme\Directory\Tests\Support\Records;

final class IndexVerifierTest extends TestCase
{
    /** @var array{secret: string, public: string} */
    private array $pair;

    private Build $build;

    private string $signature;

    protected function setUp(): void
    {
        $this->pair = Signer::generateKeyPair();
        $this->build = (new IndexBuilder)
            ->build([Records::package()], 5, new DateTimeImmutable('2026-11-19T19:00:00Z'));
        $this->signature = (new Signer($this->pair['secret']))->sign($this->build->files['index.json']);
    }

    public function test_a_signed_build_verifies(): void
    {
        self::assertSame([], $this->verify($this->build->files, $this->signature, 4, '2026-11-20T00:00:00Z'));
    }

    public function test_an_index_signed_by_another_key_is_refused(): void
    {
        $other = (new Signer(Signer::generateKeyPair()['secret']))->sign($this->build->files['index.json']);

        self::assertSame(['The signature does not match index.json.'], $this->verify($this->build->files, $other, 4, '2026-11-20T00:00:00Z'));
    }

    public function test_an_older_sequence_than_the_last_one_seen_is_refused(): void
    {
        self::assertSame(['The index is older than one already seen (5 is lower than 6).'], $this->verify($this->build->files, $this->signature, 6, '2026-11-20T00:00:00Z'));
        self::assertSame([], $this->verify($this->build->files, $this->signature, 5, '2026-11-20T00:00:00Z'));
    }

    public function test_an_expired_index_is_refused(): void
    {
        self::assertSame(['The index expired at 2026-12-03T19:00:00Z.'], $this->verify($this->build->files, $this->signature, 0, '2026-12-03T19:00:00Z'));
    }

    public function test_a_changed_detail_file_or_composer_repository_is_refused(): void
    {
        $files = $this->build->files;
        $files['packages/acme/seo-audit.json'] .= ' ';
        $files['composer/packages.json'] = '{}';

        self::assertSame([
            'composer/packages.json does not match its checksum.',
            'packages/acme/seo-audit.json does not match its checksum.',
        ], $this->verify($files, $this->signature, 0, '2026-11-20T00:00:00Z'));
    }

    public function test_a_missing_detail_file_is_refused(): void
    {
        $files = $this->build->files;
        unset($files['packages/acme/seo-audit.json']);

        self::assertSame(['packages/acme/seo-audit.json is missing.'], $this->verify($files, $this->signature, 0, '2026-11-20T00:00:00Z'));
    }

    /**
     * @param  array<string, string>  $files
     * @return list<string>
     */
    private function verify(array $files, string $signature, int $lastSequence, string $now): array
    {
        return (new IndexVerifier(new Verifier([$this->pair['public']])))->verify(
            $files['index.json'],
            $signature,
            static fn (string $path): ?string => $files[$path] ?? null,
            $lastSequence,
            new DateTimeImmutable($now),
        );
    }
}
