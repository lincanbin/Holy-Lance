<?php
/*
 * Holy Lance
 * https://github.com/lincanbin/Holy-Lance
 *
 * Copyright 2016 Canbin Lin (lincanbin@hotmail.com)
 * http://www.94cb.com/
 *
 * Licensed under the MIT License:
 * https://opensource.org/licenses/MIT
 * 
 * A Linux Resource / Performance Monitor based on PHP. 
 */

function api_exec_command($command)
{
	exec($command, $temp);
	return $temp[0];
}

header('Content-type: application/json');

define('SAMPLING_TIME', 250000); // 250ms

// load
$system_info = array(
	'status' => 1,
	'load' => array(0, 0, 0),
	'uptime' => '0',
	'cpu_usage' => 0,
	'logic_cpu_usage' => array()
);
$system_info['load'] = sys_getloadavg();

//uptime
$uptime = array();
exec("cat /proc/uptime | awk '{print $1}'", $uptime);
if (!empty($uptime)){
	$uptime[0] = intval($uptime[0]);
	$system_info['uptime'] = intval($uptime[0] / 86400) . ":" . sprintf("%02d", $uptime[0] % 86400 / 3600) . ":" . sprintf("%02d", $uptime[0] % 3600 / 60) . ":" . sprintf("%02d", $uptime[0] % 60);
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
exec("cat /proc/stat | grep 'cpu' | awk '{print $2+$3+$4+$5+$6+$7+$8+$9+$10\"\\n\" $5}'", $cpu_usage1);
exec("cat /proc/diskstats | awk '{print $3 \"\\n\" $6 \"\\n\" $7 \"\\n\" $10 \"\\n\" $11}'", $disk_usage1);
exec("cat /proc/net/dev | grep \":\" | awk '{print $1 $2 \":\"  $3 \":\" $10 \":\" $11}'", $network_status1);
usleep(SAMPLING_TIME);
exec("cat /proc/diskstats | awk '{print $3 \"\\n\" $6 \"\\n\" $7 \"\\n\" $10 \"\\n\" $11}'", $disk_usage2);
exec("cat /proc/net/dev | grep \":\" | awk '{print $1 $2 \":\"  $3 \":\" $10 \":\" $11}'", $network_status2);
exec("cat /proc/stat | grep 'cpu' | awk '{print $2+$3+$4+$5+$6+$7+$8+$9+$10\"\\n\" $5}'", $cpu_usage2);
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
	foreach (range(0, count($disk_usage1) / 5 - 1) as $offset) {
		$system_info['disk'][$disk_usage2[0 + $offset * 5]]['disk_read_speed'] = round(($disk_usage2[1 + $offset * 5] - $disk_usage1[1 + $offset * 5]) / 2 / (SAMPLING_TIME / 1000000), 1);
		$system_info['disk'][$disk_usage2[0 + $offset * 5]]['disk_read_active_time'] = round(($disk_usage2[2 + $offset * 5] - $disk_usage1[2 + $offset * 5]) / (SAMPLING_TIME / 1000000), 1);
		$system_info['disk'][$disk_usage2[0 + $offset * 5]]['disk_read_kibibytes'] = round($disk_usage2[1 + $offset * 5] / 2);
		$system_info['disk'][$disk_usage2[0 + $offset * 5]]['disk_write_speed'] = round(($disk_usage2[3 + $offset * 5] - $disk_usage1[3 + $offset * 5]) / 2 / (SAMPLING_TIME / 1000000), 1);
		$system_info['disk'][$disk_usage2[0 + $offset * 5]]['disk_write_active_time'] = round(($disk_usage2[4 + $offset * 5] - $disk_usage1[4 + $offset * 5]) / (SAMPLING_TIME / 1000000), 1);
		$system_info['disk'][$disk_usage2[0 + $offset * 5]]['disk_write_kibibytes'] = round($disk_usage2[3 + $offset * 5] / 2);
	}
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
$system_info['memory_usage_total'] = api_exec_command("free | grep \"Mem\" | awk '{print $2}'");
$system_info['memory_usage_used'] = api_exec_command("free | grep \"Mem\" | awk '{print $3}'");
$system_info['memory_usage_free'] = api_exec_command("free | grep \"Mem\" | awk '{print $4}'");
$system_info['memory_usage_buff'] = api_exec_command("cat /proc/meminfo | grep Buffers: | awk '{print $2}'");
$system_info['memory_usage_cache'] = api_exec_command("cat /proc/meminfo | grep Cached: | awk '{print $2}'");
$system_info['memory_usage_available'] = api_exec_command("cat /proc/meminfo | grep MemAvailable: | awk '{print $2}'");

$system_info['memory_usage_swap_total'] = api_exec_command("free | grep \"Swap\" | awk '{print $2}'");
$system_info['memory_usage_swap_used'] = api_exec_command("free | grep \"Swap\" | awk '{print $3}'");
$system_info['memory_usage_swap_free'] = api_exec_command("free | grep \"Swap\" | awk '{print $4}'");

// process
$process_number = array();
exec("ps -ef|wc -l", $process_number);
$system_info['process_number'] = $process_number[0];
unset($process_number);

$process_list = array();
exec("ps auxw", $process_list); //  --sort=time
if (!empty($process_list)) {
	unset($process_list[0]);
	$process_map = array();
	foreach (array_reverse($process_list) as $key => $value) {
		$process_map[] = explode(" ", preg_replace("/\s(?=\s)/","\\1", $value), 11);
	}
	$system_info['process'] = $process_map;
}
unset($process_list);
if (version_compare(PHP_VERSION, '5.4.0') < 0) {
	echo json_encode($system_info);
} else {
	echo json_encode($system_info, JSON_PRETTY_PRINT);
}
?>