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

$system_env = array(
	'cpu' => [],
	'memory' => [],
	'network' => []
);
echo json_encode($system_env, JSON_PRETTY_PRINT);