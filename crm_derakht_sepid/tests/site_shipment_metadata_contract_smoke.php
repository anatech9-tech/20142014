<?php
/** Pure contract fixtures only: no DB, transport, authentication, receiver or UI. */
require_once __DIR__.'/../../website/includes/shipments/ShipmentMetadataContract.php';
require_once __DIR__.'/../includes/site_shipment_metadata_contract.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function rejects(callable $fn,$label) { try { $fn(); check(false,$label); } catch (DomainException $e) { check(!str_contains($e->getMessage(),'PRIVATE'),$label); } }
$source=str_repeat('a',32); $event=str_repeat('b',32);
$row=['id'=>'7','service_type'=>'land','status'=>'registered','is_public'=>'0',
 'tracking_code'=>'PRIVATE TRACKING','customer_name'=>'PRIVATE NAME','customer_email'=>'PRIVATE EMAIL','customer_phone'=>'PRIVATE PHONE',
 'origin_country'=>'PRIVATE ORIGIN','origin_city'=>'PRIVATE CITY','destination_country'=>'PRIVATE DESTINATION','destination_city'=>'PRIVATE CITY2',
 'notes'=>'PRIVATE NOTES','created_by'=>'123','created_at'=>'PRIVATE DATE','updated_at'=>'PRIVATE DATE2'];
$data=site_shipment_metadata_event($source,$event,42,$row); $json=json_encode($data);
check(crm_site_shipment_metadata_validate(json_decode($json,true),$source),'producer and CRM validator agree on exact JSON contract');
check(array_keys($data['shipment'])===['id','service_type','status','is_public'] && !str_contains($json,'PRIVATE') && !str_contains($json,'customer_') && !str_contains($json,'tracking_code'),'only allowlisted metadata appears in event');
foreach (['registered','picked_up','in_transit','customs_clearance','out_for_delivery','delivered','cancelled','on_hold'] as $status) foreach (['air','sea','land','multimodal'] as $service) foreach ([0,1] as $public) {
 $item=$row; $item['status']=$status; $item['service_type']=$service; $item['is_public']=(string)$public;
 check(crm_site_shipment_metadata_validate(site_shipment_metadata_event($source,$event,1,$item),$source),$status.' '.$service.' '.$public);
}
foreach ([null,0,'0','01','-1','+1',' 1','1.0',true,[],(string)PHP_INT_MAX.'0'] as $bad) { $item=$row; $item['id']=$bad; rejects(fn()=>site_shipment_metadata_snapshot($item),'invalid identifier'); }
foreach (['2','-1','01',true,1.0,null] as $bad) { $item=$row; $item['is_public']=$bad; rejects(fn()=>site_shipment_metadata_snapshot($item),'invalid public marker'); }
foreach (['status','service_type'] as $key) { $item=$row; $item[$key]='PRIVATE unknown'; rejects(fn()=>site_shipment_metadata_snapshot($item),'unknown '.$key.' rejected without disclosure'); }
rejects(fn()=>site_shipment_metadata_event('bad',$event,1,$row),'invalid source rejected'); rejects(fn()=>site_shipment_metadata_event($source,$event,0,$row),'zero revision rejected');
$mutations=[]; $bad=$data; $bad['shipment']['customer_name']='PRIVATE NAME'; $mutations[]=$bad;
$bad=$data; $bad['extra']='PRIVATE'; $mutations[]=$bad; $bad=$data; unset($bad['shipment']['status']); $mutations[]=$bad;
$bad=$data; $bad['shipment']['id']='7'; $mutations[]=$bad; $bad=$data; $bad['revision']='42'; $mutations[]=$bad;
$bad=$data; $bad['shipment']['is_public']=true; $mutations[]=$bad; $bad=$data; $bad['shipment']['status']='PRIVATE'; $mutations[]=$bad;
$bad=$data; $bad['type']='shop_order'; $mutations[]=$bad; $bad=$data; $bad['source_id']=str_repeat('c',32); $mutations[]=$bad;
$bad=$data; $bad['event_id']=str_repeat('z',32); $mutations[]=$bad; $bad=$data; $bad['revision']=0; $mutations[]=$bad;
foreach ($mutations as $bad) check(!crm_site_shipment_metadata_validate($bad,$source),'malformed, extra or cross-source field rejected');
check(!crm_site_shipment_metadata_validate($data,'invalid'),'invalid configured source rejected');
