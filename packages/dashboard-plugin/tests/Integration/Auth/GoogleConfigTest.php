<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Auth;

use Defyn\Dashboard\Auth\GoogleConfig;
use WP_UnitTestCase;

/**
 * GoogleConfig::clientId() resolves the env constant first, then the wp-admin
 * option. The DEFYN_GOOGLE_CLIENT_ID constant may already be define()d by an
 * earlier test in the same process (define() is irreversible), so these tests
 * assert the option-read path conditionally — they only assert the option is
 * consulted when the constant is absent, which is the only deterministically
 * testable branch in a shared-process suite.
 */
final class GoogleConfigTest extends WP_UnitTestCase
{
    public function tearDown(): void
    {
        delete_option(GoogleConfig::OPTION_KEY);
        parent::tearDown();
    }

    public function testReturnsOptionWhenConstantAbsent(): void
    {
        if (defined('DEFYN_GOOGLE_CLIENT_ID') && (string) constant('DEFYN_GOOGLE_CLIENT_ID') !== '') {
            $this->markTestSkipped('DEFYN_GOOGLE_CLIENT_ID is already defined in this process; option-fallback path is non-deterministic.');
        }

        update_option(GoogleConfig::OPTION_KEY, '123-abc.apps.googleusercontent.com');
        $this->assertSame('123-abc.apps.googleusercontent.com', GoogleConfig::clientId());
    }

    public function testReturnsEmptyStringWhenNeitherConstantNorOptionSet(): void
    {
        if (defined('DEFYN_GOOGLE_CLIENT_ID') && (string) constant('DEFYN_GOOGLE_CLIENT_ID') !== '') {
            $this->markTestSkipped('DEFYN_GOOGLE_CLIENT_ID is already defined in this process; empty-option path is non-deterministic.');
        }

        delete_option(GoogleConfig::OPTION_KEY);
        $this->assertSame('', GoogleConfig::clientId());
    }

    public function testEnvConstantTakesPrecedenceOverOption(): void
    {
        // Cover the env-precedence branch deterministically: define the constant
        // (idempotent if a prior test already did), set a DIFFERENT option value,
        // and assert the constant wins regardless of the option.
        if (!defined('DEFYN_GOOGLE_CLIENT_ID')) {
            define('DEFYN_GOOGLE_CLIENT_ID', 'env-wins.apps.googleusercontent.com');
        }
        update_option(GoogleConfig::OPTION_KEY, 'option-loses.apps.googleusercontent.com');

        $this->assertSame((string) constant('DEFYN_GOOGLE_CLIENT_ID'), GoogleConfig::clientId());
        $this->assertNotSame('option-loses.apps.googleusercontent.com', GoogleConfig::clientId());
    }
}
