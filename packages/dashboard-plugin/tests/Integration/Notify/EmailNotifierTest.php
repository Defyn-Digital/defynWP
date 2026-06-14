<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Notify\EmailNotifier;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P3.1 — EmailNotifier integration tests.
 *
 * The WP test harness installs a MockPHPMailer as $GLOBALS['phpmailer'] which
 * captures calls to wp_mail() without sending. reset_phpmailer_instance() clears
 * the mock_sent buffer before each test; tests_retrieve_phpmailer_instance()->get_sent()
 * inspects what was captured.
 *
 * @group integration
 */
final class EmailNotifierTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_incidents');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_incidents");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    /**
     * Insert a site row owned by $userId and return its ID.
     * Mirrors the makeSite() helper pattern from IncidentsRepositoryTest.
     */
    private function makeSite(int $userId, string $label): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://example-' . microtime(true) . '.test',
            'label'      => $label,
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * Build a minimal open Incident DTO via Incident::fromRow().
     */
    private function makeIncident(int $siteId, string $startedAt = '2026-06-14 10:00:00', ?string $lastError = 'Connector returned status 500'): Incident
    {
        return Incident::fromRow([
            'id'                 => 1,
            'site_id'            => $siteId,
            'started_at'         => $startedAt,
            'ended_at'           => null,
            'duration_seconds'   => null,
            'last_error'         => $lastError,
            'down_alert_sent_at' => null,
            'up_alert_sent_at'   => null,
            'created_at'         => $startedAt,
        ]);
    }

    public function test_notify_down_sends_email_to_site_owner(): void
    {
        reset_phpmailer_instance();

        // Create a real WP user with a known email.
        $userId = self::factory()->user->create(['user_email' => 'owner@example.com']);

        // Seed a site row owned by that user.
        $siteId = $this->makeSite($userId, 'AcmeBlog');

        // Load Site via SitesRepository so we get a proper Site model.
        $site = (new SitesRepository())->findById($siteId);
        $this->assertNotNull($site, 'Site should be loadable from DB');

        // Build an open Incident DTO.
        $incident = $this->makeIncident($siteId);

        // Act.
        (new EmailNotifier())->notifyDown($site, $incident);

        // Assert email was captured.
        $mailer = tests_retrieve_phpmailer_instance();
        $sent   = $mailer->get_sent(0);
        $this->assertNotFalse($sent, 'Expected at least one email to be captured by MockPHPMailer');

        // Recipient should be the owner's email.
        $this->assertSame('owner@example.com', $sent->to[0][0]);

        // Subject should mention the site label.
        $this->assertStringContainsString('AcmeBlog', $sent->subject);

        // Subject should mention "down" (case-insensitive).
        $this->assertStringContainsStringIgnoringCase('down', $sent->subject);
    }

    public function test_notify_down_does_not_throw_when_wp_mail_fails(): void
    {
        reset_phpmailer_instance();

        $userId = self::factory()->user->create(['user_email' => 'owner2@example.com']);
        $siteId = $this->makeSite($userId, 'AcmeBlog');
        $site   = (new SitesRepository())->findById($siteId);
        $this->assertNotNull($site);

        $incident = $this->makeIncident($siteId);

        // Make wp_mail return false (simulates a failure) via pre_wp_mail filter.
        add_filter('pre_wp_mail', '__return_false');

        // Should NOT throw even if wp_mail is blocked.
        (new EmailNotifier())->notifyDown($site, $incident);

        remove_filter('pre_wp_mail', '__return_false');

        // If we reach here the notifier swallowed the failure as required.
        $this->assertTrue(true);
    }

    public function test_notify_ssl_expiring_composes_subject_with_ssl_and_day_count(): void
    {
        reset_phpmailer_instance();

        // Create a real WP user with a known email.
        $userId = self::factory()->user->create(['user_email' => 'sslowner@example.com']);

        // Seed a site row owned by that user.
        $siteId = $this->makeSite($userId, 'SecureSite');

        // Load Site via SitesRepository so we get a proper Site model.
        $site = (new SitesRepository())->findById($siteId);
        $this->assertNotNull($site, 'Site should be loadable from DB');

        // Act: call with a future expiry date and 14 days remaining.
        (new EmailNotifier())->notifySslExpiring($site, '2026-07-01 00:00:00', 14);

        // Assert email was captured.
        $mailer = tests_retrieve_phpmailer_instance();
        $sent   = $mailer->get_sent(0);
        $this->assertNotFalse($sent, 'Expected at least one email to be captured by MockPHPMailer');

        // Recipient should be the owner's email.
        $this->assertSame('sslowner@example.com', $sent->to[0][0]);

        // Subject must mention 'SSL'.
        $this->assertStringContainsString('SSL', $sent->subject);

        // Subject must mention the day count.
        $this->assertStringContainsString('14', $sent->subject);
    }
}
