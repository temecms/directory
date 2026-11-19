<?php

declare(strict_types=1);

namespace Teme\Directory\Signing;

/**
 * Signs the index with the directory's Ed25519 key. The key is base64 of a
 * 32-byte seed (what make-signing-key stores) or of a 64-byte secret key.
 */
final class Signer
{
    private string $secretKey;

    private string $publicKey;

    public function __construct(string $secretBase64)
    {
        $raw = base64_decode(trim($secretBase64), true);

        $pair = match (true) {
            $raw === false => null,
            strlen($raw) === SODIUM_CRYPTO_SIGN_SEEDBYTES => sodium_crypto_sign_seed_keypair($raw),
            strlen($raw) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES => sodium_crypto_sign_keypair_from_secretkey_and_publickey($raw, substr($raw, 32)),
            default => null,
        };

        if ($pair === null) {
            throw new InvalidKey('The signing key must be base64 of a 32-byte Ed25519 seed or a 64-byte secret key.');
        }

        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
        if ($raw !== false) {
            sodium_memzero($raw);
        }
        sodium_memzero($pair);
    }

    /**
     * A new key pair: the secret as base64 of a 32-byte seed, the public key as
     * base64 of its 32 bytes.
     *
     * @return array{secret: string, public: string}
     */
    public static function generateKeyPair(): array
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $keys = ['secret' => base64_encode($seed), 'public' => base64_encode(sodium_crypto_sign_publickey($pair))];
        sodium_memzero($seed);
        sodium_memzero($pair);

        return $keys;
    }

    /**
     * Base64 of the 64-byte detached signature over exactly these bytes.
     */
    public function sign(string $bytes): string
    {
        return base64_encode(sodium_crypto_sign_detached($bytes, $this->secretKey));
    }

    /**
     * Base64 of the 32-byte public key that verifies this signer's signatures.
     */
    public function publicKey(): string
    {
        return base64_encode($this->publicKey);
    }
}
