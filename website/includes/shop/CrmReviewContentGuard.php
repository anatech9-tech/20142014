<?php
/** Site-only keyed fingerprint for the reviewed content; never send it to CRM. */
function shop_review_guard_requested(): bool { return defined('SHOP_REVIEW_CONTENT_GUARD_ENABLED') && SHOP_REVIEW_CONTENT_GUARD_ENABLED===true; }
function shop_review_guard_configured(): bool {
    if (!shop_review_guard_requested() || !defined('SHOP_REVIEW_CONTENT_GUARD_KEY') || strlen((string)SHOP_REVIEW_CONTENT_GUARD_KEY)<32) return false;
    foreach (['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY','SHOP_PRODUCT_COMMAND_KEY','SHOP_REVIEW_META_CRM_KEY','SHOP_REVIEW_COMMAND_KEY'] as $other)
        if (defined($other) && hash_equals((string)constant($other),(string)SHOP_REVIEW_CONTENT_GUARD_KEY)) return false;
    return true;
}
/** HMAC byte-for-byte review content and identity, using length prefixes and a per-source id.
 * Raw review values and the fingerprint must not be logged or included in bridge messages. */
function shop_review_guard_hash(string $source,array $row): string {
    if (!shop_review_guard_configured() || !preg_match('/^[a-f0-9]{32}$/D',$source)) throw new RuntimeException('Review guard unavailable');
    foreach (['customer_id','customer_name','body','created_at'] as $field) if (!array_key_exists($field,$row)) throw new RuntimeException('Review guard field missing');
    if (!is_string($row['customer_name']) || strlen($row['customer_name'])>400 || !is_string($row['body']) || strlen($row['body'])>65535
        || !is_string($row['created_at']) || strlen($row['created_at'])>32) throw new RuntimeException('Review guard field invalid');
    $customer=$row['customer_id']===null ? null : (string)shop_crm_uint($row['customer_id'],false);
    $fields=[$source,(string)shop_crm_uint($row['id'],false),(string)shop_crm_uint($row['product_id'],false),
        (string)shop_crm_uint($row['rating']),(string)shop_crm_uint($row['is_active']),$customer,$row['created_at'],$row['customer_name'],$row['body']];
    $context=hash_init('sha256',HASH_HMAC,(string)SHOP_REVIEW_CONTENT_GUARD_KEY); hash_update($context,'shop-review-content-guard-v1.');
    foreach ($fields as $value) {
        if ($value===null) hash_update($context,"N");
        else { hash_update($context,"S".pack('N',strlen($value))); hash_update($context,$value); }
    }
    return hash_final($context);
}
