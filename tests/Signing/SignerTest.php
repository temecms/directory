<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Signing;

use PHPUnit\Framework\TestCase;
use Teme\Directory\Signing\InvalidKey;
use Teme\Directory\Signing\Signer;
use Teme\Directory\Signing\Verifier;

final class SignerTest extends TestCase
{
    public function test_a_new_key_pair_signs_and_its_public_key_verifies(): void
    {
        $pair = Signer::generateKeyPair();
        $signer = new Signer($pair['secret']);

        $signature = $signer->sign('{"format":1}');

        self::assertSame($pair['public'], $signer->publicKey());
        self::assertSame(64, strlen(base64_decode($signature, true)));
        self::assertTrue((new Verifier([$pair['public']]))->verify('{"format":1}', $signature));
    }

    public function test_the_signature_covers_the_exact_bytes(): void
    {
        $pair = Signer::generateKeyPair();
        $signature = (new Signer($pair['secret']))->sign("{\"format\":1}\n");

        $verifier = new Verifier([$pair['public']]);

        self::assertFalse($verifier->verify('{"format":1}', $signature));
        self::assertFalse($verifier->verify("{\"format\":2}\n", $signature));
    }

    public function test_another_key_does_not_verify(): void
    {
        $signature = (new Signer(Signer::generateKeyPair()['secret']))->sign('x');

        self::assertFalse((new Verifier([Signer::generateKeyPair()['public']]))->verify('x', $signature));
    }

    public function test_any_of_several_public_keys_verifies_so_keys_can_rotate(): void
    {
        $old = Signer::generateKeyPair();
        $new = Signer::generateKeyPair();
        $signature = (new Signer($new['secret']))->sign('x');

        self::assertTrue((new Verifier([$old['public'], $new['public']]))->verify('x', $signature));
    }

    public function test_a_64_byte_secret_key_is_accepted_as_well_as_a_32_byte_seed(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $public = base64_encode(sodium_crypto_sign_publickey($pair));

        $signer = new Signer($secret);

        self::assertSame($public, $signer->publicKey());
        self::assertTrue((new Verifier([$public]))->verify('x', $signer->sign('x')));
    }

    public function test_a_malformed_secret_is_refused_without_repeating_it(): void
    {
        try {
            new Signer('not-a-key-but-a-secret-looking-value');
            self::fail('The malformed key was accepted.');
        } catch (InvalidKey $exception) {
            self::assertStringNotContainsString('secret-looking', $exception->getMessage());
        }
    }

    public function test_a_malformed_signature_or_public_key_does_not_verify(): void
    {
        $pair = Signer::generateKeyPair();

        self::assertFalse((new Verifier([$pair['public']]))->verify('x', 'not base64!'));
        self::assertFalse((new Verifier(['short']))->verify('x', (new Signer($pair['secret']))->sign('x')));
    }
}
