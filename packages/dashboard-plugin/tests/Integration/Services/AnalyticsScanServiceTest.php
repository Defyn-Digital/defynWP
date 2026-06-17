<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\AnalyticsScanService;
use Defyn\Dashboard\Services\Ga4Client;
use Defyn\Dashboard\Services\SiteAnalyticsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class AnalyticsScanServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_analytics','defyn_sites','defyn_activity_log'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    private function seedSite(?string $propertyId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://acme.test','label'=>'Acme','status'=>'active','wp_version'=>'6.9.4',
            'ga4_property_id'=>$propertyId,
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    public function testSkipsWhenNoProperty(): void
    {
        $siteId = $this->seedSite(null);
        (new AnalyticsScanService())->scan($siteId, $this->client(['sessions'=>1,'users'=>1,'pageviews'=>1,'avg_engagement'=>1.0,'top_pages'=>[],'channels'=>[]]));
        self::assertNull((new SiteAnalyticsRepository())->latestForSite($siteId));
    }

    public function testStoresCurrentAndPreviousMonth(): void
    {
        $siteId = $this->seedSite('123456789');
        (new AnalyticsScanService())->scan($siteId, $this->client(['sessions'=>500,'users'=>400,'pageviews'=>1200,'avg_engagement'=>90.0,'top_pages'=>[],'channels'=>[]]));
        global $wpdb;
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'defyn_site_analytics WHERE site_id = ' . $siteId);
        self::assertSame(2, $count); // current + previous month
    }

    public function testNeverThrowsWhenClientReturnsNull(): void
    {
        $siteId = $this->seedSite('123456789');
        (new AnalyticsScanService())->scan($siteId, $this->client(null));
        self::assertNull((new SiteAnalyticsRepository())->latestForSite($siteId)); // no rows, no exception
    }

    private function client(?array $ret): Ga4Client
    {
        return new class($ret) extends Ga4Client {
            public function __construct(private readonly ?array $ret) { parent::__construct(fn () => null, 'x'); }
            public function fetchReport(string $p, string $f, string $t): ?array { return $this->ret; }
        };
    }
}
