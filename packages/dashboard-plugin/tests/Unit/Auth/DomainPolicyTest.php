<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Auth;

use Defyn\Dashboard\Auth\DomainPolicy;
use PHPUnit\Framework\TestCase;

final class DomainPolicyTest extends TestCase
{
    public function testAllowedEmailMatchesDomainCaseInsensitively(): void
    {
        $this->assertTrue(DomainPolicy::isAllowedEmail('pradeep@defyn.com.au'));
        $this->assertTrue(DomainPolicy::isAllowedEmail('Devs@DEFYN.COM.AU'));
    }

    public function testRejectsOtherDomainsAndMalformed(): void
    {
        $this->assertFalse(DomainPolicy::isAllowedEmail('x@gmail.com'));
        $this->assertFalse(DomainPolicy::isAllowedEmail('x@evildefyn.com.au'));
        $this->assertFalse(DomainPolicy::isAllowedEmail('defyn.com.au'));
        $this->assertFalse(DomainPolicy::isAllowedEmail(''));
    }

    public function testHdMatch(): void
    {
        $this->assertTrue(DomainPolicy::isAllowedHd('defyn.com.au'));
        $this->assertFalse(DomainPolicy::isAllowedHd('gmail.com'));
        $this->assertFalse(DomainPolicy::isAllowedHd(''));
    }
}
