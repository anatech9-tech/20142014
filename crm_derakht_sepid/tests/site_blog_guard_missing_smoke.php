<?php
define('SITE_BLOG_CONTENT_GUARD_ENABLED',true);
require_once __DIR__.'/../../website/includes/blog/CrmBlogMetadataSync.php';
if (!site_blog_guard_requested() || site_blog_guard_configured()) { fwrite(STDERR,"FAIL missing guard key accepted\n"); exit(1); }
$p=new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
try { site_blog_sync_capture($p,str_repeat('a',32),1); fwrite(STDERR,"FAIL missing guard key did not stop scan\n"); exit(1); } catch (RuntimeException $e) { if ($p->inTransaction()) { fwrite(STDERR,"FAIL transaction left open\n"); exit(1); } }
echo "PASS requested guard without key stops scan and rolls back\n";
