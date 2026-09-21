<?php
require __DIR__ . '/../web/config/catalog_search.php';

function check($ok, string $msg): void {
    if (!$ok) {
        throw new RuntimeException($msg);
    }
}

$drone = am_catalog_search_match(am_catalog_search_fields(['name' => 'Survey drone']), 'drones');
check($drone !== null && in_array('name', $drone['reasons'], true), 'plural drones matches drone in the name');

$uav = am_catalog_search_match(am_catalog_search_fields(['name' => 'Mavic', 'notes' => 'UAV spare']), 'drones');
check($uav !== null && in_array('notes', $uav['reasons'], true), 'drones matches related word UAV in notes');
check($drone['score'] > $uav['score'], 'name match ranks above a notes match');

$both = am_catalog_search_match(am_catalog_search_fields(['name' => 'DJI Mavic drone']), 'dji drone');
check($both !== null, 'every search word must match');
$miss = am_catalog_search_match(am_catalog_search_fields(['name' => 'DJI Mavic']), 'dji clamp');
check($miss === null, 'a missing word excludes the item');

$battery = am_catalog_search_match(am_catalog_search_fields(['name' => 'Lithium battery']), 'batteries');
check($battery !== null, 'batteries matches battery');

$related = am_catalog_search_parse('drones');
check(in_array('uav', $related['related'], true) && in_array('quadcopter', $related['related'], true), 'related words are reported');
$prefix = am_catalog_search_match(am_catalog_search_fields(['name' => 'Survey drone']), 'dro');
check($prefix !== null, 'a prefix of three letters matches the word');
$pigtail = am_catalog_search_match(am_catalog_search_fields(['name' => 'M16 pig tail']), 'M16 pigtail');
check($pigtail !== null, 'pigtail matches pig tail');

echo "catalog_search_test: OK\n";
