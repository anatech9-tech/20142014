<?php
/**
 * Single blog post page
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/SiteContent.php';
require_once BASE_PATH . '/includes/Analytics.php';

$slug = $_GET['slug'] ?? '';
$post = $slug ? SiteContent::postBySlug($slug) : null;

if (!$post) {
    http_response_code(404);
    $pageTitle = 'یافت نشد';
} else {
    Analytics::trackPageView($post['title_fa']);
    $pageTitle = $post['title_fa'];
    $category = $post['category_id'] ? DB::fetch('SELECT * FROM blog_categories WHERE id = ?', [$post['category_id']]) : null;
    $related = DB::fetchAll(
        "SELECT id, slug, title_fa, cover_image FROM blog_posts
         WHERE is_published = 1 AND id != ? AND category_id " . ($category ? "= ?" : "IS NULL") . "
         ORDER BY published_at DESC LIMIT 3",
        $category ? [$post['id'], $category['id']] : [$post['id']]
    );
}

function e4(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Convert Persian/Arabic numbers to English for safe processing in HTML content
function safe_html(string $html): string {
    // Allow basic formatting but remove dangerous tags
    $allowed = '<p><br><strong><b><em><i><u><a><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><img><figure><figcaption><hr><table><thead><tbody><tr><th><td>';
    return strip_tags($html, $allowed);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($post): ?>
        <title><?= e4($post['meta_title'] ?: $post['title_fa']) ?> — درخت سپید تجارت پارس</title>
        <meta name="description" content="<?= e4($post['meta_description'] ?: mb_substr(strip_tags($post['excerpt_fa'] ?? $post['content_fa']), 0, 160)) ?>">
        <?php if ($post['meta_keywords']): ?><meta name="keywords" content="<?= e4($post['meta_keywords']) ?>"><?php endif; ?>
        <meta property="og:title" content="<?= e4($post['title_fa']) ?>">
        <meta property="og:description" content="<?= e4(mb_substr(strip_tags($post['excerpt_fa'] ?? $post['content_fa']), 0, 160)) ?>">
        <meta property="og:type" content="article">
        <?php if ($post['cover_image']): ?><meta property="og:image" content="<?= e4($post['cover_image']) ?>"><?php endif; ?>
    <?php else: ?>
        <title>مقاله یافت نشد</title>
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="icon" href="/logo.webp" type="image/webp">
    <style>
        .post-container { max-width: 800px; margin: 0 auto; padding: 120px 20px 60px; }
        .post-cover { width: 100%; max-height: 420px; object-fit: cover; border-radius: 16px; margin-bottom: 30px; }
        .post-meta { color: #8892b0; margin-bottom: 20px; display: flex; gap: 16px; flex-wrap: wrap; font-size: 0.9rem; }
        .post-meta a { color: #d4af37; text-decoration: none; }
        .post-title { color: #fff; font-size: 2.2rem; line-height: 1.4; margin-bottom: 16px; }
        .post-content {
            color: #ccd6f6;
            line-height: 2;
            font-size: 1.05rem;
        }
        .post-content h1, .post-content h2, .post-content h3 { color: #fff; margin: 30px 0 16px; }
        .post-content p { margin-bottom: 18px; }
        .post-content img { max-width: 100%; border-radius: 12px; margin: 20px 0; }
        .post-content blockquote {
            background: rgba(212, 175, 55, 0.08);
            border-right: 4px solid #d4af37;
            padding: 16px 20px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
        }
        .post-content a { color: #d4af37; }
        .post-content pre, .post-content code {
            background: rgba(10, 15, 28, 0.6);
            border-radius: 6px;
            padding: 2px 8px;
            font-family: monospace;
            direction: ltr;
            display: inline-block;
        }
        .post-content pre { padding: 16px; overflow-x: auto; display: block; }
        .related {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px; margin-top: 50px;
        }
        .related-card {
            background: rgba(20, 27, 45, 0.6);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px; overflow: hidden;
        }
        .related-card img { width: 100%; height: 120px; object-fit: cover; }
        .related-card h4 { color: #fff; padding: 12px; font-size: 0.95rem; }
        .related-card a { color: #d4af37; text-decoration: none; }
        .back-link { text-align: center; margin-top: 40px; }
        .back-link a { color: #d4af37; text-decoration: none; }
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
            <a href="/blog/" class="nav-link">بلاگ</a>
            <a href="/track/" class="nav-link">ردیابی</a>
            <a href="/#contact" class="nav-link">تماس</a>
        </div>
    </nav>

    <div class="post-container">
        <?php if (!$post): ?>
            <h1 class="post-title">❌ مقاله یافت نشد</h1>
            <p style="color: #8892b0;">ممکن است این مقاله حذف شده یا هرگز منتشر نشده باشد.</p>
            <div class="back-link"><a href="/blog/">← بازگشت به بلاگ</a></div>
        <?php else: ?>
            <?php if ($post['cover_image']): ?>
                <img src="<?= e4($post['cover_image']) ?>" alt="<?= e4($post['title_fa']) ?>" class="post-cover">
            <?php endif; ?>

            <div class="post-meta">
                <?php if ($category): ?>
                    <span>📁 <a href="/blog/?cat=<?= e4($category['slug']) ?>"><?= e4($category['name_fa']) ?></a></span>
                <?php endif; ?>
                <span>📅 <?= date('Y/m/d', strtotime($post['published_at'] ?: $post['created_at'])) ?></span>
                <span>👁️ <?= number_format($post['views']) ?> بازدید</span>
                <?php if ($post['is_featured']): ?><span>⭐ ویژه</span><?php endif; ?>
            </div>

            <h1 class="post-title"><?= e4($post['title_fa']) ?></h1>

            <article class="post-content">
                <?= safe_html($post['content_fa']) ?>
            </article>

            <?php if (!empty($related)): ?>
            <h3 style="color: #fff; margin-top: 60px; margin-bottom: 20px;">📖 مقالات مرتبط</h3>
            <div class="related">
                <?php foreach ($related as $r): ?>
                    <div class="related-card">
                        <?php if ($r['cover_image']): ?>
                            <a href="/blog/<?= e4($r['slug']) ?>">
                                <img src="<?= e4($r['cover_image']) ?>" alt="<?= e4($r['title_fa']) ?>">
                            </a>
                        <?php endif; ?>
                        <h4><a href="/blog/<?= e4($r['slug']) ?>"><?= e4($r['title_fa']) ?></a></h4>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="back-link">
                <a href="/blog/">← بازگشت به بلاگ</a>
                <span style="margin: 0 20px; color: #8892b0;">|</span>
                <a href="/#contact">تماس با ما</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
