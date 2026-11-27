<?php
/** Default off on both sides; no data or writes. */
require_once __DIR__.'/../../website/includes/shipments/ShipmentMetadataSync.php'; require_once __DIR__.'/../includes/site_shipment_metadata_sync.php';
function check($v,$label) { if (!$v) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
check(!site_shipment_sync_configured() && !crm_site_shipment_sync_configured(),'both shipment bridge sides default disabled');
$p=new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
try { crm_site_shipment_sync_store($p,[],str_repeat('f',64)); check(false,'disabled receiver'); } catch (DomainException $e) { check(true,'disabled receiver rejects store'); }
check((int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn()===0,'disabled receiver creates no tables');
try { site_shipment_sync_send('{}'); check(false,'disabled sender'); } catch (RuntimeException $e) { check(true,'disabled sender refuses network'); }
