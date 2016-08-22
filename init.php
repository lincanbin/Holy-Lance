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
error_reporting(E_ALL); 
ini_set('display_errors', 'On');

exec("cat /proc/net/dev | grep \":\" | awk '{gsub(\":\", \"\");print $1}'", $network_cards);

$system_env = array(
	'cpu' => [],
	'memory' => [],
	'network' => $network_cards
);
echo json_encode($system_env, JSON_PRETTY_PRINT);