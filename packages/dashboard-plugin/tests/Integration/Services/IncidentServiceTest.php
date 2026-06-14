<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Defyn\Dashboard\Notify\Notifier;
use Defyn\Dashboard\Services\IncidentService;
use Defyn\Dashboard\Services\IncidentsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * Spy notifier that counts down/up calls without side-effects.
 */
final class SpyNotifier implements Notifier
{
    public int $downCount = 0;
    public int $upCount   = 0;

    public function notifyDown(Site $site, Incident $incident): void
    {
        $this->downCount++;
    }

    public function notifyRecovered(Site $site, Incident $incident): void
    {
        $this->upCount++;
    }

    public function notifySslExpiring(Site $site, string $expiresAtUtc, int $daysLeft): void
    {
        // No-op spy — SSL alerting is not exercised by IncidentService tests.
    }
}

/**
 * P3.1 Task 6 — IncidentService confirm-down state machine integration tests.
 *
 * Guardrail notes exercised:
 *   1  — first failure does NOT open incident (threshold = 2)
 *   2  — exactly one down-email on open; 3rd consecutive failure must NOT re-alert
 *   4  — recordSuccess always resets counter even without an open incident
 *   5  — single open incident per site (guardrail enforced inside service)
 *   6  — notifier throws must not propagate (not directly tested here, covered
 *          by unit-level safeNotify; integration path uses SpyNotifier)
 *
 * @group integration
 */
final class IncidentServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
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
     * Insert a site row via direct wpdb and return the hydrated Site model via
     * SitesRepository::findById so $site->consecutiveFailures is accurate.
     */
    private function seedSite(int $userId): Site
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://example-' . microtime(true) . '.test',
            'label'      => 'TestSite-' . $userId,
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $id   = (int) $wpdb->insert_id;
        $site = (new SitesRepository())->findById($id);
        $this->assertNotNull($site, "seedSite: SitesRepository::findById({$id}) returned null");
        return $site;
    }

    /**
     * Re-read the site row from the DB so consecutiveFailures reflects the
     * latest persisted value after each state-machine call.
     */
    private function reload(Site $site): Site
    {
        $fresh = (new SitesRepository())->findById($site->id);
        $this->assertNotNull($fresh, "reload: SitesRepository::findById({$site->id}) returned null");
        return $fresh;
    }

    private function service(SpyNotifier $notifier): IncidentService
    {
        // Inject the spy as the 3rd constructor arg; real repos + logger via defaults.
        return new IncidentService(null, null, $notifier, null);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Guardrail 1: first failure increments counter to 1 (< threshold 2), so
     * no incident must be opened and no down-email sent.
     */
    public function test_first_failure_does_not_open_incident(): void
    {
        $site     = $this->seedSite(1);
        $notifier = new SpyNotifier();
        $svc      = $this->service($notifier);

        $svc->recordFailure($site, 'boom');

        $this->assertNull((new IncidentsRepository())->findOpenForSite($site->id));
        $this->assertSame(0, $notifier->downCount);
    }

    /**
     * Guardrails 1, 2, 5:
     *   - 2nd consecutive failure opens exactly ONE incident
     *   - Exactly ONE down-email sent (down_alert_sent_at stamped)
     *   - 3rd consecutive failure (incident already open) does NOT re-alert
     */
    public function test_second_consecutive_failure_opens_incident_and_alerts_once(): void
    {
        $site     = $this->seedSite(1);
        $notifier = new SpyNotifier();
        $svc      = $this->service($notifier);

        $svc->recordFailure($site, 'boom');
        $svc->recordFailure($this->reload($site), 'boom');

        $open = (new IncidentsRepository())->findOpenForSite($site->id);
        $this->assertNotNull($open);
        $this->assertSame(1, $notifier->downCount);
        $this->assertNotNull($open->downAlertSentAt);   // Fix 2: stamp persisted

        // 3rd failure — incident already open, must NOT re-alert (guardrails 2 + 5)
        $svc->recordFailure($this->reload($site), 'boom');
        $this->assertSame(1, $notifier->downCount);  // still ONE
    }

    /**
     * Guardrails 2, 4: after recovery
     *   - Open incident is closed
     *   - Exactly ONE up-email sent
     *   - consecutiveFailures is reset to 0
     */
    public function test_success_closes_incident_resets_counter_and_alerts(): void
    {
        $site     = $this->seedSite(1);
        $notifier = new SpyNotifier();
        $svc      = $this->service($notifier);

        $svc->recordFailure($site, 'boom');
        $svc->recordFailure($this->reload($site), 'boom');
        $svc->recordSuccess($this->reload($site));

        $this->assertNull((new IncidentsRepository())->findOpenForSite($site->id));
        $this->assertSame(1, $notifier->upCount);
        $this->assertSame(0, $this->reload($site)->consecutiveFailures);

        // Fix 2: up-alert stamp persisted
        $closed = (new IncidentsRepository())->findForSite($site->id, 10, 0)[0];
        $this->assertNotNull($closed->upAlertSentAt);

        // Fix 3: duration + ended_at populated; no spurious second down-email
        $this->assertNotNull($closed->endedAt);
        $this->assertNotNull($closed->durationSeconds);
        $this->assertGreaterThanOrEqual(0, $closed->durationSeconds);
        $this->assertSame(1, $notifier->downCount);   // recovery must not fire a 2nd down-email
    }

    /**
     * Guardrail 4: a single failure followed immediately by success should
     * produce no incident and no emails, but the counter MUST still be reset.
     */
    public function test_single_failure_then_success_no_incident_no_email(): void
    {
        $site     = $this->seedSite(1);
        $notifier = new SpyNotifier();
        $svc      = $this->service($notifier);

        $svc->recordFailure($site, 'blip');
        $svc->recordSuccess($this->reload($site));

        $this->assertSame(0, $notifier->downCount);
        $this->assertSame(0, $notifier->upCount);
        $this->assertNull((new IncidentsRepository())->findOpenForSite($site->id));
        // Guardrail 4 — counter reset even though no incident existed
        $this->assertSame(0, $this->reload($site)->consecutiveFailures);
    }

    /**
     * P3.3 Task 7 — mute gate: a MUTED site still records the incident and its
     * audit events, but the down notification is suppressed (downCount stays 0).
     */
    public function test_muted_site_records_incident_but_does_not_notify(): void
    {
        $site = $this->seedSite(1);
        (new SitesRepository())->setAlertsMuted($site->id, true);
        $muted = $this->reload($site);   // re-hydrate so $muted->alertsMuted === true

        $notifier = new SpyNotifier();
        $svc      = $this->service($notifier);

        $svc->recordFailure($muted, 'boom');                 // 1st — below threshold
        $svc->recordFailure($this->reload($muted), 'boom');  // 2nd — opens incident

        // Incident WAS opened (history kept) but NO down-email fired (muted).
        $this->assertNotNull((new IncidentsRepository())->findOpenForSite($site->id));
        $this->assertSame(0, $notifier->downCount);
    }
}
