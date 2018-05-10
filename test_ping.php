<?php
set_time_limit(0);

if (defined('HAS_BEEN_COMPILED') === false) {
	require __DIR__ . '/common.php';
}
header('Content-type: application/json');
check_password();

$ip = '';
$port = 80;
if (!empty($_REQUEST['port'])) {
	$port = intval($_REQUEST['port']);
}
if (php_sapi_name() === "cli") {
	$ip = '8.8.8.8';// For debug only
	$port = 53;
} else {
	if (!empty($_REQUEST['ip'])) {
		if (filter_var($_REQUEST['ip'], FILTER_VALIDATE_IP) !== false) {
			$ip = $_REQUEST['ip'];
		} else {
			$ip = gethostbyname($_REQUEST['ip']);
			if ($ip === $_REQUEST['ip']) {
				$ip = '';
			}
		}
	}
}
if ($ip) {
	echo json_encode(array('status' => true, 'ip' => $ip, 'result' => ping($ip, $port)));
} else {
	echo json_encode(array('status' => false, 'result' => 'Invalid IP'));
}
?>