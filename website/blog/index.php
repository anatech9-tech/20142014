<?php
/**
 * Blog Listing Page
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/SiteContent.php';
require_once BASE_PATH . '/includes/Analytics.php';

Analytics::trackPageView('بلاگ - لیست مقالات');

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 9;
$offset = ($page - 1) * $perPage;
$categorySlug = $_GET['cat'] ?? '';

$category = null;
if ($categorySlug) {
    $category = DB::fetch('SELECT * FROM blog_categories WHERE slug = ?', [$categorySlug]);
}

$where = "WHERE p.is_published = 1";
$params = [];
if ($category) {
    $where .= ' AND p.category_id = ?';
    $params[] = $category['id'];
}

$totalPosts = (int) DB::fetchColumn("SELECT COUNT(*) FROM blog_posts p $where", $params);
$totalPages = max(1, (int) ceil($totalPosts / $perPage));

$posts = DB::fetchAll(
    "SELECT p.*, c.name_fa AS category_name, c.slug AS category_slug
     FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id
     $where ORDER BY p.is_featured DESC, p.published_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

$categories = SiteContent::blogCategories();
$featured = DB::fetchAll("SELECT * FROM blog_posts WHERE is_published = 1 AND is_featured = 1 ORDER BY published_at DESC LIMIT 3");

function e3(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="مقالات و اخبار درخت سپید تجارت پارس - حمل‌ونقل بین‌المللی و ترخیص کالا">
    <title>بلاگ — درخت سپید تجارت پارس</title>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="icon" href="/logo.webp" type="image/webp">
    <style>
        .blog-container { max-width: 1200px; margin: 0 auto; padding: 120px 20px 60px; }
        .blog-hero { text-align: center; margin-bottom: 50px; }
        .blog-hero h1 { color: #fff; font-size: 2.5rem; margin-bottom: 12px; }
        .blog-hero p { color: #8892b0; font-size: 1.1rem; }
        .blog-filters {
            display: flex; gap: 10px; flex-wrap: wrap;
            justify-content: center; margin-bottom: 40px;
        }
        .blog-filters a {
            padding: 8px 20px; border-radius: 30px;
            background: rgba(255,255,255,0.05);
            color: #ccd6f6; text-decoration: none;
            border: 1px solid rgba(255,255,255,0.08);
            transition: all 0.2s;
        }
        .blog-filters a:hover, .blog-filters a.active {
            background: rgba(212,175,55,0.2);
            border-color: rgba(212,175,55,0.5);
            color: #d4af37;
        }
        .blog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
        }
        .blog-card {
            background: rgba(20, 27, 45, 0.6);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            overflow: hidden;
            transition: transform 0.3s, border-color 0.3s;
            backdrop-filter: blur(10px);
        }
        .blog-card:hover {
            transform: translateY(-6px);
            border-color: rgba(212, 175, 55, 0.4);
        }
        .blog-card img { width: 100%; height: 200px; object-fit: cover; }
        .blog-card-body { padding: 22px; }
        .blog-card-cat {
            display: inline-block;
            background: rgba(212, 175, 55, 0.15);
            color: #d4af37;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 0.78rem;
            margin-bottom: 10px;
        }
        .blog-card h3 { color: #fff; font-size: 1.15rem; margin-bottom: 8px; line-height: 1.5; }
        .blog-card p { color: #8892b0; font-size: 0.9rem; line-height: 1.7; margin-bottom: 12px; }
        .blog-card a { color: #d4af37; text-decoration: none; font-size: 0.88rem; }
        .blog-card-meta { color: #8892b0; font-size: 0.78rem; margin-top: 8px; }
        .blog-empty { text-align: center; padding: 80px 20px; color: #8892b0; }
        .pagination {
            display: flex; justify-content: center; gap: 8px;
            margin-top: 50px; flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 8px 16px;
            border-radius: 8px;
            background: rgba(255,255,255,0.05);
            color: #ccd6f6;
            text-decoration: none;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .pagination a:hover { background: rgba(212,175,55,0.2); color: #d4af37; }
        .pagination .current { background: #d4af37; color: #0a0f1c; border-color: #d4af37; }
    </style>
</head>
<body>
    <nav class="navbar" id="navbar" style="position: fixed; background: rgba(10,15,28,0.85); backdrop-filter: blur(20px);">
        <div class="nav-brand">
            <img src="/logo.webp" alt="لوگو" class="nav-logo">
            <span class="brand-text">درخت سپید تجارت پارس</span>
        </div>
        <div class="nav-links">
            <a href="/#hero" class="nav-link">خانه</a>
            <a href="/#about" class="nav-link">درباره ما</a>
            <a href="/#services" class="nav-link">خدمات</a>
            <a href="/blog/" class="nav-link active">بلاگ</a>
            <a href="/track/" class="nav-link">ردیابی</a>
            <a href="/#contact" class="nav-link">تماس</a>
        </div>
    </nav>

    <div class="blog-container">
        <div class="blog-hero">
            <h1>📰 بلاگ و مقالات</h1>
            <p>آخرین مقالات درباره حمل‌ونقل بین‌المللی، گمرک و بازرگانی</p>
        </div>

        <?php if (!empty($categories)): ?>
        <div class="blog-filters">
            <a href="/blog/" class="<?= !$categorySlug ? 'active' : '' ?>">همه</a>
            <?php foreach ($categories as $c): ?>
                <a href="/blog/?cat=<?= e3($c['slug']) ?>" class="<?= $categorySlug === $c['slug'] ? 'active' : '' ?>">
                    <?= e3($c['name_fa']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($posts)): ?>
            <div class="blog-empty">
                <h3>هنوز مقاله‌ای منتشر نشده</h3>
                <p>به‌زودی مقالات جدید اضافه می‌شود.</p>
            </div>
        <?php else: ?>
            <div class="blog-grid">
                <?php foreach ($posts as $p): ?>
                    <article class="blog-card">
                        <?php if ($p['cover_image']): ?>
                            <a href="/blog/<?= e3($p['slug']) ?>">
                                <img src="<?= e3($p['cover_image']) ?>" alt="<?= e3($p['title_fa']) ?>" loading="lazy">
                            </a>
                        <?php endif; ?>
                        <div class="blog-card-body">
                            <?php if ($p['category_name']): ?>
                                <span class="blog-card-cat"><?= e3($p['category_name']) ?></span>
                            <?php endif; ?>
                            <h3>
                                <a href="/blog/<?= e3($p['slug']) ?>" style="color: #fff; text-decoration: none;">
                                    <?= e3($p['title_fa']) ?>
                                </a>
                            </h3>
                            <p><?= e3(mb_substr(strip_tags($p['excerpt_fa'] ?: $p['content_fa']), 0, 120)) ?>…</p>
                            <div class="blog-card-meta">
                                📅 <?= date('Y/m/d', strtotime($p['published_at'] ?: $p['created_at'])) ?>
                                · 👁️ <?= number_format($p['views']) ?> بازدید
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">قبلی</a>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">بعدی</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
