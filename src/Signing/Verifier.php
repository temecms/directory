<?php

declare(strict_types=1);

namespace Teme\Directory\Signing;

use SodiumException;

/**
 * Checks a signature against one or more public keys, so a key can be rotated
 * while sites still trust the old one.
 */
final class Verifier
{
    /**
     * @param  list<string>  $publicKeys  Base64 of 32-byte Ed25519 public keys.
     */
    public function __construct(private readonly array $publicKeys) {}

    /**
     * Whether any of the public keys made this signature over exactly these bytes.
     */
    public function verify(string $bytes, string $signatureBase64): bool
    {
        $signature = base64_decode(trim($signatureBase64), true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        foreach ($this->publicKeys as $publicKey) {
            $key = base64_decode(trim($publicKey), true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }
            try {
                if (sodium_crypto_sign_verify_detached($signature, $bytes, $key)) {
                    return true;
                }
            } catch (SodiumException) {
                continue;
            }
        }

        return false;
    }
}
