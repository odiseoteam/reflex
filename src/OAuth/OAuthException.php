<?php

declare(strict_types=1);

namespace App\OAuth;

/**
 * An error the client is told about, with its RFC 6749 code.
 */
final class OAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($description);
    }

    /**
     * @return array{error: string, error_description: string}
     */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
