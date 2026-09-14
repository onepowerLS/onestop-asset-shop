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
