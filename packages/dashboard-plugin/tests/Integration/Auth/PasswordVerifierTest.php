<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Auth;

use Defyn\Dashboard\Auth\Exceptions\InvalidCredentialsException;
use Defyn\Dashboard\Auth\PasswordVerifier;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class PasswordVerifierTest extends WP_UnitTestCase
{
    public function testVerifyReturnsUserIdForValidCredentials(): void
    {
        $userId = self::factory()->user->create([
            'user_email' => 'test@defyn.test',
            'user_login' => 'testuser',
            'user_pass'  => 'correct-horse-battery-staple',
        ]);
        $verifier = new PasswordVerifier();

        $result = $verifier->verify('test@defyn.test', 'correct-horse-battery-staple');

        self::assertSame($userId, $result);
    }

    public function testVerifyAcceptsLoginInsteadOfEmail(): void
    {
        $userId = self::factory()->user->create([
            'user_email' => 'test2@defyn.test',
            'user_login' => 'testuser2',
            'user_pass'  => 'super-secret-password',
        ]);
        $verifier = new PasswordVerifier();

        $result = $verifier->verify('testuser2', 'super-secret-password');

        self::assertSame($userId, $result);
    }

    public function testVerifyThrowsOnWrongPassword(): void
    {
        self::factory()->user->create([
            'user_email' => 'test3@defyn.test',
            'user_pass'  => 'right-password',
        ]);
        $verifier = new PasswordVerifier();

        $this->expectException(InvalidCredentialsException::class);
        $verifier->verify('test3@defyn.test', 'wrong-password');
    }

    public function testVerifyThrowsOnUnknownUser(): void
    {
        $verifier = new PasswordVerifier();

        $this->expectException(InvalidCredentialsException::class);
        $verifier->verify('nonexistent@defyn.test', 'whatever');
    }

    public function testVerifyThrowsOnEmptyCredentials(): void
    {
        $verifier = new PasswordVerifier();

        $this->expectException(InvalidCredentialsException::class);
        $verifier->verify('', '');
    }
}
