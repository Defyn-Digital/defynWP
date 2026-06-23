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
 * Recipient is the team-wide `defyn_alert_email` option, falling back to `admin_email`.
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

        delete_option('defyn_alert_email');
    }

    protected function tearDown(): void
    {
        delete_option('defyn_alert_email');
        parent::tearDown();
    }

    /**
     * Insert a site row owned by $userId and return its ID.
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

    public function test_notify_down_sends_email_to_shared_alert_address(): void
    {
        reset_phpmailer_instance();

        // Seed the shared team-wide alert email option.
        update_option('defyn_alert_email', 'team-alerts@example.com');

        // Site is owned by a different user — recipient must NOT be the owner's email.
        $ownerId = self::factory()->user->create(['user_email' => 'site-owner@example.com']);
        $siteId  = $this->makeSite($ownerId, 'AcmeBlog');

        $site     = (new SitesRepository())->findById($siteId);
        $incident = $this->makeIncident($siteId);

        (new EmailNotifier())->notifyDown($site, $incident);

        $mailer = tests_retrieve_phpmailer_instance();
        $sent   = $mailer->get_sent(0);
        $this->assertNotFalse($sent, 'Expected at least one email to be captured by MockPHPMailer');

        // Recipient must be the shared option, NOT the site owner.
        $this->assertSame('team-alerts@example.com', $sent->to[0][0]);
        $this->assertStringContainsString('AcmeBlog', $sent->subject);
        $this->assertStringContainsStringIgnoringCase('down', $sent->subject);
    }

    public function test_notify_down_falls_back_to_admin_email_when_option_empty(): void
    {
        reset_phpmailer_instance();

        // defyn_alert_email is unset — should fall back to WP admin_email.
        $adminEmail = (string) get_option('admin_email');
        $this->assertNotEmpty($adminEmail, 'admin_email must be set in the test env');

        $userId = self::factory()->user->create(['user_email' => 'owner-not-used@example.com']);
        $siteId = $this->makeSite($userId, 'FallbackSite');
        $site   = (new SitesRepository())->findById($siteId);

        (new EmailNotifier())->notifyDown($site, $this->makeIncident($siteId));

        $mailer = tests_retrieve_phpmailer_instance();
        $sent   = $mailer->get_sent(0);
        $this->assertNotFalse($sent, 'Expected email to be captured via admin_email fallback');
        $this->assertSame($adminEmail, $sent->to[0][0]);
    }

    public function test_notify_down_does_not_throw_when_wp_mail_fails(): void
    {
        reset_phpmailer_instance();

        update_option('defyn_alert_email', 'alerts@example.com');

        $userId   = self::factory()->user->create(['user_email' => 'owner2@example.com']);
        $siteId   = $this->makeSite($userId, 'AcmeBlog');
        $site     = (new SitesRepository())->findById($siteId);
        $incident = $this->makeIncident($siteId);

        // Make wp_mail return false (simulates a failure) via pre_wp_mail filter.
        add_filter('pre_wp_mail', '__return_false');

        // Should NOT throw even if wp_mail is blocked.
        (new EmailNotifier())->notifyDown($site, $incident);

        remove_filter('pre_wp_mail', '__return_false');

        // If we reach here the notifier swallowed the failure as required.
        $this->assertTrue(true);
    }

    public function testNotifyNewVulnerabilitiesEmailSubjectAndBody(): void
    {
        reset_phpmailer_instance();

        update_option('defyn_alert_email', 'vuln-alerts@example.com');

        $userId = self::factory()->user->create(['user_email' => 'vulnowner@example.com']);
        $siteId = $this->makeSite($userId, 'VulnSite');
        $site   = (new SitesRepository())->findById($siteId);
        $this->assertNotNull($site, 'Site should be loadable from DB');

        $new = [
            ['type' => 'plugin', 'slug' => 'wp-file-manager', 'component_name' => 'WP File Manager', 'installed_version' => '6.0', 'severity' => 'critical', 'cve' => 'CVE-2024-1234', 'fixed_in' => '6.9'],
            ['type' => 'plugin', 'slug' => 'elementor', 'component_name' => 'Elementor', 'installed_version' => '3.18.2', 'severity' => 'high', 'cve' => null, 'fixed_in' => '3.18.3'],
        ];

        (new EmailNotifier())->notifyNewVulnerabilities($site, $new, ['critical' => 1, 'high' => 1, 'medium' => 0, 'low' => 0]);

        $mailer = tests_retrieve_phpmailer_instance();
        $sent   = $mailer->get_sent(0);
        $this->assertNotFalse($sent, 'Expected at least one email to be captured by MockPHPMailer');

        // Recipient must be the shared alert address.
        $this->assertSame('vuln-alerts@example.com', $sent->to[0][0]);
        $this->assertStringContainsString('2 new vulnerabilities on', $sent->subject);
        $this->assertStringContainsString('VulnSite', $sent->subject);
        $this->assertStringContainsString('WP File Manager', $sent->body);
        $this->assertStringContainsString('Elementor', $sent->body);
        $this->assertStringContainsString('CVE-2024-1234', $sent->body);
        $this->assertStringContainsString('Critical: 1', $sent->body);
    }

    public function test_notify_ssl_expiring_composes_subject_with_ssl_and_day_count(): void
    {
        reset_phpmailer_instance();

        update_option('defyn_alert_email', 'ssl-alerts@example.com');

        $userId = self::factory()->user->create(['user_email' => 'sslowner@example.com']);
        $siteId = $this->makeSite($userId, 'SecureSite');
        $site   = (new SitesRepository())->findById($siteId);
        $this->assertNotNull($site, 'Site should be loadable from DB');

        (new EmailNotifier())->notifySslExpiring($site, '2026-07-01 00:00:00', 14);

        $mailer = tests_retrieve_phpmailer_instance();
        $sent   = $mailer->get_sent(0);
        $this->assertNotFalse($sent, 'Expected at least one email to be captured by MockPHPMailer');

        // Recipient must be the shared alert option.
        $this->assertSame('ssl-alerts@example.com', $sent->to[0][0]);
        $this->assertStringContainsString('SSL', $sent->subject);
        $this->assertStringContainsString('14', $sent->subject);
    }
}
