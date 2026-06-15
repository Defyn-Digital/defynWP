<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\SecurityService;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SecurityServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_vulnerabilities', 'defyn_sites'] as $t) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}{$t}");
        }
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    public function testComposeSummaryAndSortOrder(): void
    {
        $repo = new SiteVulnerabilitiesRepository();
        $crit  = $this->seedSite(1, 'https://c.test', 'CritSite', '2026-06-15 02:00:00');
        $high  = $this->seedSite(1, 'https://h.test', 'HighSite', '2026-06-15 02:00:00');
        $clean = $this->seedSite(1, 'https://b.test', 'CleanSite', '2026-06-15 02:00:00');
        $never = $this->seedSite(1, 'https://n.test', 'NeverSite', null);

        $repo->replaceForSite($crit, [$this->finding('critical')], '2026-06-15 02:00:00');
        $repo->replaceForSite($high, [$this->finding('high')], '2026-06-15 02:00:00');

        $payload = (new SecurityService())->compose(1);

        self::assertSame(4, $payload['summary']['total_sites']);
        self::assertSame(3, $payload['summary']['scanned_sites']); // crit, high, clean scanned; never not
        self::assertSame(2, $payload['summary']['sites_at_risk']);
        self::assertSame(1, $payload['summary']['critical']);
        self::assertSame(1, $payload['summary']['high']);

        // sort order: at-risk (worst first: crit then high) → clean → never-scanned
        $ids = array_map(static fn ($s) => $s['site_id'], $payload['sites']);
        self::assertSame([$crit, $high, $clean, $never], $ids);
        self::assertArrayHasKey('counts', $payload['sites'][0]);
        self::assertSame(1, $payload['sites'][0]['counts']['critical']);
        self::assertArrayHasKey('generated_at', $payload);
    }

    /** @return array<string,mixed> */
    private function finding(string $severity): array
    {
        return ['type'=>'plugin','slug'=>'x','component_name'=>'X','installed_version'=>'1.0',
                'severity'=>$severity,'cvss_score'=>null,'cve'=>null,'fixed_in'=>'2.0','title'=>'x','source_id'=>'s'];
    }

    private function seedSite(int $userId, string $url, string $label, ?string $lastScan): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'=>$userId,'url'=>$url,'label'=>$label,'status'=>'active',
            'last_security_scan_at'=>$lastScan,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
