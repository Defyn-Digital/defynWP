<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Auth;

use Defyn\Dashboard\Auth\GoogleIdTokenVerifier;
use Defyn\Dashboard\Auth\Exceptions\GoogleAuthException;
use PHPUnit\Framework\TestCase;

final class GoogleIdTokenVerifierTest extends TestCase
{
    private function verifier(array $claims): GoogleIdTokenVerifier
    {
        return new class('client-123.apps.googleusercontent.com', $claims) extends GoogleIdTokenVerifier {
            public function __construct(string $clientId, private array $stub) { parent::__construct($clientId); }
            protected function decode(string $idToken): array { return $this->stub; }
        };
    }

    private function goodClaims(array $over = []): array
    {
        return array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => 'client-123.apps.googleusercontent.com',
            'email' => 'pradeep@defyn.com.au',
            'email_verified' => true,
            'hd' => 'defyn.com.au',
            'sub' => '11122233344455566677',
            'name' => 'Pradeep',
        ], $over);
    }

    public function testValidWorkspaceTokenReturnsClaims(): void
    {
        $claims = $this->verifier($this->goodClaims())->verify('jwt');
        $this->assertSame('pradeep@defyn.com.au', $claims['email']);
        $this->assertSame('11122233344455566677', $claims['sub']);
    }

    public function testRejectsWrongAud(): void
    {
        $this->expectException(GoogleAuthException::class);
        $this->verifier($this->goodClaims(['aud' => 'someone-else']))->verify('jwt');
    }

    public function testRejectsWrongIssuer(): void
    {
        $this->expectException(GoogleAuthException::class);
        $this->verifier($this->goodClaims(['iss' => 'evil.example.com']))->verify('jwt');
    }

    public function testRejectsUnverifiedEmail(): void
    {
        $this->expectException(GoogleAuthException::class);
        $this->verifier($this->goodClaims(['email_verified' => false]))->verify('jwt');
    }

    public function testRejectsWrongHdWith403(): void
    {
        try {
            $this->verifier($this->goodClaims(['hd' => 'gmail.com', 'email' => 'x@gmail.com']))->verify('jwt');
            $this->fail('expected GoogleAuthException');
        } catch (GoogleAuthException $e) {
            $this->assertSame(403, $e->status);
            $this->assertSame('auth.google_domain', $e->errorCode);
        }
    }

    public function testRejectsHdMismatchEmail(): void
    {
        // hd says defyn but email domain differs → reject 403
        $this->expectException(GoogleAuthException::class);
        $this->verifier($this->goodClaims(['email' => 'x@gmail.com']))->verify('jwt');
    }
}
