<?php
/** Optional site-only keyed observation of blog row fields. Never send the digest or content. */
require_once __DIR__.'/CrmBlogMetadataContract.php';
function site_blog_guard_requested(): bool { return defined('SITE_BLOG_CONTENT_GUARD_ENABLED') && SITE_BLOG_CONTENT_GUARD_ENABLED===true; }
function site_blog_guard_configured(): bool {
    if (!site_blog_guard_requested() || !defined('SITE_BLOG_CONTENT_GUARD_KEY') || strlen((string)SITE_BLOG_CONTENT_GUARD_KEY)<32) return false;
    foreach (['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY','SHOP_PRODUCT_COMMAND_KEY','SHOP_REVIEW_META_CRM_KEY','SHOP_REVIEW_COMMAND_KEY','SHOP_REVIEW_CONTENT_GUARD_KEY','SITE_SHIPMENT_META_KEY','SHOP_COUPON_META_KEY','SITE_BLOG_META_KEY'] as $other)
        if (defined($other) && hash_equals((string)constant($other),(string)SITE_BLOG_CONTENT_GUARD_KEY)) return false;
    return true;
}
/** Length-framed HMAC of listed row values. Related categories, image file contents and external assets are not read. */
function site_blog_guard_hash(string $source,array $row): string {
    if (!site_blog_guard_configured() || !preg_match('/^[a-f0-9]{32}$/D',$source)) throw new RuntimeException('Blog content guard unavailable');
    $limits=['slug'=>800,'title_fa'=>1200,'title_en'=>1200,'excerpt_fa'=>65535,'excerpt_en'=>65535,'content_fa'=>1048576,'content_en'=>1048576,'cover_image'=>2000,'published_at'=>32,'meta_title'=>1200,'meta_description'=>65535,'meta_keywords'=>2000,'created_at'=>32];
    $required=['id','is_published','category_id','author_id','is_featured']; foreach (array_merge($required,array_keys($limits)) as $field) if (!array_key_exists($field,$row)) throw new RuntimeException('Blog guard field missing');
    foreach ($limits as $field=>$max) if ($row[$field]!==null && (!is_string($row[$field]) || strlen($row[$field])>$max)) throw new RuntimeException('Blog guard field invalid');
    foreach (['slug','title_fa','content_fa','created_at'] as $field) if ($row[$field]===null) throw new RuntimeException('Blog guard field invalid');
    $published=site_blog_metadata_uint($row['is_published'],true); $featured=site_blog_metadata_uint($row['is_featured'],true);
    if (!in_array($published,[0,1],true) || !in_array($featured,[0,1],true)) throw new RuntimeException('Blog guard field invalid');
    $values=[$source,(string)site_blog_metadata_uint($row['id']),(string)$published,
        $row['category_id']===null?null:(string)site_blog_metadata_uint($row['category_id']),
        $row['author_id']===null?null:(string)site_blog_metadata_uint($row['author_id']),(string)$featured];
    foreach ($limits as $field=>$max) $values[]=$row[$field];
    $ctx=hash_init('sha256',HASH_HMAC,(string)SITE_BLOG_CONTENT_GUARD_KEY); hash_update($ctx,'site-blog-content-guard-v1.');
    foreach ($values as $value) { if ($value===null) hash_update($ctx,'N'); else { hash_update($ctx,'S'.pack('N',strlen($value))); hash_update($ctx,$value); } }
    return hash_final($ctx);
}
