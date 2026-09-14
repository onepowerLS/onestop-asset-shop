<?php
// Synthetic view harness: no sessions, credentials, database access or writes.
require __DIR__.'/../../web/config/reconciliation.php';
$parts=json_decode(file_get_contents(__DIR__.'/../../web/data/mas-mapping-review.json'),true)['parts'];$ids=array_keys($parts);$partId=$ids[0];$part=$parts[$partId];
$approved=[];$error='';$message='';$q='';$eventId='demo';$media=[];$history=[];$_SESSION=['am_receipt_csrf'=>'demo'];
$shown=[];for($i=0;$i<3;$i++)$shown[]=['id'=>'a'.$i,'name'=>['ABC clamp 25–95','Dead end clamp','Suspension bracket'][$i],'description'=>'Fixture description: check conductor range and manufacturer rating.','unit_of_measure'=>'EA','quantity'=>20,'location_id'=>'MAS','asset_tag'=>'AM-00'.$i];$filtered=$shown;
?><!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{max-width:1100px;margin:auto;padding:24px;color:#24414a}h1{font-size:2rem}h2{font-size:1.4rem}p{line-height:1.6}</style></head><body><?php include __DIR__.'/../../web/includes/reconciliation_view.php';?></body></html>
