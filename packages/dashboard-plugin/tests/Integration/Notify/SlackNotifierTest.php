<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Defyn\Dashboard\Notify\SlackNotifier;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/** @group integration */
final class SlackNotifierTest extends AbstractSchemaTestCase
{
    private array $captured = [];

    private function site(int $userId): Site
    {
        return Site::fromRow([
            'id' => 7, 'user_id' => $userId, 'url' => 'https://s.test', 'label' => 'S',
            'status' => 'offline', 'created_at' => '2026-06-14 00:00:00',
        ]);
    }

    private function incident(): Incident
    {
        return new Incident(1, 7, '2026-06-14 01:00:00', null, null, 'boom', null, null, '2026-06-14 01:00:00');
    }

    public function testPostsToOwnerWebhook(): void
    {
        $uid = self::factory()->user->create();
        update_user_meta($uid, 'defyn_slack_webhook_url', 'https://hooks.slack.com/services/T/B/x');

        add_filter('pre_http_request', function ($pre, $args, $url) {
            $this->captured[] = ['url' => $url, 'body' => $args['body'] ?? ''];
            return ['response' => ['code' => 200], 'body' => 'ok'];
        }, 10, 3);

        (new SlackNotifier())->notifyDown($this->site($uid), $this->incident());

        self::assertCount(1, $this->captured);
        self::assertSame('https://hooks.slack.com/services/T/B/x', $this->captured[0]['url']);
        self::assertStringContainsString('down', strtolower($this->captured[0]['body']));
    }

    public function testNoOpWhenWebhookEmpty(): void
    {
        $uid = self::factory()->user->create();
        add_filter('pre_http_request', function ($pre, $args, $url) {
            $this->captured[] = $url;
            return ['response' => ['code' => 200], 'body' => 'ok'];
        }, 10, 3);

        (new SlackNotifier())->notifyDown($this->site($uid), $this->incident());

        self::assertCount(0, $this->captured); // no webhook → no HTTP call
    }

    public function testBestEffortSwallowsFailure(): void
    {
        $uid = self::factory()->user->create();
        update_user_meta($uid, 'defyn_slack_webhook_url', 'https://hooks.slack.com/services/T/B/x');
        add_filter('pre_http_request', fn () => new \WP_Error('http', 'boom'), 10, 3);

        // Must not throw.
        (new SlackNotifier())->notifyRecovered($this->site($uid), $this->incident());
        (new SlackNotifier())->notifySslExpiring($this->site($uid), '2026-07-01 00:00:00', 14);
        self::assertTrue(true);
    }
}
