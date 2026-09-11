<?php
require_once __DIR__ . '/../web/config/ugp_parts.php';
function verify($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$ctx = ['dry_run' => true, 'link_on_normalized_name' => true,
    'countries' => [['id' => 'ls', 'country_code' => 'LSO']],
    'categories' => [['id' => 'inv', 'item_class' => 'Inventory']],
    'all_assets' => [['id'=>'existing','name'=>'Suspension Clamp','item_class'=>'Material','country_id'=>'ls']]];
$r = am_ugp_sync_single_part(['ugp_part_id'=>'suspension-clamp','name'=>'Suspension Clamp','country_id'=>'ls'], $ctx);
verify(!$r['ok'] && $r['action']==='ambiguous', 'Explicit legacy auto-link flag must not authorize a name-only mapping');
verify(count($r['candidates'])===1, 'Material stock items are review candidates, not duplicate catalogue rows');
$ctx['all_assets']=[];
foreach ([10, 1.5, 'bad'] as $quantity) {
    $r=am_ugp_sync_single_part(['ugp_part_id'=>'clamp','name'=>'Clamp','country_id'=>'ls','quantity'=>$quantity],$ctx);
    verify(!$r['ok'], 'Catalogue sync must not create received stock');
}
echo "ugp_mapping_review_test: OK\n";
