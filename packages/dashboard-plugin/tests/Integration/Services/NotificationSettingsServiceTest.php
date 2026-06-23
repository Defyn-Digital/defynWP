<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\NotificationSettingsService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * Migration test: NotificationSettingsService::migrateLegacyToShared().
 *
 * Mirrors BrandingServiceTest::testMigratesLegacyOwnerMetaIntoSharedOption().
 *
 * @group integration
 */
final class NotificationSettingsServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option('defyn_notify_migrated');
        delete_option('defyn_slack_webhook_url');
        delete_option('defyn_alert_email');
    }

    protected function tearDown(): void
    {
        delete_option('defyn_notify_migrated');
        delete_option('defyn_slack_webhook_url');
        delete_option('defyn_alert_email');
        parent::tearDown();
    }

    public function testMigratesLegacySlackWebhookFromUserMeta(): void
    {
        update_user_meta(1, 'defyn_slack_webhook_url', 'https://hooks.slack.com/services/L/E/gacy');

        NotificationSettingsService::migrateLegacyToShared();

        $this->assertSame(
            'https://hooks.slack.com/services/L/E/gacy',
            (string) get_option('defyn_slack_webhook_url', '')
        );
    }

    public function testSeedsAlertEmailFromUser1Email(): void
    {
        // Ensure user 1 exists with a known email.
        $user1 = get_userdata(1);
        $this->assertNotFalse($user1, 'User 1 must exist in the test environment');

        NotificationSettingsService::migrateLegacyToShared();

        $this->assertSame(
            (string) $user1->user_email,
            (string) get_option('defyn_alert_email', '')
        );
    }

    public function testSetsMigratedFlag(): void
    {
        NotificationSettingsService::migrateLegacyToShared();

        $this->assertSame('1', (string) get_option('defyn_notify_migrated'));
    }

    public function testMigrationIsIdempotent(): void
    {
        // Run once — seeds the options.
        NotificationSettingsService::migrateLegacyToShared();

        // Override the option to simulate later changes.
        update_option('defyn_slack_webhook_url', 'https://hooks.slack.com/services/N/E/w');

        // Run again — guard must prevent overwriting.
        NotificationSettingsService::migrateLegacyToShared();

        $this->assertSame(
            'https://hooks.slack.com/services/N/E/w',
            (string) get_option('defyn_slack_webhook_url', ''),
            'Second run must not overwrite the already-migrated option'
        );
    }

    public function testDoesNotOverwriteExistingSharedWebhookOption(): void
    {
        // Shared option already set (e.g. operator configured it post-migration).
        update_option('defyn_slack_webhook_url', 'https://hooks.slack.com/services/E/X/isting');
        update_user_meta(1, 'defyn_slack_webhook_url', 'https://hooks.slack.com/services/O/L/d');

        NotificationSettingsService::migrateLegacyToShared();

        // Legacy meta must NOT overwrite the already-present shared option.
        $this->assertSame(
            'https://hooks.slack.com/services/E/X/isting',
            (string) get_option('defyn_slack_webhook_url', '')
        );
    }
}
