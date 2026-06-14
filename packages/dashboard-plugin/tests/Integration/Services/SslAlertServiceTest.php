<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Notify\Notifier;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SslAlertService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P3.3 Task 8 — SslAlertService fire-once / reset / mute integration tests.
 *
 * Each test seeds exactly one site via $wpdb->update (no repo setter exists for
 * ssl_expires_at) and reads it back by its own $this->sid, so cross-test row
 * leakage is irrelevant; setUp only needs the defyn_sites table to exist with
 * the current (P3.3) schema, guaranteed via freshlyActivate('defyn_sites').
 *
 * @group integration
 */
final class SslAlertServiceTest extends AbstractSchemaTestCase
{
    private int $sid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
    }

    private function spy(array &$sent): Notifier
    {
        return new class($sent) implements Notifier {
            public function __construct(public array &$sent) {}
            public function notifyDown($s, $i): void {}
            public function notifyRecovered($s, $i): void {}
            public function notifySslExpiring($s, $e, $d): void { $this->sent[] = $d; }
        };
    }

    private function makeSite(int $n, ?string $expiresAt, ?string $stamp = null, bool $muted = false): void
    {
        $repo = new SitesRepository();
        $sid = $repo->insertPending(userId: 1, url: "https://s{$n}.test", label: "S{$n}", ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');
        global $wpdb;
        $wpdb->update(\Defyn\Dashboard\Schema\SitesTable::tableName(), [
            'ssl_expires_at' => $expiresAt, 'ssl_alert_sent_at' => $stamp, 'alerts_muted' => $muted ? 1 : 0,
        ], ['id' => $sid]);
        $this->sid = $sid;
    }

    public function testFiresOnceWithinThresholdThenStamps(): void
    {
        $this->makeSite(1, gmdate('Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS)); // 5 days left
        $sent = [];
        (new SslAlertService($this->spy($sent)))->evaluate($this->sid);

        self::assertCount(1, $sent);                                   // fired
        self::assertNotNull((new SitesRepository())->findById($this->sid)->sslAlertSentAt); // stamped

        $sent2 = [];
        (new SslAlertService($this->spy($sent2)))->evaluate($this->sid);
        self::assertSame([], $sent2);                                  // idempotent — already stamped
    }

    public function testMutedStampsButDoesNotSend(): void
    {
        $this->makeSite(2, gmdate('Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS), null, true);
        $sent = [];
        (new SslAlertService($this->spy($sent)))->evaluate($this->sid);
        self::assertSame([], $sent);                                   // muted → no send
        self::assertNotNull((new SitesRepository())->findById($this->sid)->sslAlertSentAt); // still stamped
    }

    public function testRenewalClearsStamp(): void
    {
        $this->makeSite(3, gmdate('Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS), '2026-01-01 00:00:00'); // renewed, stale stamp
        $sent = [];
        (new SslAlertService($this->spy($sent)))->evaluate($this->sid);
        self::assertSame([], $sent);
        self::assertNull((new SitesRepository())->findById($this->sid)->sslAlertSentAt); // cleared
    }
}
