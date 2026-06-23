<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Auth;

use Defyn\Dashboard\Auth\UserProvisioner;
use WP_UnitTestCase;

final class UserProvisionerTest extends WP_UnitTestCase
{
    public function testLinksExistingUserByEmailAndStoresSub(): void
    {
        $existing = self::factory()->user->create(['user_email' => 'devs@defyn.com.au']);
        $id = (new UserProvisioner())->findOrCreate([
            'email' => 'devs@defyn.com.au', 'name' => 'Devs', 'sub' => 'sub-1',
        ]);
        $this->assertSame($existing, $id);
        $this->assertSame('sub-1', get_user_meta($id, 'defyn_google_sub', true));
    }

    public function testCreatesNewUserWhenEmailUnknown(): void
    {
        $id = (new UserProvisioner())->findOrCreate([
            'email' => 'newperson@defyn.com.au', 'name' => 'New Person', 'sub' => 'sub-2',
        ]);
        $this->assertGreaterThan(0, $id);
        $user = get_userdata($id);
        $this->assertSame('newperson@defyn.com.au', $user->user_email);
        $this->assertSame('New Person', $user->display_name);
        $this->assertSame('sub-2', get_user_meta($id, 'defyn_google_sub', true));
    }
}
