<?php
/*
 * Carbon-Probe
 * https://github.com/lincanbin/Carbon-Probe
 *
 * Copyright 2016 Canbin Lin (lincanbin@hotmail.com)
 * http://www.94cb.com/
 *
 * Licensed under the MIT License:
 * https://opensource.org/licenses/MIT
 * 
 * A Linux environmental probe.
 */
header('Content-type: application/json');

define('SAMPLING_TIME', 250000); // 250ms

// load
$system_info = array(
	'load' => array(0, 0, 0),
	'uptime' => '0',
	'cpu_usage' => 0,
	'logic_cpu_usage' => array()
);
$system_info['load'] = sys_getloadavg();

//uptime
$uptime = array();
exec("uptime | awk -F ',' '{print $1 $2}' | awk -F 'up' '{print $2}'", $uptime);
if (!empty($uptime)){
	$system_info['uptime'] = $uptime[0];
}
unset($uptime);

// cpu: % and disk and network
$cpu_usage1 = array();
$cpu_usage2 = array();
$disk_usage1 = array();
$disk_usage2 = array();
$network_status1 = array();
$network_status2 = array();
// Deprecated Code: Low performance
// exec("top -b -n2 | grep \"Cpu(s)\"|tail -n 1 | awk '{print $2 + $4}'", $cpu_usage);
exec("grep 'cpu' /proc/stat | awk '{print $2+$3+$4+$5+$6+$7+$8+$9+$10\"\\n\" $5}'", $cpu_usage1);
exec("cat /proc/diskstats | grep \"da\" | head -n1 | awk -F 'da' '{print $2}' | awk '{print $3 \"\\n\" $4 \"\\n\" $7 \"\\n\" $8}'", $disk_usage1);
exec("cat /proc/net/dev | grep \":\" | awk '{print $1 $2 \":\"  $3 \":\" $10 \":\" $11}'", $network_status1);
usleep(SAMPLING_TIME);
exec("cat /proc/diskstats | grep \"da\" | head -n1 | awk -F 'da' '{print $2}' | awk '{print $3 \"\\n\" $4 \"\\n\" $7 \"\\n\" $8}'", $disk_usage2);
exec("cat /proc/net/dev | grep \":\" | awk '{print $1 $2 \":\"  $3 \":\" $10 \":\" $11}'", $network_status2);
exec("grep 'cpu' /proc/stat | awk '{print $2+$3+$4+$5+$6+$7+$8+$9+$10\"\\n\" $5}'", $cpu_usage2);
// CPU
if (!empty($cpu_usage1)) {
	foreach (range(0, count($cpu_usage1) / 2 - 1) as $offset) {
		$cpu_usage = round((($cpu_usage2[0 + $offset * 2] - $cpu_usage1[0 + $offset * 2]) - ($cpu_usage2[1 + $offset * 2] - $cpu_usage1[1 + $offset * 2])) / ($cpu_usage2[0 + $offset * 2] - $cpu_usage1[0 + $offset * 2]) * 100, 1);
		if ($offset === 0) {
			$system_info['cpu_usage'] = $cpu_usage;
		} else {
			$system_info['logic_cpu_usage'][$offset - 1] = $cpu_usage;
		}
	}
}

//Disk: KiB per second
if (!empty($disk_usage1)) {
	$system_info['disk_read_speed'] = round(($disk_usage2[0] - $disk_usage1[0]) / 2 / (SAMPLING_TIME / 1000000), 1);
	$system_info['disk_read_active_time'] = round(($disk_usage2[1] - $disk_usage1[1]) / (SAMPLING_TIME / 1000000), 1);
	$system_info['disk_write_speed'] = round(($disk_usage2[2] - $disk_usage1[2]) / 2 / (SAMPLING_TIME / 1000000), 1);
	$system_info['disk_write_active_time'] = round(($disk_usage2[3] - $disk_usage1[3]) / (SAMPLING_TIME / 1000000), 1);
}
unset($cpu_usage1);
unset($cpu_usage2);
unset($disk_usage1);
unset($disk_usage2);

//Network: Bytes per second
$network_usage = array();
if (!empty($network_status2)) {
	foreach ($network_status2 as $key => $eth) {
		$eth_previous = explode(":", $network_status1[$key]);
		$eth = explode(":", $eth);
		$network_card_name = $eth[0];
		unset($eth_previous[0]);
		unset($eth[0]);
		$network_usage[$network_card_name] = array(
			"receive_bytes" => $eth[1],
			"receive_packets" => $eth[2],
			"receive_speed" => ($eth[1] - $eth_previous[1]) / (SAMPLING_TIME / 1000000),
			"transmit_bytes" => $eth[3],
			"transmit_packets" => $eth[4],
			"transmit_speed" => ($eth[3] - $eth_previous[3]) / (SAMPLING_TIME / 1000000)
		);
	}
	$system_info['network'] = $network_usage;
}
unset($network_status1);
unset($network_status2);
unset($network_usage);

// memory: KiB
$memory_usage_total = array();
exec("free | grep \"Mem\" | awk '{print $2}'", $memory_usage_total);
$system_info['memory_usage_total'] = $memory_usage_total[0];
unset($memory_usage_total);

$memory_usage_used = array();
exec("free | grep \"Mem\" | awk '{print $3}'", $memory_usage_used);
$system_info['memory_usage_used'] = $memory_usage_used[0];
unset($memory_usage_used);

$memory_usage_free = array();
exec("free | grep \"Mem\" | awk '{print $4}'", $memory_usage_free);
$system_info['memory_usage_free'] = $memory_usage_free[0];
unset($memory_usage_free);

$memory_usage_swap_total = array();
exec("free | grep \"Swap\" | awk '{print $2}'", $memory_usage_swap_total);
$system_info['memory_usage_swap_total'] = $memory_usage_swap_total[0];
unset($memory_usage_swap_total);

$memory_usage_swap_used = array();
exec("free | grep \"Swap\" | awk '{print $3}'", $memory_usage_swap_used);
$system_info['memory_usage_swap_used'] = $memory_usage_swap_used[0];
unset($memory_usage_swap_used);

$memory_usage_swap_free = array();
exec("free | grep \"Swap\" | awk '{print $4}'", $memory_usage_swap_free);
$system_info['memory_usage_swap_free'] = $memory_usage_swap_free[0];
unset($memory_usage_swap_free);

// process
$process_list = array();
exec("ps auxw --sort=time", $process_list);
if (!empty($process_list)) {
	unset($process_list[0]);
	$process_map = array();
	foreach (array_reverse($process_list) as $key => $value) {
		$process_map[] = explode(" ", preg_replace("/\s(?=\s)/","\\1", $value), 11);
	}
	$system_info['process'] = $process_map;
}
unset($process_list);

echo json_encode($system_info, JSON_PRETTY_PRINT);
?>