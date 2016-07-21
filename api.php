<?php
/*
 * Carbon-Probe
 * https://github.com/lincanbin/Carbon-Probe
 *
 * Copyright 2016 Canbin Lin (lincanbin@hotmail.com)
 * http://www.94cb.com/
 *
 * Licensed under the MIT License:
 * http://www.apache.org/licenses/LICENSE-2.0
 * 
 * A Linux environmental probe.
 */
// load
$system_info = array();
$system_info['load'] = sys_getloadavg();

// cpu
$cpu_usage = array();
exec("top -b -n2 | grep \"Cpu(s)\"|tail -n 1 | awk '{print $2 + $4}'", $cpu_usage);
$system_info['cpu_usage'] = $cpu_usage[0];
unset($cpu_usage);

// memory
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
// disk

// network

// process
$process_list = array();
exec("ps auxw --sort=time", $process_list);
$process_map = array();
foreach (array_reverse($process_list) as $key => $value) {
	$process_map[] = explode(" ", preg_replace("/\s(?=\s)/","\\1", $value), 11);
}
unset($process_list);
$system_info['process'] = $process_map;

echo json_encode($system_info, JSON_PRETTY_PRINT);