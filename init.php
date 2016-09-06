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
 * A Linux environmental probe.
 */
header('Content-type: application/json');

exec("cat /proc/net/dev | grep \":\" | awk '{gsub(\":\", \"\");print $1}'", $network_cards);

$system_env = array(
	'version' => 1,
	'cpu' => [],
	'memory' => [],
	'network' => $network_cards
);
echo json_encode($system_env, JSON_PRETTY_PRINT);
?>