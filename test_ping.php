<?php
set_time_limit(0);

if (defined('HAS_BEEN_COMPILED') === false) {
	require __DIR__ . '/common.php';
}
header('Content-type: application/json');
check_password();

$ip = '';
if (php_sapi_name() === "cli") {
	$ip = '8.8.8.8';// For debug onlu
} else {
	if (!empty($_REQUEST['ip'])) {
		if (filter_var($_REQUEST['ip'], FILTER_VALIDATE_IP) !== false) {
			$ip = $_REQUEST['ip'];
		} else {
			$ip = gethostbyaddr($_REQUEST['ip']);
			if ($ip = $_REQUEST['ip']) {
				$ip = '';
			}
		}
	}
}
if ($ip) {
	echo json_encode(array('status' => true, 'ip' => $ip, 'result' => ping($ip)));
} else {
	echo json_encode(array('status' => false, 'result' => 'Invalid IP'));
}
?>