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
$system_info = array();
$system_info['load'] = sys_getloadavg();

$process_list = array();
exec("ps auxw --sort=time", $process_list);
$process_map = array();
foreach (array_reverse($process_list) as $key => $value) {
	$process_map[] = explode(" ", preg_replace("/\s(?=\s)/","\\1", $value), 11);
}
$system_info['process'] = $process_map;

echo json_encode($system_info, JSON_PRETTY_PRINT);