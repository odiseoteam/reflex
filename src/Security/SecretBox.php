<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Symmetric encryption for secrets at rest, keyed from the app secret.
 */
final readonly class SecretBox
{
    private string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $appSecret)
    {
        if ('' === $appSecret) {
            throw new \LogicException('APP_SECRET must be set to encrypt credentials.');
        }

        $this->key = sodium_crypto_generichash('reflex.secret_box|'.$appSecret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): string
    {
        $decoded = base64_decode($encrypted, true);
        if (false === $decoded || \strlen($decoded) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Malformed encrypted value.');
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($decoded, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($decoded, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        if (false === $plaintext) {
            throw new \RuntimeException('Encrypted value cannot be decrypted with the current APP_SECRET.');
        }

        return $plaintext;
    }
}
