<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ConnectionAuth;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ConnectionInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Assert\NotBlank]
    #[Assert\Url(requireTld: false)]
    #[Assert\Length(max: 2048)]
    public string $url = '';

    public ConnectionAuth $auth = ConnectionAuth::OAuth;

    public ?string $token = null;

    #[Assert\Length(max: 255)]
    public ?string $oauthClientId = null;

    public ?string $oauthClientSecret = null;

    #[Assert\Url(requireTld: false)]
    #[Assert\Length(max: 2048)]
    public ?string $oauthIssuer = null;

    #[Assert\Callback]
    public function validateAuth(ExecutionContextInterface $context): void
    {
        if (ConnectionAuth::Bearer === $this->auth && null === $this->token) {
            $context->buildViolation('A bearer token is required.')->atPath('token')->addViolation();
        }

        if (ConnectionAuth::OAuth === $this->auth && (null === $this->oauthClientId) !== (null === $this->oauthIssuer)) {
            $context->buildViolation('A pre-registered client needs both its client ID and the issuer.')->atPath('oauthIssuer')->addViolation();
        }
    }
}
