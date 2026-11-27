<?php
/** No settings: neither side runs or writes. */
require_once __DIR__.'/../../website/includes/shop/CrmCategoryMetadataSync.php'; require_once __DIR__.'/../includes/shop_category_metadata_sync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
check(!shop_category_sync_configured() && !crm_shop_category_sync_configured() && !shop_category_sync_deletion_enabled() && !crm_shop_category_sync_deletion_enabled(),'both category features default off in both components');
$p=new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
try { crm_shop_category_sync_store($p,[],str_repeat('f',64)); check(false,'receiver'); } catch (DomainException $e) { check(true,'disabled receiver refuses writes'); }
try { shop_category_sync_send('{}'); check(false,'sender'); } catch (RuntimeException $e) { check(true,'disabled sender refuses network'); }
check((int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn()===0,'disabled code creates no tables');
