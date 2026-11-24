<?php
/**
 * Shop\Products — کوئری‌های محصولات
 */

namespace Shop;

class Products
{
    private const SELECT_BASE = "
        p.*, c.name_fa AS category_name, c.slug AS category_slug
        FROM products p
        LEFT JOIN product_categories c ON c.id = p.category_id";

    /**
     * لیست محصولات با فیلتر و صفحه‌بندی
     * $filters: category(slug), q, sort, price_min, price_max, stock
     */
    public static function list(array $filters = [], int $page = 1, int $perPage = 12): array
    {
        $where = ['p.is_active = 1'];
        $params = [];

        if (!empty($filters['category'])) {
            $where[] = 'c.slug = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(p.name_fa LIKE ? OR p.name_en LIKE ? OR p.short_desc_fa LIKE ? OR p.brand LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($filters['stock'])) {
            $where[] = 'p.stock > 0';
        }

        // فیلتر قیمت روی قیمت نمایشی (تومان)
        if (!empty($filters['price_min'])) {
            $where[] = self::priceExpr() . ' >= ?';
            $params[] = (int)$filters['price_min'];
        }
        if (!empty($filters['price_max'])) {
            $where[] = self::priceExpr() . ' <= ?';
            $params[] = (int)$filters['price_max'];
        }

        $order = match ($filters['sort'] ?? '') {
            'price_asc'  => 'display_price ASC',
            'price_desc' => 'display_price DESC',
            'popular'    => 'p.sold_count DESC, p.views DESC',
            'oldest'     => 'p.created_at ASC',
            default      => 'p.created_at DESC, p.id DESC',
        };

        $whereSQL = implode(' AND ', $where);
        $total = (int)\DB::fetchColumn(
            "SELECT COUNT(*) FROM products p LEFT JOIN product_categories c ON c.id = p.category_id WHERE $whereSQL",
            $params
        );

        $offset = max(0, ($page - 1) * $perPage);
        $rows = \DB::fetchAll(
            "SELECT p.*, c.name_fa AS category_name, c.slug AS category_slug, " . self::priceExpr() . " AS display_price
             FROM products p
             LEFT JOIN product_categories c ON c.id = p.category_id
             WHERE $whereSQL
             ORDER BY $order
             LIMIT $perPage OFFSET $offset",
            $params
        );

        return [
            'items'   => array_map([self::class, 'decode'], $rows),
            'total'   => $total,
            'page'    => $page,
            'pages'   => max(1, (int)ceil($total / $perPage)),
        ];
    }

    /**
     * عبارت SQL قیمت نمایشی به تومان (محصولات دلاری با نرخ لحظه‌ای)
     */
    private static function priceExpr(): string
    {
        $rate = Rates::getUsd();
        return "(CASE WHEN p.currency = 'usd' THEN ROUND(p.price_usd * $rate / 10) * 10 ELSE p.price_toman END)";
    }

    /**
     * یک محصول بر اساس slug
     */
    public static function bySlug(string $slug): ?array
    {
        $row = \DB::fetch(
            "SELECT p.*, c.name_fa AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN product_categories c ON c.id = p.category_id
             WHERE p.slug = ? LIMIT 1",
            [$slug]
        );
        return $row ? self::decode($row) : null;
    }

    public static function byId(int $id): ?array
    {
        $row = \DB::fetch(
            "SELECT p.*, c.name_fa AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN product_categories c ON c.id = p.category_id
             WHERE p.id = ? LIMIT 1",
            [$id]
        );
        return $row ? self::decode($row) : null;
    }

    /**
     * آخرین محصولات (صفحه اصلی)
     */
    public static function latest(int $limit = 8): array
    {
        $rows = \DB::fetchAll(
            "SELECT p.*, c.name_fa AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN product_categories c ON c.id = p.category_id
             WHERE p.is_active = 1
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT " . max(1, min(24, $limit))
        );
        return array_map([self::class, 'decode'], $rows);
    }

    /**
     * محبوب‌ترین محصولات (بر اساس فروش + بازدید)
     */
    public static function popular(int $limit = 8): array
    {
        $rows = \DB::fetchAll(
            "SELECT p.*, c.name_fa AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN product_categories c ON c.id = p.category_id
             WHERE p.is_active = 1
             ORDER BY (p.sold_count * 10 + p.views) DESC, p.sold_count DESC
             LIMIT " . max(1, min(24, $limit))
        );
        return array_map([self::class, 'decode'], $rows);
    }

    /**
     * محصولات هم‌دسته (مرتبط)
     */
    public static function related(array $product, int $limit = 4): array
    {
        if (empty($product['category_id'])) return [];
        $rows = \DB::fetchAll(
            "SELECT p.*, c.name_fa AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN product_categories c ON c.id = p.category_id
             WHERE p.is_active = 1 AND p.category_id = ? AND p.id != ?
             ORDER BY p.created_at DESC
             LIMIT " . max(1, min(12, $limit)),
            [$product['category_id'], $product['id']]
        );
        return array_map([self::class, 'decode'], $rows);
    }

    /**
     * دسته‌بندی‌های فعال با تعداد محصول
     */
    public static function categories(): array
    {
        return \DB::fetchAll(
            "SELECT cat.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = cat.id AND p.is_active = 1) AS product_count
             FROM product_categories cat
             WHERE cat.is_active = 1
             ORDER BY cat.display_order, cat.id"
        );
    }

    public static function countAll(): int
    {
        return (int)\DB::fetchColumn('SELECT COUNT(*) FROM products WHERE is_active = 1');
    }

    public static function incViews(int $id): void
    {
        \DB::execute('UPDATE products SET views = views + 1 WHERE id = ?', [$id]);
    }

    public static function incSold(int $id, int $qty): void
    {
        \DB::execute('UPDATE products SET sold_count = sold_count + ? WHERE id = ?', [$qty, $id]);
    }

    /**
     * بازگردانی images (JSON) به آرایه
     */
    public static function decode(array $row): array
    {
        if (isset($row['images'])) {
            $imgs = json_decode((string)$row['images'], true);
            $row['images'] = is_array($imgs) ? array_values($imgs) : [];
        }
        $row['first_image'] = $row['images'] ? $row['images'][0] : '/assets/shop/product-placeholder.svg';
        return $row;
    }

    /**
     * مقدار قیمت نمایشی از یک row decoded
     */
    public static function displayPrice(array $row): int
    {
        if (isset($row['display_price'])) return (int)$row['display_price'];
        return self::productToman($row);
    }
}
