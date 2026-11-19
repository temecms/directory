<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Teme\Directory\Index\State;
use Teme\Directory\Json;
use Teme\Directory\Signing\Signer;
use Teme\Directory\Tests\Support\Records;

final class BuildCommandTest extends TestCase
{
    private string $root;

    /** @var array{secret: string, public: string} */
    private array $pair;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/directory-cli-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/site', 0777, true);
        $state = State::empty();
        $state->put(Records::package());
        $state->save($this->root.'/state');
        $this->pair = Signer::generateKeyPair();
    }

    public function test_build_signs_a_new_index_that_verifies_and_counts_up(): void
    {
        [$code, $output] = $this->directory('build', ['DIRECTORY_SIGNING_KEY' => $this->pair['secret'], 'DIRECTORY_PUBLIC_KEY' => $this->pair['public']]);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Published index 1.', $output);
        self::assertSame(1, Json::decode((string) file_get_contents($this->root.'/site/index.json'))['sequence']);

        [$code, $output] = $this->directory('verify', ['DIRECTORY_PUBLIC_KEY' => $this->pair['public']]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('The index verifies.', $output);
    }

    public function test_an_unchanged_directory_is_not_published_again(): void
    {
        $this->directory('build', ['DIRECTORY_SIGNING_KEY' => $this->pair['secret']]);

        [$code, $output] = $this->directory('build', ['DIRECTORY_SIGNING_KEY' => $this->pair['secret']]);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Nothing new to publish.', $output);
        self::assertSame(1, State::load($this->root.'/state')->sequence());
    }

    public function test_build_refuses_without_a_key_and_never_prints_one(): void
    {
        [$code, $output] = $this->directory('build', ['DIRECTORY_SIGNING_KEY' => '']);
        self::assertSame(1, $code);
        self::assertStringContainsString('DIRECTORY_SIGNING_KEY is not set.', $output);

        [$code, $output] = $this->directory('build', ['DIRECTORY_SIGNING_KEY' => $this->pair['secret'], 'DIRECTORY_PUBLIC_KEY' => Signer::generateKeyPair()['public']]);
        self::assertSame(1, $code);
        self::assertStringContainsString('The signing key does not match DIRECTORY_PUBLIC_KEY.', $output);
        self::assertStringNotContainsString($this->pair['secret'], $output);
        self::assertFileDoesNotExist($this->root.'/site/index.json');
    }

    public function test_verify_refuses_an_index_signed_by_another_key(): void
    {
        $this->directory('build', ['DIRECTORY_SIGNING_KEY' => $this->pair['secret']]);

        [$code, $output] = $this->directory('verify', ['DIRECTORY_PUBLIC_KEY' => Signer::generateKeyPair()['public']]);

        self::assertSame(1, $code);
        self::assertStringContainsString('The signature does not match index.json.', $output);
    }

    /**
     * @param  array<string, string>  $env
     * @return array{int, string}
     */
    private function directory(string $command, array $env): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/bin/directory', $command, '--state', $this->root.'/state', '--site', $this->root.'/site'],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            null,
            array_merge(['PATH' => (string) getenv('PATH')], $env),
        );
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }
}
