<?php
declare(strict_types=1);
namespace Defyn\Connector\Links;

/** P7.1 — enumerate published posts/pages, extract + check their links, report the bad ones. Bounded. */
final class BrokenLinkScanner
{
    public const MAX_POSTS    = 500;
    public const MAX_LINKS    = 3000;
    public const MAX_FINDINGS = 2000;
    public const MAX_SECONDS  = 20;

    public function __construct(
        private readonly LinkExtractor $extractor = new LinkExtractor(),
        private readonly LinkChecker $checker = new LinkChecker(),
    ) {}

    /**
     * @return array{links:list<array<string,mixed>>, scanned_posts:int, total_posts:int, checked_links:int, truncated:bool}
     */
    public function scan(?int $now = null): array
    {
        $start    = $now ?? time();
        $homeUrl  = (string) get_home_url();
        $homeHost = strtolower((string) (wp_parse_url($homeUrl, PHP_URL_HOST) ?: ''));

        $query = new \WP_Query([
            'post_type'      => ['post', 'page'],
            'post_status'    => 'publish',
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'posts_per_page' => self::MAX_POSTS,
            'fields'         => 'ids',
        ]);
        $totalPosts = (int) $query->found_posts;

        $cache     = [];
        $findings  = [];
        $scanned   = 0;
        $truncated = $totalPosts > self::MAX_POSTS;

        foreach (array_map('intval', $query->posts) as $postId) {
            if ((time() - $start) >= self::MAX_SECONDS) { $truncated = true; break; }
            $scanned++;
            $content   = (string) get_post_field('post_content', $postId);
            $permalink = (string) get_permalink($postId);
            $title     = (string) get_the_title($postId);

            foreach ($this->extractor->extract($content, $homeUrl) as $occ) {
                $url = $occ['url'];
                if (!isset($cache[$url])) {
                    if (count($cache) >= self::MAX_LINKS) { $truncated = true; continue; }
                    if ((time() - $start) >= self::MAX_SECONDS) { $truncated = true; break 2; }
                    $cache[$url] = $this->checker->check($url);
                }
                $r = $cache[$url];
                if (!$r['transport_error'] && $r['status'] !== null && $r['status'] >= 200 && $r['status'] < 400) {
                    continue;
                }
                if (count($findings) >= self::MAX_FINDINGS) { $truncated = true; break 2; }
                $host = strtolower((string) (wp_parse_url($url, PHP_URL_HOST) ?: ''));
                $findings[] = [
                    'url'             => $url,
                    'status'          => $r['status'],
                    'transport_error' => $r['transport_error'],
                    'link_type'       => ($host !== '' && $host === $homeHost) ? 'internal' : 'external',
                    'source_url'      => $permalink,
                    'source_title'    => $title !== '' ? $title : null,
                    'anchor_text'     => $occ['anchor_text'] !== '' ? $occ['anchor_text'] : null,
                ];
            }
        }

        return [
            'links'         => $findings,
            'scanned_posts' => $scanned,
            'total_posts'   => $totalPosts,
            'checked_links' => count($cache),
            'truncated'     => $truncated,
        ];
    }
}
