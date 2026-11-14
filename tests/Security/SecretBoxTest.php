<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SecretBox;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $box = new SecretBox('app-secret');
        $encrypted = $box->encrypt('ghp_token');

        self::assertNotSame('ghp_token', $encrypted);
        self::assertSame('ghp_token', $box->decrypt($encrypted));
    }

    public function testUsesAFreshNonceEachTime(): void
    {
        $box = new SecretBox('app-secret');

        self::assertNotSame($box->encrypt('same'), $box->encrypt('same'));
    }

    public function testRejectsAnotherKey(): void
    {
        $encrypted = (new SecretBox('app-secret'))->encrypt('ghp_token');

        $this->expectException(\RuntimeException::class);
        (new SecretBox('other-secret'))->decrypt($encrypted);
    }

    public function testRejectsAnEmptySecret(): void
    {
        $this->expectException(\LogicException::class);
        new SecretBox('');
    }
}
