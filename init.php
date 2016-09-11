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
function get_cpu_info_map($cpu_info_val)
{
	$result = array();
	foreach (explode("\n", $cpu_info_val) as $value) {
		if ($value) {
			$item = array_map("trim", explode(":", $value));
			$result[str_replace(" ", "_", $item[0])] = $item[1];
		}
	}
	return $result;
}

function get_mem_info_map($mem_info)
{
	$result = array();
	foreach ($mem_info as $value) {
		$value = str_ireplace(")", "", str_ireplace("(", "_", str_ireplace("kB", "", $value)));
		$item = array_map("trim", explode(":", $value));
		$result[str_replace(" ", "_", $item[0])] = $item[1];
	}
	return $result;
}

header('Content-type: application/json');

exec("cat /proc/net/dev | grep \":\" | awk '{gsub(\":\", \"\");print $1}'", $network_cards);
$cpu_info = array_map("get_cpu_info_map", explode("\n\n", trim(file_get_contents("/proc/cpuinfo"))));
$memory_info = get_mem_info_map(explode("\n", trim(file_get_contents("/proc/meminfo"))));
$system_env = array(
	'version' => 1,
	'psssword_require' => false,
	'cpu' => $cpu_info,
	'memory' => $memory_info,
	'network' => $network_cards
);

if (version_compare(PHP_VERSION, '5.4.0') < 0) {
	echo json_encode($system_env);
} else {
	echo json_encode($system_env, JSON_PRETTY_PRINT);
}
?>