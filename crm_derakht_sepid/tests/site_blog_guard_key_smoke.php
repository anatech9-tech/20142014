<?php
/** A guard must not reuse the bridge key; failure does not capture content. */
define('SITE_BLOG_META_ENABLED',true); define('SITE_BLOG_META_KEY',str_repeat('b',32)); define('SITE_BLOG_META_URL','https://example.test/api'); define('SITE_BLOG_SOURCE_ID',str_repeat('a',32)); define('SITE_BLOG_CONTENT_GUARD_ENABLED',true); define('SITE_BLOG_CONTENT_GUARD_KEY',str_repeat('b',32));
require_once __DIR__.'/../../website/includes/blog/CrmBlogMetadataSync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
check(site_blog_sync_configured() && site_blog_guard_requested() && !site_blog_guard_configured(),'reused bridge key invalidates guard');
$p=new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
try { site_blog_sync_ensure($p); check(false,'schema'); } catch (RuntimeException $e) { check(true,'misconfigured guard stops worker setup'); }
try { site_blog_sync_capture($p,SITE_BLOG_SOURCE_ID,7); check(false,'capture'); } catch (RuntimeException $e) { check(!$p->inTransaction(),'misconfigured guard rolls back capture'); }
check((int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn()===0,'no tables or data created');
