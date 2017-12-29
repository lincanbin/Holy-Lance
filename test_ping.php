<?php
set_time_limit(0);

if (defined('HAS_BEEN_COMPILED') === false) {
	require __DIR__ . '/common.php';
}
header('Content-type: application/json');
check_password();



if (php_sapi_name() === "cli") {
	$ip = '8.8.8.8';// For debug onlu
} else {
	$ip = (!empty($_REQUEST['ip']) && filter_var($_REQUEST['ip'], FILTER_VALIDATE_IP)) ? $_REQUEST['ip'] : '';
}
if ($ip) {
	echo json_encode(array('status' => true, 'ip' => $ip, 'result' => ping($ip)));
} else {
	echo json_encode(array('status' => false, 'result' => 'Invalid IP'));
}
?>