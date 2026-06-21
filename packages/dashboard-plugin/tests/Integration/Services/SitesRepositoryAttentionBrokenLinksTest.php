<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\BrokenLinksRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryAttentionBrokenLinksTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_broken_links', 'defyn_sites'] as $t) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}{$t}");
        }
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    public function testBrokenLinkSiteGetsHasBrokenLinksReason(): void
    {
        $sites = new SitesRepository();
        $id    = $this->seedSite('https://broken-link-attention.test', 'Broken Link Attention');

        (new BrokenLinksRepository())->upsertForSite($id, [
            'url'         => 'https://broken-link-attention.test/dead-page',
            'source_url'  => 'https://broken-link-attention.test/home',
            'severity'    => 'broken',
            'reason'      => 'http_404',
            'link_type'   => 'internal',
            'status_code' => 404,
        ], gmdate('Y-m-d H:i:s'));

        $rows  = $sites->findSitesNeedingAttention(1);
        $match = array_values(array_filter($rows, static fn ($r) => (int) $r['site_id'] === $id));
        self::assertNotEmpty($match, 'site with a broken link must appear in needing-attention');
        self::assertContains('has_broken_links', $match[0]['reasons']);
    }

    public function testWarningOnlySiteDoesNotGetReason(): void
    {
        $sites = new SitesRepository();
        $id    = $this->seedSite('https://warning-only-attention.test', 'Warning Only Attention');

        (new BrokenLinksRepository())->upsertForSite($id, [
            'url'         => 'https://warning-only-attention.test/slow-page',
            'source_url'  => 'https://warning-only-attention.test/home',
            'severity'    => 'warning',
            'reason'      => 'redirect',
            'link_type'   => 'internal',
            'status_code' => 301,
        ], gmdate('Y-m-d H:i:s'));

        $rows  = $sites->findSitesNeedingAttention(1);
        $match = array_values(array_filter($rows, static fn ($r) => (int) $r['site_id'] === $id));

        // Site has NO other attention triggers — it must not appear.
        self::assertEmpty($match, 'site with only warning-severity links must not appear in needing-attention');
    }

    private function seedSite(string $url, string $label): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => 1,
            'url'        => $url,
            'label'      => $label,
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
