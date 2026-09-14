<?php
require __DIR__.'/../web/config/reconciliation.php';
require __DIR__.'/../web/config/reference_photos.php';
function check($v) { if(!$v) throw new RuntimeException('Assertion failed'); }
$p=['name'=>'ABC clamp','candidateIds'=>['a']];
check(am_workshop_rank(['id'=>'a'],'clamp',$p)===500);
check(am_workshop_rank(['id'=>'b','ugp_part_id'=>'clamp'],'clamp',$p)===1000);
check(am_workshop_identity(['name'=>'Test','quantity'=>999])===['name'=>'Test','unit'=>'','ugpPartId'=>'','definitionId'=>'','manufacturer'=>'','model'=>'','description'=>'']);
$in=tempnam(sys_get_temp_dir(),'photo-in');$out=tempnam(sys_get_temp_dir(),'photo-out');
try {
 file_put_contents($in,'<svg onload="alert(1)"></svg>');
 try {am_reference_photo_prepare($in,$out);throw new RuntimeException('Accepted SVG');} catch(RuntimeException $e){check($e->getMessage()!=='Accepted SVG');}
 if(extension_loaded('gd')) {
  $im=imagecreatetruecolor(100,100);imagepng($im,$in);unset($im);
  $r=am_reference_photo_prepare($in,$out);check(strlen($r['sha256'])===64);check(getimagesize($out)['mime']==='image/jpeg');
 } else echo "GD image re-encoding test requires production-compatible GD runtime.\n";
} finally {unlink($in);unlink($out);}
echo "PASS: workshop identity/ranking and image validation\n";

$evidence=json_decode(file_get_contents(__DIR__.'/../web/data/mas-specification-evidence.json'),true)['entries'];
foreach($evidence as $e) {
 $a=['id'=>$e['assetId']];
 foreach(['name'=>'name','unit'=>'unit_of_measure','ugpPartId'=>'ugp_part_id','definitionId'=>'definition_id','manufacturer'=>'manufacturer','model'=>'model','description'=>'description'] as $k=>$v) $a[$v]=$e['identity'][$k];
 check(am_workshop_evidence($a,$e['partId'])===$e);
 check(am_workshop_rank($a,$e['partId'],['name'=>'test'])===['strong'=>800,'candidate'=>650,'difference'=>100][$e['band']]);
 $a['description'].=' changed';check(am_workshop_evidence($a,$e['partId'])===null);
}
echo "PASS: specification evidence ranking and stale identity rejection\n";
