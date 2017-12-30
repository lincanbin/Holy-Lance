<?php
set_time_limit(0);

if (defined('HAS_BEEN_COMPILED') === false) {
	require __DIR__ . '/common.php';
}
header('Content-type: application/json');
check_password();


ini_set('precision', 49);
ini_set('serialize_precision', 49);

$ts1 = microtime(true);

$pi = 4;
$top = 4;
$bot = 3;
$minus = true;
$accuracy = !empty($_REQUEST['accuracy']) ? intval($_REQUEST['accuracy']) : 100000000;

for ($i = 0; $i < $accuracy; $i++) {
	$pi += ($minus ? -($top / $bot) : ($top / $bot));
	$minus = ($minus ? false : true);
	$bot += 2;
}
echo json_encode(array(
	'status' => true,
	'time' => number_format((microtime(true) - $ts1) * 1000, 2, '.', ' ') . ' ms',
	'pi' => $pi,
	'real_pi' => pi()
));
?>