<?php
/**
 * Site Content Loader
 * Loads content from DB with file-based fallback
 */

declare(strict_types=1);

class SiteContent
{
    /**
     * Get content for a section, with cache
     */
    public static function section(string $key, ?string $lang = null): array
    {
        $lang = $lang ?? I18n::getLang();
        static $cache = [];
        $ck = $key . ':' . $lang;
        if (isset($cache[$ck])) return $cache[$ck];

        try {
            $row = DB::fetch('SELECT * FROM content_sections WHERE section_key = ?', [$key]);
            if ($row) {
                $cache[$ck] = [
                    'title'    => $lang === 'en' ? ($row['title_en'] ?: $row['title_fa']) : $row['title_fa'],
                    'subtitle' => $lang === 'en' ? ($row['subtitle_en'] ?: $row['subtitle_fa']) : $row['subtitle_fa'],
                    'body'     => $lang === 'en' ? ($row['body_en'] ?: $row['body_fa']) : $row['body_fa'],
                ];
                return $cache[$ck];
            }
        } catch (Throwable $e) {}

        $cache[$ck] = ['title' => '', 'subtitle' => '', 'body' => ''];
        return $cache[$ck];
    }

    public static function services(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $cache = DB::fetchAll('SELECT * FROM services WHERE is_active = 1 ORDER BY display_order, id');
        } catch (Throwable $e) {
            $cache = [];
        }
        return $cache;
    }

    public static function team(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $cache = DB::fetchAll('SELECT * FROM team_members WHERE is_active = 1 ORDER BY display_order, id');
        } catch (Throwable $e) {
            $cache = [];
        }
        return $cache;
    }

    public static function network(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $cache = DB::fetchAll('SELECT * FROM network_locations WHERE is_active = 1 ORDER BY display_order, id');
        } catch (Throwable $e) {
            $cache = [];
        }
        return $cache;
    }

    public static function whyUs(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $cache = DB::fetchAll('SELECT * FROM why_us_items WHERE is_active = 1 ORDER BY display_order, id');
        } catch (Throwable $e) {
            $cache = [];
        }
        return $cache;
    }

    public static function processSteps(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $cache = DB::fetchAll('SELECT * FROM process_steps WHERE is_active = 1 ORDER BY display_order, id');
        } catch (Throwable $e) {
            $cache = [];
        }
        return $cache;
    }

    public static function publishedPosts(int $limit = 10, ?int $categoryId = null): array
    {
        try {
            $sql = 'SELECT p.*, c.name_fa AS category_name FROM blog_posts p
                    LEFT JOIN blog_categories c ON c.id = p.category_id
                    WHERE p.is_published = 1';
            $params = [];
            if ($categoryId) {
                $sql .= ' AND p.category_id = ?';
                $params[] = $categoryId;
            }
            $sql .= ' ORDER BY p.is_featured DESC, p.published_at DESC LIMIT ' . (int) $limit;
            return DB::fetchAll($sql, $params);
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function postBySlug(string $slug): ?array
    {
        try {
            $post = DB::fetch('SELECT * FROM blog_posts WHERE slug = ? AND is_published = 1', [$slug]);
            if ($post) {
                // Increment view
                DB::execute('UPDATE blog_posts SET views = views + 1 WHERE id = ?', [$post['id']]);
            }
            return $post;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function shipmentByCode(string $code): ?array
    {
        try {
            return DB::fetch('SELECT * FROM shipments WHERE tracking_code = ? AND is_public = 1', [$code]);
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function shipmentEvents(int $shipmentId): array
    {
        try {
            return DB::fetchAll(
                'SELECT * FROM shipment_events WHERE shipment_id = ? ORDER BY event_time DESC',
                [$shipmentId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function blogCategories(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $cache = DB::fetchAll('SELECT * FROM blog_categories ORDER BY display_order, id');
        } catch (Throwable $e) {
            $cache = [];
        }
        return $cache;
    }
}
