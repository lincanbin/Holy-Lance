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
define('SAMPLING_TIME', 200000); // 200ms

// load
$system_info = array();
$system_info['load'] = sys_getloadavg();

//uptime
$uptime = array();
exec("uptime | awk -F ',' '{print $1 $2}' | awk -F 'up' '{print $2}'", $uptime);
$system_info['uptime'] = $uptime[0];
unset($uptime);

// network
$network_status = array();
$network_usage = array();
exec("cat /proc/net/dev | grep \":\" | awk '{print $1 $2 \":\"  $3 \":\" $10 \":\" $11}'", $network_status);
foreach ($network_status as $eth) {
	$eth = explode(":", $eth);
	$network_card_name = $eth[0];
	unset($eth[0]);
	$network_usage[$network_card_name] = $eth;
}
$system_info['network'] = $network_usage;
unset($network_status);
unset($network_usage);

// cpu: % and disk
$cpu_usage1 = array();
$cpu_usage2 = array();
$disk_usage1 = array();
$disk_usage2 = array();
// Deprecated Code: Low performance
// exec("top -b -n2 | grep \"Cpu(s)\"|tail -n 1 | awk '{print $2 + $4}'", $cpu_usage);
exec("grep 'cpu ' /proc/stat | awk '{usage=($2+$3+$4+$5+$6+$7+$8+$9+$10)} END {print usage\"\\n\"$5}'", $cpu_usage1);
exec("cat /proc/diskstats | grep \"da\" | head -n1 | awk -F 'da' '{print $2}' | awk '{print $3 \"\\n\" $4 \"\\n\" $7 \"\\n\" $8}'", $disk_usage1);
usleep(SAMPLING_TIME);
exec("cat /proc/diskstats | grep \"da\" | head -n1 | awk -F 'da' '{print $2}' | awk '{print $3 \"\\n\" $4 \"\\n\" $7 \"\\n\" $8}'", $disk_usage2);
exec("grep 'cpu ' /proc/stat | awk '{usage=($2+$3+$4+$5+$6+$7+$8+$9+$10)} END {print usage\"\\n\"$5}'", $cpu_usage2);
$system_info['cpu_usage'] = round((($cpu_usage2[0] - $cpu_usage1[0]) - ($cpu_usage2[1] - $cpu_usage1[1])) / ($cpu_usage2[0] - $cpu_usage1[0]) * 100, 1);
unset($cpu_usage1);
unset($cpu_usage2);
unset($disk_usage1);
unset($disk_usage2);

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
unset($process_list[0]);
$process_map = array();
foreach (array_reverse($process_list) as $key => $value) {
	$process_map[] = explode(" ", preg_replace("/\s(?=\s)/","\\1", $value), 11);
}
unset($process_list);
$system_info['process'] = $process_map;

echo json_encode($system_info, JSON_PRETTY_PRINT);