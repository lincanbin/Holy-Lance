<?php
set_time_limit(0);

if (defined('HAS_BEEN_COMPILED') === false) {
	require __DIR__ . '/common.php';
}
header('Content-type: application/json');
check_password();

$ip = (!empty($_GET['ip']) && filter_var($_GET['ip'], FILTER_VALIDATE_IP)) ? $_GET['ip'] : '';
if ($ip) {
	system('ping -c4 -w1000 ' . $ip);
} else {
	echo 'Invalid IP';
}
?>