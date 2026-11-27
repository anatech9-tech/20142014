<?php
/** Shared keys across security domains must fail closed in both components. */
define('SITE_SHIPMENT_META_ENABLED',true); define('SITE_SHIPMENT_META_KEY',str_repeat('s',32)); define('SITE_SHIPMENT_SOURCE_ID',str_repeat('a',32)); define('SITE_SHIPMENT_META_URL','https://example.test/api'); define('SHOP_REVIEW_COMMAND_KEY',str_repeat('s',32));
require_once __DIR__.'/../../website/includes/shipments/ShipmentMetadataSync.php'; require_once __DIR__.'/../includes/site_shipment_metadata_sync.php';
if (site_shipment_sync_configured() || crm_site_shipment_sync_configured()) { fwrite(STDERR,"FAIL reused key accepted\n"); exit(1); } echo "PASS reused command key disables both shipment bridge sides\n";
