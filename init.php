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
header('Content-type: application/json');

exec("cat /proc/net/dev | grep \":\" | awk '{gsub(\":\", \"\");print $1}'", $network_cards);

$system_env = array(
	'version' => 1,
	'cpu' => array(),
	'memory' => array(),
	'network' => $network_cards
);
if (version_compare(PHP_VERSION, '5.4.0') < 0) {
	echo json_encode($system_env);
} else {
	echo json_encode($system_env, JSON_PRETTY_PRINT);
}
?>