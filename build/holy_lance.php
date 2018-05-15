<?php
define("HAS_BEEN_COMPILED", true);
?><?php

if (!defined('HOLY_LANCE_PASSWORD')) {
define('HOLY_LANCE_PASSWORD', '');
}

function convert_boolean($value)
{
if (is_bool($value) || in_array($value, array('true', 'false', '1', '0', 1, 0), true)) {
return $value ? '√' : '×';
} else {
return $value;
}
}

function get_config_value($varName)
{
return convert_boolean(get_cfg_var($varName));
}

//格式化文件大小
function format_bytes($size, $precision = 2)
{
// https://www.zhihu.com/question/21578998/answer/86401223
// According to Metric prefix, IEEE 1541-2002.
$units = array(
' Bytes',
' KiB',
' MiB',
' GiB',
' TiB'
);
for ($i = 0; $size >= 1024 && $i < 4; $i++)
$size /= 1024;
return round($size, $precision) . $units[$i];
}

function format_number($number)
{
return number_format($number, '0', '.', ' ');
}


function check_permission($file_name)
{
$fp = @fopen($file_name, 'w');
if (!$fp) {
return false;
} else {
fclose($fp);
@unlink($file_name);
return true;
}
}

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

function convert_timestamp_2_string($timestamp)
{
$timestamp = intval($timestamp);
return intval($timestamp / 86400) . ":"
. sprintf("%02d", $timestamp % 86400 / 3600) . ":"
. sprintf("%02d", $timestamp % 3600 / 60) . ":"
. sprintf("%02d", $timestamp % 60);
}

function check_password()
{
if (HOLY_LANCE_PASSWORD !== '' && (!isset($_POST['password']) || $_POST['password'] !== HOLY_LANCE_PASSWORD)) {
echo '{"status":false}';
exit(1);
}
return true;
}


// 创建row socket 需要root权限，所以用root账户在CLI下运行可以成功，用www用户在fpm下运行可能会失败，但是不会报错
// 需要root权限运行则要php-fpm -R运行
// 目前针对没有root权限做了一套临时的兼容方案
function ping($host, $port = 80)
{
$protocolNumber = getprotobyname('icmp');
$socket = socket_create(AF_INET, SOCK_RAW, $protocolNumber);
if ($socket === false) {// 没有Root权限，开启Raw socket失败，用TCP协议ping
return ping_without_root($host, $port);
}
socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 1, 'usec' => 0));
socket_connect($socket, $host, 0);
$package = "\x08\x00\x19\x2f\x00\x00\x00\x00\x70\x69\x6e\x67";
socket_send($socket, $package, strlen($package), 0);
$ts1 = microtime(true);
if (socket_read($socket, 255) !== false) {
$ts2 = microtime(true);
$result = round(($ts2 - $ts1) * 1000, 2) . ' ms';
} else {
$result = socket_strerror(socket_last_error($socket));
}
socket_close($socket);
return $result;
}

function ping_without_root($host, $port)
{
try {
$err_no = null;
$err_str = null;
$ts1 = microtime(true);
$fp = stream_socket_client("tcp://" . $host . ":" . $port, $err_no, $err_str, 3);
$ts2 = microtime(true);
$result = round(($ts2 - $ts1) * 1000, 2) . ' ms';
if ($fp === false) {
$result = 'Timeout';
}
fclose($fp);
} catch (Exception $exception) {
$result = 'Timeout';
}
return $result;
}
?><?php
if (!empty($_GET["file"]) && $_GET["file"] == "api.php"):
?><?php
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
if (defined('HAS_BEEN_COMPILED') === false) {
require __DIR__ . '/holy_lance.php?file=common.php';
}
header('Content-type: application/json');
check_password();

define('SAMPLING_TIME', 250000); // 250ms

// load
$system_info = array(
'status' => true,
'load' => array(0, 0, 0),
'uptime' => '0:0:0:0',
'cpu_usage' => 0,
'logic_cpu_usage' => array(),
'network_service' => array(),
'connection' => array(
'ESTABLISHED' => 0,
'SYN_SENT' => 0,
'SYN_RECV' => 0,
'FIN_WAIT1' => 0,
'FIN_WAIT2' => 0,
'TIME_WAIT' => 0,
'CLOSE' => 0,
'CLOSE_WAIT' => 0,
'LAST_ACK' => 0,
'LISTEN' => 0,
'CLOSING' => 0,
'UNKNOWN' => 0
),
'process' => array(),
'disk_free' => array()
);
$system_info['load'] = sys_getloadavg();

//uptime
$uptime = array();
exec("cat /proc/uptime | awk '{print $1}'", $uptime);
if (!empty($uptime)){
$uptime[0] = intval($uptime[0]);
    $system_info['uptime'] = convert_timestamp_2_string($uptime[0]);
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
exec("cat /proc/net/dev | grep \":\" | awk '{gsub(\":\", \" \");print $1 \":\" $2 \":\"  $3 \":\" $10 \":\" $11}'", $network_status1);
usleep(SAMPLING_TIME);
exec("cat /proc/diskstats | awk '{print $3 \"\\n\" $6 \"\\n\" $7 \"\\n\" $10 \"\\n\" $11}'", $disk_usage2);
exec("cat /proc/net/dev | grep \":\" | awk '{gsub(\":\", \" \");print $1 \":\" $2 \":\"  $3 \":\" $10 \":\" $11}'", $network_status2);
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
$system_info['memory_usage_total'] = trim(shell_exec("free | grep \"Mem\" | awk '{print $2}'"));
$system_info['memory_usage_used'] = trim(shell_exec("free | grep \"Mem\" | awk '{print $3}'"));
$system_info['memory_usage_free'] = trim(shell_exec("free | grep \"Mem\" | awk '{print $4}'"));
$system_info['memory_usage_buff'] = trim(shell_exec("cat /proc/meminfo | grep Buffers: | awk '{print $2}'"));
$system_info['memory_usage_cache'] = trim(shell_exec("cat /proc/meminfo | grep Cached: | head -n1 | awk '{print $2}'"));

$system_info['memory_usage_swap_total'] = trim(shell_exec("free | grep \"Swap\" | awk '{print $2}'"));
$system_info['memory_usage_swap_used'] = trim(shell_exec("free | grep \"Swap\" | awk '{print $3}'"));
$system_info['memory_usage_swap_free'] = trim(shell_exec("free | grep \"Swap\" | awk '{print $4}'"));

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
foreach ($process_list as $key => $value) {
$process_map[] = explode(" ", preg_replace("/\s(?=\s)/","\\1", $value), 11);
}
$system_info['process'] = $process_map;
}
unset($process_list);

// disk_free
$disk_free_list = array();
exec("df -T", $disk_free_list); //  --sort=time
if (!empty($disk_free_list)) {
unset($disk_free_list[0]);
$disk_free_map = array();
foreach ($disk_free_list as $key => $value) {
$disk_free_map[] = explode(" ", preg_replace("/\s(?=\s)/","\\1", $value), 7);
}
$system_info['disk_free'] = $disk_free_map;
}
unset($disk_free_list);

// network service
$temp_network_service_list = array();
exec("netstat -lntp | tail -n +3", $temp_network_service_list);
if (!empty($temp_network_service_list)) {
$network_service_list = array();
foreach ($temp_network_service_list as $key => $value) {
$network_service_list[] = explode(" ", preg_replace("/\s(?=\s)/","\\1", $value), 7);
}
$system_info['network_service'] = $network_service_list;
}
unset($temp_network_service_list);

// connections
$temp_connection = array();
exec("netstat -nat| tail -n +3 | awk '{print $6}'|sort|uniq -c", $temp_connection);
if (!empty($temp_connection)) {
$connection = array();
foreach ($temp_connection as $key => $value) {
$cur_connection = explode(" ", trim($value), 2);
$connection[$cur_connection[1]] = intval($cur_connection[0]);
}
$system_info['connection'] = array_merge($system_info['connection'], $connection);
}
unset($temp_connection);

if (version_compare(PHP_VERSION, '5.4.0') < 0) {
echo json_encode($system_info);
} else {
echo json_encode($system_info, JSON_PRETTY_PRINT);
}
?><?php
exit();
endif;
?><?php
if (!empty($_GET["file"]) && $_GET["file"] == "init.php"):
?><?php
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
if (defined('HAS_BEEN_COMPILED') === false) {
require __DIR__ . '/holy_lance.php?file=common.php';
}
header('Content-type: application/json');
check_password();


exec("cat /proc/net/dev | grep \":\" | awk -F ':' '{gsub(\" \", \"\"); if ($2 > 0) print $1}'", $network_cards);
exec("cat /proc/diskstats | awk '{if ($4 > 0) print $3}'", $disk);
$cpu_info = array(
'cpu_name' => trim(shell_exec('cat /proc/cpuinfo | grep name | cut -f2 -d: | head -1')), // CPU名称
'cpu_num' => trim(shell_exec('cat /proc/cpuinfo | grep "physical id"| sort | uniq | wc -l')), // CPU个数（X路CPU）
'cpu_core_num' => trim(shell_exec('cat /proc/cpuinfo | grep "cores" | uniq | awk -F ":" \'{print $2}\'')), // CPU核心数
'cpu_processor_num' => trim(shell_exec('cat /proc/cpuinfo | grep "processor" | wc -l')), // CPU逻辑处理器个数
'cpu_frequency' => trim(shell_exec('cat /proc/cpuinfo | grep MHz | uniq | awk -F ":" \'{print $2}\'')), // CPU 频率
);
$all_cpu_info = array_map("get_cpu_info_map", explode("\n\n", trim(shell_exec('cat /proc/cpuinfo'))));
$memory_info = get_mem_info_map(explode("\n", trim(shell_exec('cat /proc/meminfo'))));
$network_info = array();
foreach ($network_cards as $eth) {
$network_info[$eth]['ip'] = explode("\n", trim(shell_exec("ifconfig " . $eth . " | grep 'inet' | sed 's/addr://g' | awk '{print $2}'")));
}
$system_env = array(
'status' => true,
'version' => 1,
'system_name' => trim(shell_exec('cat /etc/*-release | head -n1')),
'psssword_require' => false,
'cpu_info' => $cpu_info,
'cpu' => $all_cpu_info,
'disk' => $disk,
'memory' => $memory_info,
'network' => $network_cards,
'network_info' => $network_info
);

if (version_compare(PHP_VERSION, '5.4.0') < 0) {
echo json_encode($system_env);
} else {
echo json_encode($system_env, JSON_PRETTY_PRINT);
}
?><?php
exit();
endif;
?><?php
if (!empty($_GET["file"]) && $_GET["file"] == "static/css/style.css"):
header("Content-type: text/css");?>﻿/*
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
html, #MainTab {
height:100%;
}
body {
height:100%;
margin: 0;
padding: 0;
background: #FFF;
font-family: 'Segoe UI';
}
table {
width: 100%;
border-collapse: collapse;
margin: 0 auto;
}
a {
text-decoration: none;
color: #3498db;
}
tr:nth-of-type(odd) {
background: #eee;
}
th {
background: #3498db;
color: white;
font-weight: bold;
cursor: pointer;
max-width: 400px;
}
.selected-col-desc:after {
content: "▼";
float: right;
}
.selected-col-asc:after {
content: "▲";
float: right;
}
td, th {
padding: 10px;
border: 1px solid #ccc;
text-align: left;
font-size: 18px;
max-width: 400px;
}
ul.resp-tabs-list, p {
margin: 0;
padding: 0;
}
.resp-tabs-list li {
font-weight: 600;
font-size: 13px;
display: inline-block;
padding: 20px 65px;
margin: 0 4px 0 0;
list-style: none;
cursor: pointer;
float: left;
}
.resp-tabs-list .tab-label {
font-size: 11px;
}
.resp-tabs-container {
padding: 0;
background-color: #fff;
clear: left;
}
h2.resp-accordion {
cursor: pointer;
padding: 5px;
display: none;
}
.resp-tab-content {
display: none;
padding: 15px 0;
}
.resp-tab-item .main {
border-top: 4px solid #C1C1C1 !important;
}
.resp-tab-active {
border: 1px solid #5AB1D0 !important;
border-bottom: none;
margin-bottom: -1px !important;
padding: 16px 64px 20px 64px !important;
border-top: 4px solid #5AB1D0 !important;
border-bottom: 0 #fff solid !important;
}
.resp-tab-active {
border-bottom: none;
background-color: #fff;
}
.resp-content-active, .resp-accordion-active {
display: block;
}
.resp-tab-content {
/*border: 1px solid #c1c1c1;*/
border-top-color: #5AB1D0;
}
h2.resp-accordion {
font-size: 13px;
border: 1px solid #c1c1c1;
border-top: 0 solid #c1c1c1;
margin: 0;
padding: 10px 15px;
}
h2.resp-tab-active {
border-bottom: 0 solid #c1c1c1 !important;
margin-bottom: 0 !important;
padding: 10px 15px !important;
}
h2.resp-tab-title:last-child {
border-bottom: 12px solid #c1c1c1 !important;
background: blue;
}
/*-----------Vertical tabs-----------*/
.resp-vtabs ul.resp-tabs-list {
float: left;
width: 15%;
min-width: 150px;
}
.resp-vtabs .resp-tabs-list li {
display: block;
padding: 15px 15px !important;
margin: 0 0 4px;
cursor: pointer;
float: none;
}
.resp-vtabs .resp-tabs-container {
padding: 0;
background-color: #fff;
/*border: 1px solid #c1c1c1;*/
float: left;
width: 83%;
min-height: 460px;
border-radius: 4px;
clear: none;
}
.resp-vtabs .resp-tab-content {
border: none;
word-wrap: break-word;
}
.resp-vtabs li.resp-tab-active {
position: relative;
z-index: 1;
margin-right: -1px !important;
padding: 14px 15px 15px 14px !important;
border-top: 1px solid;
border: 1px solid #5AB1D0 !important;
border-left: 4px solid #5AB1D0 !important;
margin-bottom: 4px !important;
border-right: 1px #FFF solid !important;
}
.resp-arrow {
width: 0;
height: 0;
float: right;
margin-top: 3px;
border-left: 6px solid transparent;
border-right: 6px solid transparent;
border-top: 12px solid #c1c1c1;
}
h2.resp-tab-active span.resp-arrow {
border: none;
border-left: 6px solid transparent;
border-right: 6px solid transparent;
border-bottom: 12px solid #9B9797;
}
/*-----------Accordion styles-----------*/
h2.resp-tab-active {
background: #DBDBDB;
/* !important;*/
}
.resp-easy-accordion h2.resp-accordion {
display: block;
}
.resp-easy-accordion .resp-tab-content {
border: 1px solid #c1c1c1;
}
.resp-easy-accordion .resp-tab-content:last-child {
border-bottom: 1px solid #c1c1c1;
/* !important;*/
}
.resp-jfit {
width: 100%;
margin: 0;
}
.resp-tab-content-active {
display: block;
}
h2.resp-accordion:first-child {
border-top: 1px solid #c1c1c1;
/* !important;*/
}
/*Here your can change the breakpoint to set the accordion, when screen resolution changed*/
@media only screen and (max-width: 960px) {
ul.resp-tabs-list {
display: none;
}
h2.resp-accordion {
display: block;
}
.resp-vtabs .resp-tab-content {
border: 1px solid #C1C1C1;
}
.resp-vtabs .resp-tabs-container {
border: none;
float: none;
width: 100%;
min-height: 100px;
clear: none;
}
.resp-accordion-closed {
display: none !important;
}
.resp-vtabs .resp-tab-content:last-child {
border-bottom: 1px solid #c1c1c1 !important;
}
}

.chart-title-set{
width: 85%;
margin: 0 auto;
margin-top: 10px;
position:relative;
}

.chart-title {
display: inline;
font-size: 35px;
font-weight: 400;
}

.chart-sub-title {
font-size: 25px;
float: right;
position: absolute;
bottom: 0;
right: 20px;
}

.info_block_container {
width: 85%;
margin: 0 auto;
margin-top: 10px;
margin-bottom: 200px;
}

.info_block_container:after {
content: ".";
display: block;
height: 0;
clear: both;
visibility: hidden;
}

.info_block {
display: inline;
float: left;
}

.info {
min-width: 65px;
display: block;
float: left;
margin: 5px 30px;
}

.info-clear{
clear: both;
}

.info-label {
color: #707070;
font-size: 12px;
display: block;
}

.info-content {
font-size: 22px;
font-weight: 500;
display: block;
}


.info-inline {
font-size: 12px;
display: block;
margin: 7px 10px;
}

.info-inline-label {
width: 120px;
color: #707070;
display: inline-block;
}

.info-inline-content {
display: inline;
}
<?php
exit();
endif;
?><?php
if (!empty($_GET["file"]) && $_GET["file"] == "static/js/common.js"):
header("Content-type: text/javascript");?>/*
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
var numberOfRecords  = 360; // points
var intervalTime = 3000; // ms
var password = '';

function getCpuColumn(cpuNumber) {
var start = Math.ceil(Math.sqrt(cpuNumber));
for (var i = start; i <= cpuNumber; i++) {
if ((cpuNumber % i) === 0) {
// console.log(Math.abs((cpuNumber / i) - i));
return i;
}
}
}

function cloneObject(obj) {
var copy;
// Handle the 3 simple types, and null or undefined
if (null === obj || "object" != typeof obj) return obj;
// Handle Date
if (obj instanceof Date) {
copy = new Date();
copy.setTime(obj.getTime());
return copy;
}
// Handle Array
if (obj instanceof Array) {
copy = [];
for (var i = 0, len = obj.length; i < len; i++) {
copy[i] = cloneObject(obj[i]);
}
return copy;
}
// Handle Object
if (obj instanceof Object) {
copy = {};
for (var attr in obj) {
if (obj.hasOwnProperty(attr)) copy[attr] = cloneObject(obj[attr]);
}
return copy;
}
throw new Error("Unable to copy obj! Its type isn't supported.");
}

function listSort(arr, field, order){ 
var refer = [], result = [], index; 
order = order == 'asc' ? 'asc' : 'desc';
for(i = 0; i < arr.length; i++){ 
refer[i] = arr[i][field] + '|' + i; 
} 
refer = refer.sort(function(a, b) {
return +/\d+/.exec(a)[0] - +/\d+/.exec(b)[0];
}); 
if(order=='desc') refer.reverse(); 
for(i = 0;i < refer.length;i++){ 
index = refer[i].split('|')[1]; 
result[i] = arr[index]; 
} 
return result; 
}

function numberFormatter(number) {
return number.toString().replace(/\d+?(?=(?:\d{3})+$)/img, "$& ");
}

function kibiBytesToSize(bytes) {
if (bytes == 0) return '0 B';
var kibi = 1024, // or 1000
sizes = ['KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'],
i = Math.floor(Math.log(bytes) / Math.log(kibi));
sizes[-1] = 'B';
return (bytes / Math.pow(kibi, i)).toFixed(2) + ' ' + sizes[i];
}

function resizeChart() {
if ($("#cpu_usage").is(":visible")) {
cpuUsageChart.setOption(cpuUsageChartoption);
window.cpuUsageChart.resize();
}
if ($("#logic_cpu_usage_container").is(":visible")) {
for (var i = 0; i < window.env.cpu.length; i++) {
window.logicCpuUsageChart[i].setOption(logicCpuUsageChartoption[i]);
window.logicCpuUsageChart[i].resize();
}
}
if ($("#load_usage").is(":visible")) {
loadUsageChart.setOption(loadUsageChartoption, true);
window.loadUsageChart.resize();
}
if ($("#memory_usage").is(":visible")) {
memoryUsageChart.setOption(memoryUsageChartoption);
window.memoryUsageChart.resize();
}
if ($("#connection_usage").is(":visible")) {
connectionUsageChart.setOption(connectionUsageChartoption);
window.connectionUsageChart.resize();
}
for (var offset in window.env.disk) {
if ($("#disk_" + window.env.disk[offset] + "_speed").is(":visible")) {
window.diskUsageChart[window.env.disk[offset]].setOption(diskUsageChartoption[window.env.disk[offset]]);
window.diskUsageChart[window.env.disk[offset]].resize();
window.diskSpeedChart[window.env.disk[offset]].setOption(diskSpeedChartoption[window.env.disk[offset]]);
window.diskSpeedChart[window.env.disk[offset]].resize();
}
}
for (var offset in window.env.network) {
if ($("#network_" + window.env.network[offset] + "_usage").is(":visible")) {
window.networkUsageChart[window.env.network[offset]].setOption(networkUsageChartoption[window.env.network[offset]]);
window.networkUsageChart[window.env.network[offset]].resize();
}

}


}

function init(data) {
var cpuNumber = data.cpu.length;
window.env = data;
window.processSortedBy = 2;
window.processOrder = 'desc';
// console.log(data);
for (var offset in data.disk) {
$("#PerformanceList").append('<li>磁盘 ' + 
data.disk[offset] + 
'<p><span class="tab-label" id="disk_' + data.disk[offset] + '_usage_label"></span></p></li>');
$("#PerformanceContainer").append('<div><div class="chart-title-set"><h2 class="chart-title">磁盘 ' + 
data.disk[offset] + 
'</h2><span class="chart-sub-title" id="disk_' + data.disk[offset] + '_size"></span></div>' + 
'<div id="disk_' + data.disk[offset] + '_usage" style="width: 100%; height: 460px;"></div>' +
'<div id="disk_' + data.disk[offset] + '_speed" style="width: 100%; height: 360px;"></div>' +
'<div class="info_block_container">' +
'<div class="info_block">' +
'<div class="info">' +
'<span class="info-label">活动时间</span>' +
'<span class="info-content" id="disk_' + data.disk[offset] + '_usage_info">0 %</span>' +
'</div>' +
'<div class="info">' +
'<span class="info-label">平均读取响应时间</span>' +
'<span class="info-content" id="disk_' + data.disk[offset] + '_read_active_time">0 毫秒</span>' +
'</div>' +
'<div class="info">' +
'<span class="info-label">平均写入响应时间</span>' +
'<span class="info-content" id="disk_' + data.disk[offset] + '_write_active_time">0 毫秒</span>' +
'</div>' +
'<div class="info-clear"></div>' +
'<div class="info">' +
'<span class="info-label">总读取字节</span>' +
'<span class="info-content" id="disk_' + data.disk[offset] + '_read_kibibytes">0 KiB</span>' +
'</div>' +
'<div class="info">' +
'<span class="info-label">总写入字节</span>' +
'<span class="info-content" id="disk_' + data.disk[offset] + '_write_kibibytes">0 KiB</span>' +
'</div>' +
'<div class="info-clear"></div>' +
'<div class="info">' +
'<span class="info-label">读取速度</span>' +
'<span class="info-content" id="disk_' + data.disk[offset] + '_read_speed">0 KiB / 秒</span>' +
'</div>' +
'<div class="info">' +
'<span class="info-label">写入速度</span>' +
'<span class="info-content" id="disk_' + data.disk[offset] + '_write_speed">0 KiB / 秒</span>' +
'</div>' +
'</div>' +
'</div>' +
'</div>');
}

for (var offset in data.network) {
$("#PerformanceList").append('<li>网卡 ' + 
data.network[offset] + 
'<p><span class="tab-label" id="network_' + data.network[offset] + '_usage_label"></span></p></li>');
var temp = '<div><div class="chart-title-set"><h2 class="chart-title">网卡 ' + 
data.network[offset] + 
'</h2><span class="chart-sub-title" id="eth_name_' + data.network[offset] + '"></span></div>' + 
'<div id="network_' + data.network[offset] + '_usage" style="width: 100%; height: 460px;"></div>' +
'<div class="info_block_container">' +
'<div class="info_block">' +
'<div class="info">' +
'<span class="info-label">发送速率</span>' +
'<span class="info-content" id="eth_' + data.network[offset] + '_transmit_speed">0 KiB / 秒</span>' +
'</div>' +
'<div class="info">' +
'<span class="info-label">接收速率</span>' +
'<span class="info-content" id="eth_' + data.network[offset] + '_receive_speed">0 KiB / 秒</span>' +
'</div>' +
'<div class="info-clear"></div>' +
'<div class="info">' +
'<span class="info-label">已发送字节</span>' +
'<span class="info-content" id="eth_' + data.network[offset] + '_transmit_bytes">0 KiB</span>' +
'</div>' +
'<div class="info">' +
'<span class="info-label">已接收字节</span>' +
'<span class="info-content" id="eth_' + data.network[offset] + '_receive_bytes">0 KiB</span>' +
'</div>' +
'<div class="info-clear"></div>' +
'<div class="info">' +
'<span class="info-label">已发送包</span>' +
'<span class="info-content" id="eth_' + data.network[offset] + '_transmit_packets">0</span>' +
'</div>' +
'<div class="info">' +
'<span class="info-label">已接收包</span>' +
'<span class="info-content" id="eth_' + data.network[offset] + '_receive_packets">0</span>' +
'</div>' +
'</div>' +
'<div class="info_block" id="eth_' + data.network[offset] + '_info">';
for (var offset2 in data.network_info[data.network[offset]].ip) {
var ip = data.network_info[data.network[offset]].ip[offset2];
var ip_version = ip.indexOf(":") !== -1 ? "6" : "4";
temp += '' +
'<div class="info-inline">' +
'<span class="info-inline-label">IPV' + ip_version + ' 地址:</span>' +
'<span class="info-inline-content">' + ip + '</span>' +
'</div>';
}
temp += '' +
'</div>' +
'</div>' +
'</div>';
$("#PerformanceContainer").append(temp);

// 逻辑处理器
var cpu_column = getCpuColumn(cpuNumber);
var logic_cpu_width = Math.floor(100 / cpu_column);
var logic_cpu_height = Math.floor(640 / (cpuNumber / cpu_column));
temp = '';
for (var i = 0; i < cpuNumber; i++) {
temp += '<div id="logic_cpu_' + i + '_usage" style="float: left;width: ' + logic_cpu_width + '%; height: ' + logic_cpu_height + 'px;"></div>';
}
$("#logic_cpu_usage_container").html(temp);
}

$('#MainTab').easyResponsiveTabs({
type: 'default', //Types: default, vertical, accordion
width: 'auto', //auto or any width like 600px
fit: true, // 100% fit in a container
closed: 'accordion', // Start closed if in accordion view
tabidentify: 'main', // The tab groups identifier
inactive_bg: '#F5F5F5', // background color for inactive 
activate: function() {
resizeChart();
}
});

$('#PerformanceTab').easyResponsiveTabs({
type: 'vertical',
width: 'auto',
fit: true,
tabidentify: 'performance', // The tab groups identifier
// activetab_bg: '#FFFFFF', // background color for active tabs in this group
// inactive_bg: '#F5F5F5', // background color for inactive tabs in this group
// active_border_color: '#C1C1C1', // border color for active tabs heads in this group
// active_content_border_color: '#5AB1D0', // border color for active tabs contect in this group so that it matches the tab head border
activate: function() {
resizeChart();
}  // Callback function, gets called if tab is switched
});
$('#system_name').text(data.system_name);
$('#cpu_model_name').text(data.cpu_info.cpu_name);
$('#logic_cpu_model_name').text(data.cpu_info.cpu_name);
$('#total_memory').text(kibiBytesToSize(data.memory.MemTotal));
$('#cpu_max_frequency').text((data.cpu_info.cpu_frequency / 1000).toFixed(2) + " GHz");
$('#cpu_frequency').text((data.cpu_info.cpu_frequency / 1000).toFixed(2) + " GHz");
$('#cpu_num').text(data.cpu_info.cpu_num);
$('#cpu_processor_num').text(numberFormatter(data.cpu_info.cpu_processor_num));
$('#cpu_core_num').text(data.cpu_info.cpu_core_num);
$('#cpu_cache_size').text(kibiBytesToSize(parseInt(data.cpu[0].cache_size.replace("KB", "").replace(" ", ""))));

window.cpuUsageChart = echarts.init(document.getElementById('cpu_usage'));
window.cpuUsageChartoption = {
title: {},
tooltip: {
trigger: 'axis'
},
xAxis: {
data: (function (){
var res = [];
var len = 1;
while (len <= numberOfRecords) {
res.push((new Date()).toLocaleTimeString().replace(/^\D*/,''));
len++;
}
return res;
})()
},
yAxis: {
type: 'value',
name: 'CPU利用率 %',
splitLine: {
show: true
},
max: 100,
min: 0
},
color: ['#117DBB'],
series: [
{
name:'CPU Usage',
type:'line',
areaStyle: {normal: {}},
data:(function (){
var res = [];
var len = 1;
while (len <= numberOfRecords) {
res.push(0);
len++;
}
return res;
})()
}
]
};

window.loadUsageChart = echarts.init(document.getElementById('load_usage'));
window.loadUsageChartoption = {
series : [
{
name: '1分钟平均负载',
type: 'gauge',
z: 3,
min: 0,
max: cpuNumber,
splitNumber: 10,
radius: '70%',
axisLine: {            // 坐标轴线
lineStyle: {       // 属性lineStyle控制线条样式
width: 10
}
},
axisTick: {            // 坐标轴小标记
length: 15,        // 属性length控制线长
lineStyle: {       // 属性lineStyle控制线条样式
color: 'auto'
}
},
splitLine: {           // 分隔线
length: 20,         // 属性length控制线长
lineStyle: {       // 属性lineStyle（详见lineStyle）控制线条样式
color: 'auto'
}
},
title : {
textStyle: {       // 其余属性默认使用全局文本样式，详见TEXTSTYLE
fontWeight: 'bolder',
fontSize: 20,
fontStyle: 'italic'
}
},
detail : {
textStyle: {       // 其余属性默认使用全局文本样式，详见TEXTSTYLE
fontWeight: 'bolder'
}
},
data:[{value: 0, name: '1分钟平均负载'}]
},
{
name: '5分钟平均负载',
type: 'gauge',
center: ['20%', '55%'],    // 默认全局居中
radius: '55%',
min:0,
max:cpuNumber,
endAngle:45,
splitNumber:10,
axisLine: {            // 坐标轴线
lineStyle: {       // 属性lineStyle控制线条样式
width: 8
}
},
axisTick: {            // 坐标轴小标记
length:12,        // 属性length控制线长
lineStyle: {       // 属性lineStyle控制线条样式
color: 'auto'
}
},
splitLine: {           // 分隔线
length:20,         // 属性length控制线长
lineStyle: {       // 属性lineStyle（详见lineStyle）控制线条样式
color: 'auto'
}
},
pointer: {
width:5
},
title: {
offsetCenter: [0, '-30%'],       // x, y，单位px
},
detail: {
textStyle: {       // 其余属性默认使用全局文本样式，详见TEXTSTYLE
fontWeight: 'bolder'
}
},
data:[{value: 0, name: '5分钟平均负载'}]
},
{
name: '15分钟平均负载',
type: 'gauge',
center: ['80%', '55%'],    // 默认全局居中
radius: '55%',
min: 0,
max: cpuNumber,
startAngle: 135,
endAngle: -45,
splitNumber: 10,
axisLine: {            // 坐标轴线
lineStyle: {       // 属性lineStyle控制线条样式
width: 8
}
},
axisTick: {            // 坐标轴小标记
length:12,        // 属性length控制线长
lineStyle: {       // 属性lineStyle控制线条样式
color: 'auto'
}
},
splitLine: {           // 分隔线
length:20,         // 属性length控制线长
lineStyle: {       // 属性lineStyle（详见lineStyle）控制线条样式
color: 'auto'
}
},
pointer: {
width:5
},
title: {
offsetCenter: [0, '-30%'],       // x, y，单位px
},
detail: {
textStyle: {       // 其余属性默认使用全局文本样式，详见TEXTSTYLE
fontWeight: 'bolder'
}
},
data:[{value: 0, name: '15分钟平均负载'}]
}
]
};

window.logicCpuUsageChart = [];
window.logicCpuUsageChartoption = [];
for (var i = 0; i < cpuNumber; i++) {
window.logicCpuUsageChart[i] = echarts.init(document.getElementById('logic_cpu_' + i + '_usage'));
window.logicCpuUsageChartoption[i] = cloneObject(window.cpuUsageChartoption);
logicCpuUsageChartoption[i].grid = {
show: true,
borderColor: '#117DBB',
borderWidth: 1,
left: 3,
top: 3,
right: 3,
bottom: 3
};
logicCpuUsageChartoption[i].xAxis.show = false;
logicCpuUsageChartoption[i].yAxis.axisLabel = {
show: false
};
logicCpuUsageChartoption[i].yAxis.name = 'CPU' + i + ' 利用率 %';
logicCpuUsageChartoption[i].series[0].name = 'CPU' + i + ' Usage';
}
window.memoryUsageChart = echarts.init(document.getElementById('memory_usage'));
window.memoryUsageChartoption = cloneObject(window.cpuUsageChartoption);
memoryUsageChartoption.yAxis.name = '内存使用量 MiB';
memoryUsageChartoption.color = ['#8B12AE'];
memoryUsageChartoption.series[0].name = 'Memory Usage';

window.connectionUsageChart = echarts.init(document.getElementById('connection_usage'));
window.connectionUsageChartoption = cloneObject(window.cpuUsageChartoption);
connectionUsageChartoption.yAxis.name = '连接数';
connectionUsageChartoption.color = [
'#F44336',
'#9C27B0',
'#673AB7',
'#2196F3',
'#03A9F4',
'#009688',
'#4CAF50',
'#CDDC39',
'#FFEB3B',
'#FF9800',
'#FF5722',
'#9E9E9E'
];
connectionUsageChartoption.yAxis.max = null;
connectionUsageChartoption.legend = {
data: [
'ESTABLISHED',
'SYN_SENT',
'SYN_RECV',
'FIN_WAIT1',
'FIN_WAIT2',
'TIME_WAIT',
'CLOSE',
'CLOSE_WAIT',
'LAST_ACK',
'LISTEN',
'CLOSING',
'UNKNOWN'
]
};
connectionUsageChartoption.series[0].name = 'ESTABLISHED';
connectionUsageChartoption.series[0].stack = 'AllConnection';
connectionUsageChartoption.series[1] = cloneObject(connectionUsageChartoption.series[0]);
connectionUsageChartoption.series[1].name = 'SYN_SENT';
connectionUsageChartoption.series[2] = cloneObject(connectionUsageChartoption.series[0]);
connectionUsageChartoption.series[2].name = 'SYN_RECV';
connectionUsageChartoption.series[3] = cloneObject(connectionUsageChartoption.series[0]);
connectionUsageChartoption.series[3].name = 'FIN_WAIT1';
connectionUsageChartoption.series[4] = cloneObject(connectionUsageChartoption.series[0]);
connectionUsageChartoption.series[4].name = 'FIN_WAIT2';
connectionUsageChartoption.series[5] = cloneObject(connectionUsageChartoption.series[0]);
connectionUsageChartoption.series[5].name = 'TIME_WAIT';
connectionUsageChartoption.series[6] = cloneObject(connectionUsageChartoption.series[0]);
connectionUsageChartoption.series[6].name = 'CLOSE';
connectionUsageChartoption.series[7] = cloneObject(connectionUsageChartoption.series[0]);
connectionUsageChartoption.series[7].name = 'CLOSE_WAIT';
connectionUsageChartoption.series[8] = cloneObject(connectionUsageChartoption.series[0]);
connectionUsageChartoption.series[8].name = 'LAST_ACK';
connectionUsageChartoption.series[9] = cloneObject(connectionUsageChartoption.series[0]);
connectionUsageChartoption.series[9].name = 'LISTEN';
connectionUsageChartoption.series[10] = cloneObject(connectionUsageChartoption.series[0]);
connectionUsageChartoption.series[10].name = 'CLOSING';
connectionUsageChartoption.series[11] = cloneObject(connectionUsageChartoption.series[0]);
connectionUsageChartoption.series[11].name = 'UNKNOWN';

window.diskUsageChart = [];
window.diskUsageChartoption = [];
window.diskSpeedChart = [];
window.diskSpeedChartoption = [];
for (var offset in data.disk) {
window.diskUsageChart[data.disk[offset]] = echarts.init(document.getElementById('disk_' + data.disk[offset] + '_usage'));
window.diskUsageChartoption[data.disk[offset]] = cloneObject(window.cpuUsageChartoption);
diskUsageChartoption[data.disk[offset]].yAxis.name = '活动时间 %';
diskUsageChartoption[data.disk[offset]].color = ['#4DA60C'];
diskUsageChartoption[data.disk[offset]].series[0].name = 'Disk Usage';

window.diskSpeedChart[data.disk[offset]] = echarts.init(document.getElementById('disk_' + data.disk[offset] + '_speed'));
window.diskSpeedChartoption[data.disk[offset]] = cloneObject(window.cpuUsageChartoption);
diskSpeedChartoption[data.disk[offset]].yAxis.name = '磁盘传输速率  read(+) / write(-) KiB/s';
diskSpeedChartoption[data.disk[offset]].yAxis.max = null;
diskSpeedChartoption[data.disk[offset]].yAxis.min = null;
diskSpeedChartoption[data.disk[offset]].color = ['#4DA60C'];
diskSpeedChartoption[data.disk[offset]].series[0].name = '磁盘读取速率';
diskSpeedChartoption[data.disk[offset]].series[1] = cloneObject(diskSpeedChartoption[data.disk[offset]].series[0]);
diskSpeedChartoption[data.disk[offset]].series[1].name = '磁盘写入速率';
}
window.networkUsageChart = [];
window.networkUsageChartoption = [];
for (var offset in data.network) {
window.networkUsageChart[data.network[offset]] = echarts.init(document.getElementById('network_' + data.network[offset] + '_usage'));
window.networkUsageChartoption[data.network[offset]] = cloneObject(window.cpuUsageChartoption);
networkUsageChartoption[data.network[offset]].yAxis.name = '吞吐量 out(+) / in(-) KiB/s';
networkUsageChartoption[data.network[offset]].yAxis.max = null;
networkUsageChartoption[data.network[offset]].yAxis.min = null;
networkUsageChartoption[data.network[offset]].color = ['#A74F01'];
networkUsageChartoption[data.network[offset]].series[0].name = '上行速率';
networkUsageChartoption[data.network[offset]].series[1] = cloneObject(networkUsageChartoption[data.network[offset]].series[0]);
networkUsageChartoption[data.network[offset]].series[0].name = '下行速率';
}

refreshChart();
}

function drawProcessTable(processData, formatData) {
// Process if ($("#cpu_usage").is(":visible")) {
$("#Process").empty();
processData = listSort(processData, window.processSortedBy, window.processOrder);
if (formatData) {
for (var key in processData) {
processData[key][2] = processData[key][2] + "%";
processData[key][3] = processData[key][3] + "%";
processData[key][4] = kibiBytesToSize(processData[key][4]);
processData[key][5] = kibiBytesToSize(processData[key][5]);
processData[key][6] = processData[key][6].replace("?", "").replace("tty", "终端");
processData[key][7] = processData[key][7].split("");
var tempState = processData[key][7];
var tempStateList = [];
var stateDict = {
"D": "正在等待磁盘",
"R": "正在运行",
"S": "处于休眠状态",
"T": "正在被跟踪或被停止",
"W": "进入内存交换",
"X": "进程已退出",
"Z": "僵进程", // 进程已终止, 但进程描述符存在
"<": "高优先级",
"N": "低优先级",
"L": "有些页面被锁在内存中",
"s": "主进程",
"l": "多线程进程",
"+": "前台进程"
};
for (var i = 0; i < tempState.length; i++) {
tempStateList.push(stateDict[tempState[i]]);
}
processData[key][7] = tempStateList.join("<br />");
}
}
processData.unshift([
"用户",
"进程ID",
"CPU",
"内存",
"虚拟内存",
"常驻内存",
"终端位置",
"状态",
"启动时间",
"使用的CPU时间",
"命令"
]);
$.jsontotable(processData, { id: '#Process', header: true });
$("th").each(function(key){
$(this).attr("data-code", key);
});
$("th").bind("click", function() {
var tempProcessSortedBy = $(this).attr("data-code");
if (tempProcessSortedBy == window.processSortedBy) {
window.processOrder = window.processOrder == "desc" ? "asc" : "desc";
} else {
window.processSortedBy = tempProcessSortedBy;
window.processOrder = "desc";
}
processData.shift();
$("th").unbind("click");
drawProcessTable(processData ,false);
});
$("th:eq(" + window.processSortedBy + ")").attr("class", "selected-col-" + window.processOrder);
}


function drawDiskFreeTable(diskFreeData) {
// Process if ($("#cpu_usage").is(":visible")) {
$("#DiskFree").empty();
for (var key in diskFreeData) {
diskFreeData[key][2] = kibiBytesToSize(diskFreeData[key][2]);
diskFreeData[key][3] = kibiBytesToSize(diskFreeData[key][3]);
diskFreeData[key][4] = kibiBytesToSize(diskFreeData[key][4]);
}
diskFreeData.unshift([
"文件系统",
"类型",
"容量",
"已用",
"可用",
"使用率",
"挂载点"
]);
$.jsontotable(diskFreeData, { id: '#DiskFree', header: true });
}

function drawChart(data) {
$("#cpu_usage_info").text(data.cpu_usage + "%");
$("#process_number").text(data.process_number);
$("#uptime").text(data.uptime);

$('#logic_cpu_usage_label').text(data.logic_cpu_usage.slice(0, Math.min(data.logic_cpu_usage.length, 4)).join('%   ') + '%' + (data.logic_cpu_usage.length > 4 ? '  ……' : ''));

$("#memory_usage_used").text(kibiBytesToSize(data.memory_usage_used));
$("#memory_usage_available").text(kibiBytesToSize(parseInt(data.memory_usage_total) - parseInt(data.memory_usage_used)));

$("#memory_usage_swap_used").text(kibiBytesToSize(data.memory_usage_swap_used));
$("#memory_usage_swap_free").text(kibiBytesToSize(data.memory_usage_swap_free));

$("#memory_submit").text(kibiBytesToSize(parseInt(data.memory_usage_used) + parseInt(data.memory_usage_swap_used)) + " / " + kibiBytesToSize(parseInt(data.memory_usage_total) + parseInt(data.memory_usage_swap_total)));
$("#memory_usage_cache").text(kibiBytesToSize(data.memory_usage_cache));

axisData = (new Date()).toLocaleTimeString().replace(/^\D*/,'');
// CPU
$("#cpu_usage_label").text(data.cpu_usage + "%");
cpuUsageChartoption.series[0].data.shift();
cpuUsageChartoption.series[0].data.push(data.cpu_usage);
cpuUsageChartoption.xAxis.data.shift();
cpuUsageChartoption.xAxis.data.push(axisData);
if ($("#cpu_usage").is(":visible")) {
cpuUsageChart.setOption(cpuUsageChartoption);
}
// Logic CPU
for (var i = 0; i < window.env.cpu.length; i++) {
logicCpuUsageChartoption[i].series[0].data.shift();
logicCpuUsageChartoption[i].series[0].data.push(data.logic_cpu_usage[i]);
logicCpuUsageChartoption[i].xAxis.data.shift();
logicCpuUsageChartoption[i].xAxis.data.push(axisData);
if ($("#logic_cpu_usage_container").is(":visible")) {
window.logicCpuUsageChart[i].setOption(logicCpuUsageChartoption[i]);
}
}
// Load
$("#load_usage_label").text(data.load[0]);
loadUsageChartoption.series[0].data[0].value = data.load[0];
loadUsageChartoption.series[1].data[0].value = data.load[1];
loadUsageChartoption.series[2].data[0].value = data.load[2];
if ($("#load_usage").is(":visible")) {
loadUsageChart.setOption(loadUsageChartoption, true);
}
// Memory
$("#memory_usage_label").text(kibiBytesToSize(data.memory_usage_used) + " / " + kibiBytesToSize(data.memory_usage_total) + " (" + Math.round(data.memory_usage_used * 100 / data.memory_usage_total) + "%)");
memoryUsageChartoption.yAxis.max = Math.round(data.memory_usage_total / 1024);
memoryUsageChartoption.series[0].data.shift();
memoryUsageChartoption.series[0].data.push(Math.round(data.memory_usage_used / 1024));
memoryUsageChartoption.xAxis.data.shift();
memoryUsageChartoption.xAxis.data.push(axisData);
if ($("#memory_usage").is(":visible")) {
memoryUsageChart.setOption(memoryUsageChartoption);
}
// Connection
connectionUsageChartoption.series[0].data.shift();
connectionUsageChartoption.series[0].data.push(data.connection.ESTABLISHED);
$('#connection_ESTABLISHED_usage_info').text(data.connection.ESTABLISHED);
connectionUsageChartoption.series[1].data.shift();
connectionUsageChartoption.series[1].data.push(data.connection.SYN_SENT);
$('#connection_SYN_SENT_usage_info').text(data.connection.SYN_SENT);
connectionUsageChartoption.series[2].data.shift();
connectionUsageChartoption.series[2].data.push(data.connection.SYN_RECV);
$('#connection_SYN_RECV_usage_info').text(data.connection.SYN_RECV);
connectionUsageChartoption.series[3].data.shift();
connectionUsageChartoption.series[3].data.push(data.connection.FIN_WAIT1);
$('#connection_FIN_WAIT1_usage_info').text(data.connection.FIN_WAIT1);
connectionUsageChartoption.series[4].data.shift();
connectionUsageChartoption.series[4].data.push(data.connection.FIN_WAIT2);
$('#connection_FIN_WAIT2_usage_info').text(data.connection.FIN_WAIT2);
connectionUsageChartoption.series[5].data.shift();
connectionUsageChartoption.series[5].data.push(data.connection.TIME_WAIT);
$('#connection_TIME_WAIT_usage_info').text(data.connection.TIME_WAIT);
connectionUsageChartoption.series[6].data.shift();
connectionUsageChartoption.series[6].data.push(data.connection.CLOSE);
$('#connection_CLOSE_usage_info').text(data.connection.CLOSE);
connectionUsageChartoption.series[7].data.shift();
connectionUsageChartoption.series[7].data.push(data.connection.CLOSE_WAIT);
$('#connection_CLOSE_WAIT_usage_info').text(data.connection.CLOSE_WAIT);
connectionUsageChartoption.series[8].data.shift();
connectionUsageChartoption.series[8].data.push(data.connection.LAST_ACK);
$('#connection_LAST_ACK_usage_info').text(data.connection.LAST_ACK);
connectionUsageChartoption.series[9].data.shift();
connectionUsageChartoption.series[9].data.push(data.connection.LISTEN);
$('#connection_LISTEN_usage_info').text(data.connection.LISTEN);
connectionUsageChartoption.series[10].data.shift();
connectionUsageChartoption.series[10].data.push(data.connection.CLOSING);
$('#connection_CLOSING_usage_info').text(data.connection.CLOSING);
connectionUsageChartoption.series[11].data.shift();
connectionUsageChartoption.series[11].data.push(data.connection.UNKNOWN);
$('#connection_UNKNOWN_usage_info').text(data.connection.UNKNOWN);
connectionUsageChartoption.xAxis.data.shift();
connectionUsageChartoption.xAxis.data.push(axisData);
if ($("#connection_usage").is(":visible")) {
connectionUsageChart.setOption(connectionUsageChartoption);
}
$('#connection_usage_label').text(
data.connection.ESTABLISHED + 
data.connection.SYN_SENT + 
data.connection.SYN_RECV + 
data.connection.FIN_WAIT1 + 
data.connection.FIN_WAIT2 + 
data.connection.TIME_WAIT + 
data.connection.CLOSE + 
data.connection.CLOSE_WAIT + 
data.connection.LAST_ACK + 
data.connection.LISTEN + 
data.connection.CLOSING + 
data.connection.UNKNOWN
);
// Disk
for (var offset in window.env.disk) {
// console.log(window.env.disk[offset]);
// console.log(data.disk[window.env.disk[offset]]);
// Disk Usage
var disk_usage_percent = Math.min((data.disk[window.env.disk[offset]].disk_read_active_time + data.disk[window.env.disk[offset]].disk_write_active_time) / 10, 100);
$("#disk_" + window.env.disk[offset] + "_usage_label").text(disk_usage_percent + "%");
$("#disk_" + window.env.disk[offset] + "_usage_info").text(disk_usage_percent + "%");
$("#disk_" + window.env.disk[offset] + "_read_active_time").text(data.disk[window.env.disk[offset]].disk_read_active_time + " 毫秒");
$("#disk_" + window.env.disk[offset] + "_write_active_time").text(data.disk[window.env.disk[offset]].disk_write_active_time + " 毫秒");
$("#disk_" + window.env.disk[offset] + "_read_speed").text(kibiBytesToSize(data.disk[window.env.disk[offset]].disk_read_speed) + " / 秒");
$("#disk_" + window.env.disk[offset] + "_write_speed").text(kibiBytesToSize(data.disk[window.env.disk[offset]].disk_write_speed) + " / 秒");
$("#disk_" + window.env.disk[offset] + "_read_kibibytes").text(kibiBytesToSize(data.disk[window.env.disk[offset]].disk_read_kibibytes));
$("#disk_" + window.env.disk[offset] + "_write_kibibytes").text(kibiBytesToSize(data.disk[window.env.disk[offset]].disk_write_kibibytes));
diskUsageChartoption[window.env.disk[offset]].series[0].data.shift();
diskUsageChartoption[window.env.disk[offset]].series[0].data.push(disk_usage_percent);
diskUsageChartoption[window.env.disk[offset]].xAxis.data.shift();
diskUsageChartoption[window.env.disk[offset]].xAxis.data.push(axisData);
if ($("#disk_" + window.env.disk[offset] + "_usage").is(":visible")) {
window.diskUsageChart[window.env.disk[offset]].setOption(diskUsageChartoption[window.env.disk[offset]]);
}
// console.log(window.diskUsageChart[window.env.disk[offset]].isDisposed);
// Disk Speed
diskSpeedChartoption[window.env.disk[offset]].series[0].data.shift();
diskSpeedChartoption[window.env.disk[offset]].series[0].data.push(data.disk[window.env.disk[offset]].disk_read_speed);
diskSpeedChartoption[window.env.disk[offset]].series[1].data.shift();
diskSpeedChartoption[window.env.disk[offset]].series[1].data.push(-data.disk[window.env.disk[offset]].disk_write_speed);
diskSpeedChartoption[window.env.disk[offset]].xAxis.data.shift();
diskSpeedChartoption[window.env.disk[offset]].xAxis.data.push(axisData);
if ($("#disk_" + window.env.disk[offset] + "_speed").is(":visible")) {
window.diskSpeedChart[window.env.disk[offset]].setOption(diskSpeedChartoption[window.env.disk[offset]]);
}
}
// Network
for (var eth in window.env.network) {
$("#network_" + window.env.network[eth] + "_usage_label").text("发送：" + kibiBytesToSize(data.network[window.env.network[eth]].transmit_speed / 1024) + "/s 接收：" + kibiBytesToSize(data.network[window.env.network[eth]].receive_speed / 1024) + "/s");
// networkUsageChartoption[window.env.network[eth]].yAxis.max = Math.max(data.network[window.env.network[eth]].transmit_speed, data.network[window.env.network[eth]].receive_speed / 1024);
$("#eth_" + window.env.network[eth] + "_receive_bytes").text(kibiBytesToSize(data.network[window.env.network[eth]].receive_bytes / 1024));
$("#eth_" + window.env.network[eth] + "_receive_packets").text(numberFormatter(data.network[window.env.network[eth]].receive_packets));
$("#eth_" + window.env.network[eth] + "_receive_speed").text(kibiBytesToSize(data.network[window.env.network[eth]].receive_speed / 1024) + " / 秒");
$("#eth_" + window.env.network[eth] + "_transmit_bytes").text(kibiBytesToSize(data.network[window.env.network[eth]].transmit_bytes / 1024));
$("#eth_" + window.env.network[eth] + "_transmit_packets").text(numberFormatter(data.network[window.env.network[eth]].transmit_packets));
$("#eth_" + window.env.network[eth] + "_transmit_speed").text(kibiBytesToSize(data.network[window.env.network[eth]].transmit_speed / 1024) + " / 秒");

networkUsageChartoption[window.env.network[eth]].series[0].data.shift();
networkUsageChartoption[window.env.network[eth]].series[0].data.push(-Math.round(data.network[window.env.network[eth]].receive_speed / 1024));
networkUsageChartoption[window.env.network[eth]].series[1].data.shift();
networkUsageChartoption[window.env.network[eth]].series[1].data.push(Math.round(data.network[window.env.network[eth]].transmit_speed / 1024));
networkUsageChartoption[window.env.network[eth]].xAxis.data.shift();
networkUsageChartoption[window.env.network[eth]].xAxis.data.push(axisData);
if ($("#network_" + window.env.network[eth] + "_usage").is(":visible")) {
window.networkUsageChart[window.env.network[eth]].setOption(networkUsageChartoption[window.env.network[eth]]);
}
}
// Process
if ($("#Process").is(":visible") || $("#Process").children().length === 0) {
drawProcessTable(data.process, true);
}
// DiskFree
if ($("#DiskFree").is(":visible") || $("#DiskFree").children().length === 0) {
drawDiskFreeTable(data.disk_free);
}
}
function refreshChart() {
$.ajax({
type: "POST",
url: "holy_lance.php?file=api.php",
data: {
password: password
},
dataType: "json",
success: function(data){
drawChart(data);
// Callback
setTimeout(function(){refreshChart();}, intervalTime);
},
error: function (data, e) {
// Callback
setTimeout(function(){refreshChart();}, intervalTime);
}
});
}

function diskTest() {
    $("#disk_read_512k").text('…');
    $("#disk_write_512k").text('…');
    $("#disk_read_4k").text('…');
    $("#disk_write_4k").text('…');
    $.ajax({
        type: "POST",
        url: "holy_lance.php?file=test_disk.php",
        data: {password: password},
        dataType: "json",
        success: function(data){
            $("#disk_read_512k").text(data.result.disk_read_512k);
            $("#disk_write_512k").text(data.result.disk_write_512k);
            $("#disk_read_4k").text(data.result.disk_read_4k);
            $("#disk_write_4k").text(data.result.disk_write_4k);
        }
    });
}

function pingTest(_this, ip, port) {
    port = (typeof port === 'undefined') ? 80 : port;
    _this.textContent="…";
    $.ajax({
        type: "POST",
        url: "holy_lance.php?file=test_ping.php",
        data: {password: password, ip: ip, port: port},
        dataType: "json",
        success: function(data){
            _this.textContent=data.result;
        }
    });
}

function pingPi(_this, accuracy) {
    _this.textContent="…";
    $.ajax({
        type: "POST",
        url: "holy_lance.php?file=test_pi.php",
        data: {password: password, accuracy:accuracy},
        dataType: "json",
        success: function(data){
            _this.textContent=data.time;
        }
    });
}

$(document).ready(function () {
    if (passwordRequired) {
        password = prompt("请输入Holy Lance的密码","");
        if (password !== null){
            //TODO 校验密码操作
        }else{
            alert("未输入密码");
        }
    }
if (passwordRequired === false || password !== ''){
var postData = password === '' ? {} : {password: password};
$.ajax({
type: "POST",
url: "holy_lance.php?file=init.php",
data: postData,
dataType: "json",
success: function(data){
if (data.status) {
                    $('body').show();
init(data);
                } else {
alert('密码错误');
}
}
});
    }
});<?php
exit();
endif;
?><?php
if (!empty($_GET["file"]) && $_GET["file"] == "static/js/easyResponsiveTabs.js"):
header("Content-type: text/javascript");?>// Easy Responsive Tabs Plugin
// Author: Samson.Onna <Email : samson3d@gmail.com> 

(function ($) {
$.fn.extend({
easyResponsiveTabs: function (options) {
//Set the default values, use comma to separate the settings, example:
var defaults = {
type: 'default', //default, vertical, accordion;
width: 'auto',
fit: true,
closed: false,
tabidentify: '',
activetab_bg: 'white',
inactive_bg: '#F5F5F5',
active_border_color: '#c1c1c1',
active_content_border_color: '#c1c1c1',
activate: function () {
}
}
//Variables
var options = $.extend(defaults, options);
var opt = options, jtype = opt.type, jfit = opt.fit, jwidth = opt.width, vtabs = 'vertical', accord = 'accordion';
var hash = window.location.hash;
var historyApi = !!(window.history && history.replaceState);

//Events
$(this).bind('tabactivate', function (e, currentTab) {
if (typeof options.activate === 'function') {
options.activate.call(currentTab, e)
}
});

//Main function
this.each(function () {
var $respTabs = $(this);
var $respTabsList = $respTabs.find('ul.resp-tabs-list.' + options.tabidentify);
var respTabsId = $respTabs.attr('id');
$respTabs.find('ul.resp-tabs-list.' + options.tabidentify + ' li').addClass('resp-tab-item').addClass(options.tabidentify);
$respTabs.css({
'display': 'block',
'width': jwidth
});

if (options.type == 'vertical')
$respTabsList.css('margin-top', '3px');

$respTabs.find('.resp-tabs-container.' + options.tabidentify).css('border-color', options.active_content_border_color);
$respTabs.find('.resp-tabs-container.' + options.tabidentify + ' > div').addClass('resp-tab-content').addClass(options.tabidentify);
jtab_options();
//Properties Function
function jtab_options() {
if (jtype == vtabs) {
$respTabs.addClass('resp-vtabs').addClass(options.tabidentify);
}
if (jfit == true) {
$respTabs.css({ width: '100%', margin: '0px' });
}
if (jtype == accord) {
$respTabs.addClass('resp-easy-accordion').addClass(options.tabidentify);
$respTabs.find('.resp-tabs-list').css('display', 'none');
}
}


//Assigning the h2 markup to accordion title
var $tabItemh2;
$respTabs.find('.resp-tab-content.' + options.tabidentify).before("<h2 class='resp-accordion " + options.tabidentify + "' role='tab'><span class='resp-arrow'></span></h2>");

$respTabs.find('.resp-tab-content.' + options.tabidentify).prev("h2").css({
'background-color': options.inactive_bg,
'border-color': options.active_border_color
});

var itemCount = 0;
$respTabs.find('.resp-accordion').each(function () {
$tabItemh2 = $(this);
var $tabItem = $respTabs.find('.resp-tab-item:eq(' + itemCount + ')');
var $accItem = $respTabs.find('.resp-accordion:eq(' + itemCount + ')');
$accItem.append($tabItem.html());
$accItem.data($tabItem.data());
$tabItemh2.attr('aria-controls', options.tabidentify + '_tab_item-' + (itemCount));
itemCount++;
});

//Assigning the 'aria-controls' to Tab items
var count = 0,
$tabContent;
$respTabs.find('.resp-tab-item').each(function () {
$tabItem = $(this);
$tabItem.attr('aria-controls', options.tabidentify + '_tab_item-' + (count));
$tabItem.attr('role', 'tab');
$tabItem.css({
'background-color': options.inactive_bg,
'border-color': 'none'
});

//Assigning the 'aria-labelledby' attr to tab-content
var tabcount = 0;
$respTabs.find('.resp-tab-content.' + options.tabidentify).each(function () {
$tabContent = $(this);
$tabContent.attr('aria-labelledby', options.tabidentify + '_tab_item-' + (tabcount)).css({
'border-color': options.active_border_color
});
tabcount++;
});
count++;
});

// Show correct content area
var tabNum = 0;
if (hash != '') {
var matches = hash.match(new RegExp(respTabsId + "([0-9]+)"));
if (matches !== null && matches.length === 2) {
tabNum = parseInt(matches[1], 10) - 1;
if (tabNum > count) {
tabNum = 0;
}
}
}

//Active correct tab
$($respTabs.find('.resp-tab-item.' + options.tabidentify)[tabNum]).addClass('resp-tab-active').css({
'background-color': options.activetab_bg,
'border-color': options.active_border_color
});

//keep closed if option = 'closed' or option is 'accordion' and the element is in accordion mode
if (options.closed !== true && !(options.closed === 'accordion' && !$respTabsList.is(':visible')) && !(options.closed === 'tabs' && $respTabsList.is(':visible'))) {
$($respTabs.find('.resp-accordion.' + options.tabidentify)[tabNum]).addClass('resp-tab-active').css({
'background-color': options.activetab_bg + ' !important',
'border-color': options.active_border_color,
'background': 'none'
});


$($respTabs.find('.resp-tab-content.' + options.tabidentify)[tabNum]).addClass('resp-tab-content-active').addClass(options.tabidentify).attr('style', 'display:block');
}
//assign proper classes for when tabs mode is activated before making a selection in accordion mode
else {
   // $($respTabs.find('.resp-tab-content.' + options.tabidentify)[tabNum]).addClass('resp-accordion-closed'); //removed resp-tab-content-active
}

//Tab Click action function
$respTabs.find("[role=tab]").each(function () {

var $currentTab = $(this);
$currentTab.click(function () {

var $currentTab = $(this);
var $tabAria = $currentTab.attr('aria-controls');

if ($currentTab.hasClass('resp-accordion') && $currentTab.hasClass('resp-tab-active')) {
$respTabs.find('.resp-tab-content-active.' + options.tabidentify).slideUp('', function () {
$(this).addClass('resp-accordion-closed');
});
$currentTab.removeClass('resp-tab-active').css({
'background-color': options.inactive_bg,
'border-color': 'none'
});
return false;
}
if (!$currentTab.hasClass('resp-tab-active') && $currentTab.hasClass('resp-accordion')) {
$respTabs.find('.resp-tab-active.' + options.tabidentify).removeClass('resp-tab-active').css({
'background-color': options.inactive_bg,
'border-color': 'none'
});
$respTabs.find('.resp-tab-content-active.' + options.tabidentify).slideUp().removeClass('resp-tab-content-active resp-accordion-closed');
$respTabs.find("[aria-controls=" + $tabAria + "]").addClass('resp-tab-active').css({
'background-color': options.activetab_bg,
'border-color': options.active_border_color
});

$respTabs.find('.resp-tab-content[aria-labelledby = ' + $tabAria + '].' + options.tabidentify).slideDown().addClass('resp-tab-content-active');
} else {
// console.log('here');
$respTabs.find('.resp-tab-active.' + options.tabidentify).removeClass('resp-tab-active').css({
'background-color': options.inactive_bg,
'border-color': 'none'
});


$respTabs.find('.resp-tab-content-active.' + options.tabidentify).removeAttr('style').removeClass('resp-tab-content-active').removeClass('resp-accordion-closed');


$respTabs.find("[aria-controls=" + $tabAria + "]").addClass('resp-tab-active').css({
'background-color': options.activetab_bg,
'border-color': options.active_border_color
});


$respTabs.find('.resp-tab-content[aria-labelledby = ' + $tabAria + '].' + options.tabidentify).addClass('resp-tab-content-active').attr('style', 'display:block');
}
//Trigger tab activation event
$currentTab.trigger('tabactivate', $currentTab);

//Update Browser History
if (historyApi) {
var currentHash = window.location.hash;
var tabAriaParts = $tabAria.split('tab_item-');
// var newHash = respTabsId + (parseInt($tabAria.substring(9), 10) + 1).toString();
var newHash = respTabsId + (parseInt(tabAriaParts[1], 10) + 1).toString();
if (currentHash != "") {
var re = new RegExp(respTabsId + "[0-9]+");
if (currentHash.match(re) != null) {
newHash = currentHash.replace(re, newHash);
}
else {
newHash = currentHash + "|" + newHash;
}
}
else {
newHash = '#' + newHash;
}

history.replaceState(null, null, newHash);
}
});

});

//Window resize function                   
$(window).resize(function () {
$respTabs.find('.resp-accordion-closed').removeAttr('style');
});
});
}
});
})(jQuery);
<?php
exit();
endif;
?><?php
if (!empty($_GET["file"]) && $_GET["file"] == "static/js/echarts.min.js"):
header("Content-type: text/javascript");?>!function(t,e){"function"==typeof define&&define.amd?define([],e):"object"==typeof module&&module.exports?module.exports=e():t.echarts=e()}(this,function(){var t,e;!function(){function i(t,e){if(!e)return t;if(0===t.indexOf(".")){var i=e.split("/"),n=t.split("/"),r=i.length-1,a=n.length,o=0,s=0;t:for(var l=0;a>l;l++)switch(n[l]){case"..":if(!(r>o))break t;o++,s++;break;case".":s++;break;default:break t}return i.length=r-o,n=n.slice(s),i.concat(n).join("/")}return t}function n(t){function e(e,o){if("string"==typeof e){var s=n[e];return s||(s=a(i(e,t)),n[e]=s),s}e instanceof Array&&(o=o||function(){},o.apply(this,r(e,o,t)))}var n={};return e}function r(e,n,r){for(var s=[],l=o[r],u=0,c=Math.min(e.length,n.length);c>u;u++){var h,f=i(e[u],r);switch(f){case"require":h=l&&l.require||t;break;case"exports":h=l.exports;break;case"module":h=l;break;default:h=a(f)}s.push(h)}return s}function a(t){var e=o[t];if(!e)throw new Error("No "+t);if(!e.defined){var i=e.factory,n=i.apply(this,r(e.deps||[],i,t));"undefined"!=typeof n&&(e.exports=n),e.defined=1}return e.exports}var o={};e=function(t,e,i){o[t]={id:t,deps:e,factory:i,defined:0,exports:{},require:n(t)}},t=n("")}();var i="lineTo",n="__dirty",r="undefined",a="moveTo",o="transform",s="ecModel",l="retrieve",u="applyTransform",c="getBoundingRect",h="stroke",f="textStyle",d="center",p="parsePercent",v="getShallow",m="getItemModel",g="ordinal",y="position",_="dimensions",x="middle",b="concat",w="createElement",M="getExtent",T="contain",S="inherits",C="function",P="isArray",A="replace",L="zlevel",z="target",k="splice",I="extend",D="isObject",O="update",E="create",R="getItemVisual",B="dataIndex",N="getData",F="indexOf",G="length",V="bottom",H="ignore",q="storage",W="canvasSupported",Z="getHeight",X="getWidth",U="getModel",j="animation",Y="resize",$="height",Q="string",K="prototype",J="toLowerCase",te="coordinateSystem",ee="removeAll",ie="zrender/core/util",ne="require";e("echarts/chart/line",[ne,ie,"../echarts","./line/LineSeries","./line/LineView","../visual/symbol","../layout/points","../processor/dataSample","../component/grid"],function(t){var e=t(ie),i=t("../echarts"),n=i.PRIORITY;t("./line/LineSeries"),t("./line/LineView"),i.registerVisual(e.curry(t("../visual/symbol"),"line","circle","line")),i.registerLayout(e.curry(t("../layout/points"),"line")),i.registerProcessor(n.PROCESSOR.STATISTIC,e.curry(t("../processor/dataSample"),"line")),t("../component/grid")}),e("echarts/chart/gauge",[ne,"./gauge/GaugeSeries","./gauge/GaugeView"],function(t){t("./gauge/GaugeSeries"),t("./gauge/GaugeView")}),e("echarts/component/grid",[ne,"../util/graphic",ie,"../echarts","../coord/cartesian/Grid","./axis"],function(t){var e=t("../util/graphic"),i=t(ie),n=t("../echarts");t("../coord/cartesian/Grid"),t("./axis"),n.extendComponentView({type:"grid",render:function(t){this.group[ee](),t.get("show")&&this.group.add(new e.Rect({shape:t[te].getRect(),style:i.defaults({fill:t.get("backgroundColor")},t.getItemStyle()),silent:!0,z2:-1}))}}),n.registerPreprocessor(function(t){t.xAxis&&t.yAxis&&!t.grid&&(t.grid={})})}),e("echarts/component/legend",[ne,"./legend/LegendModel","./legend/legendAction","./legend/LegendView","../echarts","./legend/legendFilter"],function(t){t("./legend/LegendModel"),t("./legend/legendAction"),t("./legend/LegendView");var e=t("../echarts");e.registerProcessor(t("./legend/legendFilter"))}),e("echarts/component/tooltip",[ne,"./tooltip/TooltipModel","./tooltip/TooltipView","../echarts"],function(t){t("./tooltip/TooltipModel"),t("./tooltip/TooltipView"),t("../echarts").registerAction({type:"showTip",event:"showTip",update:"none"},function(){}),t("../echarts").registerAction({type:"hideTip",event:"hideTip",update:"none"},function(){})}),e("echarts/echarts",[ne,"zrender/core/env","./model/Global","./ExtensionAPI","./CoordinateSystem","./model/OptionManager","./model/Component","./model/Series","./view/Component","./view/Chart","./util/graphic","./util/model","zrender",ie,"zrender/tool/color","zrender/mixin/Eventful","zrender/core/timsort","./visual/seriesColor","./preprocessor/backwardCompat","./loading/default","./data/List","./model/Model","./util/number","./util/format","zrender/core/matrix","zrender/core/vector"],function(t){function e(t){return function(e,i,n){e=e&&e[J](),le[K][t].call(this,e,i,n)}}function i(){le.call(this)}function n(t,e,n){function r(t,e){return t.prio-e.prio}n=n||{},typeof e===Q&&(e=Le[e]),this.id,this.group,this._dom=t,this._zr=ae.init(t,{renderer:n.renderer||"canvas",devicePixelRatio:n.devicePixelRatio,width:n.width,height:n[$]}),this._theme=oe.clone(e),this._chartsViews=[],this._chartsMap={},this._componentsViews=[],this._componentsMap={},this._api=new _(this),this._coordSysMgr=new x,le.call(this),this._messageCenter=new i,this._initEvents(),this[Y]=oe.bind(this[Y],this),this._pendingActions=[],ue(Ae,r),ue(Ce,r),this._zr[j].on("frame",this._onframe,this)}function r(t,e,i){var n,r=this._model,a=this._coordSysMgr.getCoordinateSystems();e=re.parseFinder(r,e);for(var o=0;o<a[G];o++){var s=a[o];if(s[t]&&null!=(n=s[t](r,e,i)))return n}}function a(t,e){var i=this._model;i&&i.eachComponent({mainType:"series",query:e},function(n){var r=this._chartsMap[n.__viewId];r&&r.__alive&&r[t](n,i,this._api,e)},this)}function o(t,e,i){var n=this._api;ce(this._componentsViews,function(r){var a=r.__model;r[t](a,e,n,i),v(a,r)},this),e.eachSeries(function(r){var a=this._chartsMap[r.__viewId];a[t](r,e,n,i),v(r,a),p(r,a)},this),d(this._zr,e)}function s(t,e){for(var i="component"===t,n=i?this._componentsViews:this._chartsViews,r=i?this._componentsMap:this._chartsMap,a=this._zr,o=0;o<n[G];o++)n[o].__alive=!1;e[i?"eachComponent":"eachSeries"](function(t,o){if(i){if("series"===t)return}else o=t;var s=o.id+"_"+o.type,l=r[s];if(!l){var u=w.parseClassType(o.type),c=i?T.getClass(u.main,u.sub):ee.getClass(u.sub);if(!c)return;l=new c,l.init(e,this._api),r[s]=l,n.push(l),a.add(l.group)}o.__viewId=s,l.__alive=!0,l.__id=s,l.__model=o},this);for(var o=0;o<n[G];){var s=n[o];s.__alive?o++:(a.remove(s.group),s.dispose(e,this._api),n[k](o,1),delete r[s.__id])}}function l(t,e){ce(Ce,function(i){i.func(t,e)})}function u(t){var e={};t.eachSeries(function(t){var i=t.get("stack"),n=t[N]();if(i&&"list"===n.type){var r=e[i];r&&(n.stackedOn=r),e[i]=n}})}function c(t,e){var i=this._api;ce(Ae,function(n){n.isLayout&&n.func(t,i,e)})}function h(t,e){var i=this._api;t.clearColorPalette(),t.eachSeries(function(t){t.clearColorPalette()}),ce(Ae,function(n){n.func(t,i,e)})}function f(t,e){var i=this._api;ce(this._componentsViews,function(n){var r=n.__model;n.render(r,t,i,e),v(r,n)},this),ce(this._chartsViews,function(t){t.__alive=!1},this),t.eachSeries(function(n){var r=this._chartsMap[n.__viewId];r.__alive=!0,r.render(n,t,i,e),r.group.silent=!!n.get("silent"),v(n,r),p(n,r)},this),d(this._zr,t),ce(this._chartsViews,function(e){e.__alive||e.remove(t,i)},this)}function d(t,e){var i=t[q],n=0;i.traverse(function(t){t.isGroup||n++}),n>e.get("hoverLayerThreshold")&&!g.node&&i.traverse(function(t){t.isGroup||(t.useHoverLayer=!0)})}function p(t,e){var i=0;e.group.traverse(function(t){"group"===t.type||t[H]||i++});var n=+t.get("progressive"),r=i>t.get("progressiveThreshold")&&n&&!g.node;r&&e.group.traverse(function(t){t.isGroup||(t.progressive=r?Math.floor(i++/n):-1,r&&t.stopAnimation(!0))});var a=t.get("blendMode")||null;e.group.traverse(function(t){t.isGroup||t.setStyle("blend",a)})}function v(t,e){var i=t.get("z"),n=t.get(L);e.group.traverse(function(t){"group"!==t.type&&(null!=i&&(t.z=i),null!=n&&(t[L]=n))})}function m(t){function e(t,e){for(var i=0;i<t[G];i++){var n=t[i];n[a]=e}}var i=0,n=1,r=2,a="__connectUpdateStatus";oe.each(Se,function(o,s){t._messageCenter.on(s,function(o){if(Ie[t.group]&&t[a]!==i){var s=t.makeActionFromEvent(o),l=[];oe.each(ke,function(e){e!==t&&e.group===t.group&&l.push(e)}),e(l,i),ce(l,function(t){t[a]!==n&&t.dispatchAction(s)}),e(l,r)}})})}var g=t("zrender/core/env"),y=t("./model/Global"),_=t("./ExtensionAPI"),x=t("./CoordinateSystem"),b=t("./model/OptionManager"),w=t("./model/Component"),M=t("./model/Series"),T=t("./view/Component"),ee=t("./view/Chart"),ne=t("./util/graphic"),re=t("./util/model"),ae=t("zrender"),oe=t(ie),se=t("zrender/tool/color"),le=t("zrender/mixin/Eventful"),ue=t("zrender/core/timsort"),ce=oe.each,he=1e3,fe=5e3,de=1e3,pe=2e3,ve=3e3,me=4e3,ge=5e3,ye="__flag_in_main_process",_e="_hasGradientOrPatternBg",xe="_optionUpdated";i[K].on=e("on"),i[K].off=e("off"),i[K].one=e("one"),oe.mixin(i,le);var be=n[K];be._onframe=function(){this[xe]&&(this[ye]=!0,we.prepareAndUpdate.call(this),this[ye]=!1,this[xe]=!1)},be.getDom=function(){return this._dom},be.getZr=function(){return this._zr},be.setOption=function(t,e,i){if(this[ye]=!0,!this._model||e){var n=new b(this._api),r=this._theme,a=this._model=new y(null,null,r,n);a.init(null,null,r,n)}this._model.setOption(t,Pe),i?this[xe]=!0:(we.prepareAndUpdate.call(this),this._zr.refreshImmediately(),this[xe]=!1),this[ye]=!1,this._flushPendingActions()},be.setTheme=function(){console.log("ECharts#setTheme() is DEPRECATED in ECharts 3.0")},be[U]=function(){return this._model},be.getOption=function(){return this._model&&this._model.getOption()},be[X]=function(){return this._zr[X]()},be[Z]=function(){return this._zr[Z]()},be.getRenderedCanvas=function(t){if(g[W]){t=t||{},t.pixelRatio=t.pixelRatio||1,t.backgroundColor=t.backgroundColor||this._model.get("backgroundColor");var e=this._zr,i=e[q].getDisplayList();return oe.each(i,function(t){t.stopAnimation(!0)}),e.painter.getRenderedCanvas(t)}},be.getDataURL=function(t){t=t||{};var e=t.excludeComponents,i=this._model,n=[],r=this;ce(e,function(t){i.eachComponent({mainType:t},function(t){var e=r._componentsMap[t.__viewId];e.group[H]||(n.push(e),e.group[H]=!0)})});var a=this.getRenderedCanvas(t).toDataURL("image/"+(t&&t.type||"png"));return ce(n,function(t){t.group[H]=!1}),a},be.getConnectedDataURL=function(t){if(g[W]){var e=this.group,i=Math.min,n=Math.max,r=1/0;if(Ie[e]){var a=r,o=r,s=-r,l=-r,u=[],c=t&&t.pixelRatio||1;oe.each(ke,function(r){if(r.group===e){var c=r.getRenderedCanvas(oe.clone(t)),h=r.getDom().getBoundingClientRect();a=i(h.left,a),o=i(h.top,o),s=n(h.right,s),l=n(h[V],l),u.push({dom:c,left:h.left,top:h.top})}}),a*=c,o*=c,s*=c,l*=c;var h=s-a,f=l-o,d=oe.createCanvas();d.width=h,d[$]=f;var p=ae.init(d);return ce(u,function(t){var e=new ne.Image({style:{x:t.left*c-a,y:t.top*c-o,image:t.dom}});p.add(e)}),p.refreshImmediately(),d.toDataURL("image/"+(t&&t.type||"png"))}return this.getDataURL(t)}},be.convertToPixel=oe.curry(r,"convertToPixel"),be.convertFromPixel=oe.curry(r,"convertFromPixel"),be.containPixel=function(t,e){var i,n=this._model;return t=re.parseFinder(n,t),oe.each(t,function(t,n){n[F]("Models")>=0&&oe.each(t,function(t){var r=t[te];if(r&&r.containPoint)i|=!!r.containPoint(e);else if("seriesModels"===n){var a=this._chartsMap[t.__viewId];a&&a.containPoint&&(i|=a.containPoint(e,t))}},this)},this),!!i},be.getVisual=function(t,e){var i=this._model;t=re.parseFinder(i,t,{defaultMainType:"series"});var n=t.seriesModel,r=n[N](),a=t.hasOwnProperty("dataIndexInside")?t.dataIndexInside:t.hasOwnProperty(B)?r.indexOfRawIndex(t[B]):null;return null!=a?r[R](a,e):r.getVisual(e)};var we={update:function(t){var e=this._model,i=this._api,n=this._coordSysMgr,r=this._zr;if(e){e.restoreData(),n[E](this._model,this._api),l.call(this,e,i),u.call(this,e),n[O](e,i),h.call(this,e,t),f.call(this,e,t);var a=e.get("backgroundColor")||"transparent",o=r.painter;if(o.isSingleCanvas&&o.isSingleCanvas())r.configLayer(0,{clearColor:a});else{if(!g[W]){var s=se.parse(a);a=se.stringify(s,"rgb"),0===s[3]&&(a="transparent")}a.colorStops||a.image?(r.configLayer(0,{clearColor:a}),this[_e]=!0,this._dom.style.background="transparent"):(this[_e]&&r.configLayer(0,{clearColor:null}),this[_e]=!1,this._dom.style.background=a)}}},updateView:function(t){var e=this._model;e&&(e.eachSeries(function(t){t[N]().clearAllVisual()}),h.call(this,e,t),o.call(this,"updateView",e,t))},updateVisual:function(t){var e=this._model;e&&(e.eachSeries(function(t){t[N]().clearAllVisual()}),h.call(this,e,t),o.call(this,"updateVisual",e,t))},updateLayout:function(t){var e=this._model;e&&(c.call(this,e,t),o.call(this,"updateLayout",e,t))},highlight:function(t){a.call(this,"highlight",t)},downplay:function(t){a.call(this,"downplay",t)},prepareAndUpdate:function(t){var e=this._model;s.call(this,"component",e),s.call(this,"chart",e),we[O].call(this,t)}};be[Y]=function(t){this[ye]=!0,this._zr[Y](t);var e=this._model&&this._model.resetOption("media");we[e?"prepareAndUpdate":O].call(this),this._loadingFX&&this._loadingFX[Y](),this[ye]=!1,this._flushPendingActions()},be.showLoading=function(t,e){if(oe[D](t)&&(e=t,t=""),t=t||"default",this.hideLoading(),ze[t]){var i=ze[t](this._api,e),n=this._zr;this._loadingFX=i,n.add(i)}},be.hideLoading=function(){this._loadingFX&&this._zr.remove(this._loadingFX),this._loadingFX=null},be.makeActionFromEvent=function(t){var e=oe[I]({},t);return e.type=Se[t.type],e},be.dispatchAction=function(t,e){var i=Te[t.type];if(i){var n=i.actionInfo,r=n[O]||O;if(this[ye])return void this._pendingActions.push(t);this[ye]=!0;var a=[t],o=!1;t.batch&&(o=!0,a=oe.map(t.batch,function(e){return e=oe.defaults(oe[I]({},e),t),e.batch=null,e}));for(var s,l=[],u="highlight"===t.type||"downplay"===t.type,c=0;c<a[G];c++){var h=a[c];s=i.action(h,this._model),s=s||oe[I]({},h),s.type=n.event||s.type,l.push(s),u&&we[r].call(this,h)}"none"===r||u||(this[xe]?(we.prepareAndUpdate.call(this,t),this[xe]=!1):we[r].call(this,t)),s=o?{type:n.event||t.type,batch:l}:l[0],this[ye]=!1,!e&&this._messageCenter.trigger(s.type,s),this._flushPendingActions()}},be._flushPendingActions=function(){for(var t=this._pendingActions;t[G];){var e=t.shift();this.dispatchAction(e)}},be.on=e("on"),be.off=e("off"),be.one=e("one");var Me=["click","dblclick","mouseover","mouseout","mousemove","mousedown","mouseup","globalout","contextmenu"];be._initEvents=function(){ce(Me,function(t){this._zr.on(t,function(e){var i,n=this[U](),r=e[z];if("globalout"===t)i={};else if(r&&null!=r[B]){var a=r.dataModel||n.getSeriesByIndex(r.seriesIndex);i=a&&a.getDataParams(r[B],r.dataType)||{}}else r&&r.eventData&&(i=oe[I]({},r.eventData));i&&(i.event=e,i.type=t,this.trigger(t,i))},this)},this),ce(Se,function(t,e){this._messageCenter.on(e,function(t){this.trigger(e,t)},this)},this)},be.isDisposed=function(){return this._disposed},be.clear=function(){this.setOption({series:[]},!0)},be.dispose=function(){if(!this._disposed){this._disposed=!0;var t=this._api,e=this._model;ce(this._componentsViews,function(i){i.dispose(e,t)}),ce(this._chartsViews,function(i){i.dispose(e,t)}),this._zr.dispose(),delete ke[this.id]}},oe.mixin(n,le);var Te=[],Se={},Ce=[],Pe=[],Ae=[],Le={},ze={},ke={},Ie={},De=new Date-0,Oe=new Date-0,Ee="_echarts_instance_",Re={version:"3.3.1",dependencies:{zrender:"3.2.1"}};Re.init=function(t,e,i){var r=new n(t,e,i);return r.id="ec_"+De++,ke[r.id]=r,t.setAttribute&&t.setAttribute(Ee,r.id),m(r),r},Re.connect=function(t){if(oe[P](t)){var e=t;t=null,oe.each(e,function(e){null!=e.group&&(t=e.group)}),t=t||"g_"+Oe++,oe.each(e,function(e){e.group=t})}return Ie[t]=!0,t},Re.disConnect=function(t){Ie[t]=!1},Re.dispose=function(t){oe.isDom(t)?t=Re.getInstanceByDom(t):typeof t===Q&&(t=ke[t]),t instanceof n&&!t.isDisposed()&&t.dispose()},Re.getInstanceByDom=function(t){var e=t.getAttribute(Ee);return ke[e]},Re.getInstanceById=function(t){return ke[t]},Re.registerTheme=function(t,e){Le[t]=e},Re.registerPreprocessor=function(t){Pe.push(t)},Re.registerProcessor=function(t,e){typeof t===C&&(e=t,t=he),Ce.push({prio:t,func:e})},Re.registerAction=function(t,e,i){typeof e===C&&(i=e,e="");var n=oe[D](t)?t.type:[t,t={event:e}][0];t.event=(t.event||n)[J](),e=t.event,Te[n]||(Te[n]={action:i,actionInfo:t}),Se[e]=n},Re.registerCoordinateSystem=function(t,e){x.register(t,e)},Re.registerLayout=function(t,e){typeof t===C&&(e=t,t=de),Ae.push({prio:t,func:e,isLayout:!0})},Re.registerVisual=function(t,e){typeof t===C&&(e=t,t=ve),Ae.push({prio:t,func:e})},Re.registerLoading=function(t,e){ze[t]=e};var Be=w.parseClassType;return Re.extendComponentModel=function(t,e){var i=w;if(e){var n=Be(e);i=w.getClass(n.main,n.sub,!0)}return i[I](t)},Re.extendComponentView=function(t,e){var i=T;if(e){var n=Be(e);i=T.getClass(n.main,n.sub,!0)}return i[I](t)},Re.extendSeriesModel=function(t,e){var i=M;if(e){e="series."+e[A]("series.","");var n=Be(e);i=M.getClass(n.main,n.sub,!0)}return i[I](t)},Re.extendChartView=function(t,e){var i=ee;if(e){e[A]("series.","");var n=Be(e);i=ee.getClass(n.main,!0)}return i[I](t)},Re.setCanvasCreator=function(t){oe.createCanvas=t},Re.registerVisual(pe,t("./visual/seriesColor")),Re.registerPreprocessor(t("./preprocessor/backwardCompat")),Re.registerLoading("default",t("./loading/default")),Re.registerAction({type:"highlight",event:"highlight",update:"highlight"},oe.noop),Re.registerAction({type:"downplay",event:"downplay",update:"downplay"},oe.noop),Re.List=t("./data/List"),Re.Model=t("./model/Model"),Re.graphic=t("./util/graphic"),Re.number=t("./util/number"),Re.format=t("./util/format"),Re.matrix=t("zrender/core/matrix"),Re.vector=t("zrender/core/vector"),Re.color=t("zrender/tool/color"),Re.util={},ce(["map","each","filter",F,S,"reduce","filter","bind","curry",P,"isString",D,"isFunction",I,"defaults"],function(t){Re.util[t]=oe[t]}),Re.PRIORITY={PROCESSOR:{FILTER:he,STATISTIC:fe},VISUAL:{LAYOUT:de,GLOBAL:pe,CHART:ve,COMPONENT:me,BRUSH:ge}},Re}),e("zrender/vml/vml",[ne,"./graphic","../zrender","./Painter"],function(t){t("./graphic"),t("../zrender").registerPainter("vml",t("./Painter"))}),e("echarts/scale/Time",[ne,ie,"../util/number","../util/format","./Interval"],function(t){var e=t(ie),i=t("../util/number"),n=t("../util/format"),r=t("./Interval"),a=r[K],o=Math.ceil,s=Math.floor,l=1e3,u=60*l,c=60*u,h=24*c,f=function(t,e,i,n){for(;n>i;){var r=i+n>>>1;t[r][2]<e?i=r+1:n=r}return i},d=r[I]({type:"time",getLabel:function(t){var e=this._stepLvl,i=new Date(t);return n.formatTime(e[0],i)},niceExtent:function(t,e,n){var r=this._extent;if(r[0]===r[1]&&(r[0]-=h,r[1]+=h),r[1]===-1/0&&1/0===r[0]){var a=new Date;r[1]=new Date(a.getFullYear(),a.getMonth(),a.getDate()),r[0]=r[1]-h}this.niceTicks(t);var l=this._interval;e||(r[0]=i.round(s(r[0]/l)*l)),n||(r[1]=i.round(o(r[1]/l)*l))},niceTicks:function(t){t=t||10;var e=this._extent,n=e[1]-e[0],r=n/t,a=p[G],l=f(p,r,0,a),u=p[Math.min(l,a-1)],c=u[2];if("year"===u[0]){var h=n/c,d=i.nice(h/t,!0);c*=d}var v=[o(e[0]/c)*c,s(e[1]/c)*c];this._stepLvl=u,this._interval=c,this._niceExtent=v},parse:function(t){return+i.parseDate(t)}});e.each([T,"normalize"],function(t){d[K][t]=function(e){return a[t].call(this,this.parse(e))}});var p=[["hh:mm:ss",1,l],["hh:mm:ss",5,5*l],["hh:mm:ss",10,10*l],["hh:mm:ss",15,15*l],["hh:mm:ss",30,30*l],["hh:mm\nMM-dd",1,u],["hh:mm\nMM-dd",5,5*u],["hh:mm\nMM-dd",10,10*u],["hh:mm\nMM-dd",15,15*u],["hh:mm\nMM-dd",30,30*u],["hh:mm\nMM-dd",1,c],["hh:mm\nMM-dd",2,2*c],["hh:mm\nMM-dd",6,6*c],["hh:mm\nMM-dd",12,12*c],["MM-dd\nyyyy",1,h],["week",7,7*h],["month",1,31*h],["quarter",3,380*h/4],["half-year",6,380*h/2],["year",1,380*h]];return d[E]=function(){return new d},d}),e("echarts/scale/Log",[ne,ie,"./Scale","../util/number","./Interval"],function(t){function e(t,e){return u(t,l(e))}var i=t(ie),n=t("./Scale"),r=t("../util/number"),a=t("./Interval"),o=n[K],s=a[K],l=r.getPrecisionSafe,u=r.round,c=Math.floor,h=Math.ceil,f=Math.pow,d=Math.log,p=n[I]({type:"log",base:10,$constructor:function(){n.apply(this,arguments),this._originalScale=new a},getTicks:function(){var t=this._originalScale,n=this._extent,a=t[M]();return i.map(s.getTicks.call(this),function(i){var o=r.round(f(this.base,i));return o=i===n[0]&&t.__fixMin?e(o,a[0]):o,o=i===n[1]&&t.__fixMax?e(o,a[1]):o},this)},getLabel:s.getLabel,scale:function(t){return t=o.scale.call(this,t),f(this.base,t)},setExtent:function(t,e){var i=this.base;t=d(t)/d(i),e=d(e)/d(i),s.setExtent.call(this,t,e)},getExtent:function(){var t=this.base,i=o[M].call(this);i[0]=f(t,i[0]),i[1]=f(t,i[1]);var n=this._originalScale,r=n[M]();return n.__fixMin&&(i[0]=e(i[0],r[0])),n.__fixMax&&(i[1]=e(i[1],r[1])),i},unionExtent:function(t){this._originalScale.unionExtent(t);var e=this.base;t[0]=d(t[0])/d(e),t[1]=d(t[1])/d(e),o.unionExtent.call(this,t)},niceTicks:function(t){t=t||10;var e=this._extent,i=e[1]-e[0];if(!(1/0===i||0>=i)){var n=r.quantity(i),a=t/i*n;for(.5>=a&&(n*=10);!isNaN(n)&&Math.abs(n)<1&&Math.abs(n)>0;)n*=10;var o=[r.round(h(e[0]/n)*n),r.round(c(e[1]/n)*n)];this._interval=n,this._niceExtent=o}},niceExtent:function(t,e,i){s.niceExtent.call(this,t,e,i);var n=this._originalScale;n.__fixMin=e,n.__fixMax=i}});return i.each([T,"normalize"],function(t){p[K][t]=function(e){return e=d(e)/d(this.base),o[t].call(this,e)}}),p[E]=function(){return new p},p}),e(ie,[ne],function(){function t(e){if("object"==typeof e&&null!==e){var i=e;if(e instanceof Array){i=[];for(var n=0,r=e[G];r>n;n++)i[n]=t(e[n])}else if(!T(e)&&!S(e)){i={};for(var a in e)e.hasOwnProperty(a)&&(i[a]=t(e[a]))}return i}return e}function e(i,n,r){if(!M(n)||!M(i))return r?t(n):i;for(var a in n)if(n.hasOwnProperty(a)){var o=i[a],s=n[a];!M(s)||!M(o)||y(s)||y(o)||S(s)||S(o)||T(s)||T(o)?!r&&a in i||(i[a]=t(n[a],!0)):e(o,s,r)}return i}function i(t,i){for(var n=t[0],r=1,a=t[G];a>r;r++)n=e(n,t[r],i);return n}function n(t,e){for(var i in e)e.hasOwnProperty(i)&&(t[i]=e[i]);return t}function r(t,e,i){for(var n in e)e.hasOwnProperty(n)&&(i?null!=e[n]:null==t[n])&&(t[n]=e[n]);return t}function a(){return document[w]("canvas")}function o(){return z||(z=V.createCanvas().getContext("2d")),z}function s(t,e){if(t){if(t[F])return t[F](e);for(var i=0,n=t[G];n>i;i++)if(t[i]===e)return i}return-1}function l(t,e){function i(){}var n=t[K];i[K]=e[K],t[K]=new i;for(var r in n)t[K][r]=n[r];t[K].constructor=t,t.superClass=e}function u(t,e,i){t=K in t?t[K]:t,e=K in e?e[K]:e,r(t,e,i)}function c(t){return t?typeof t==Q?!1:"number"==typeof t[G]:void 0}function h(t,e,i){if(t&&e)if(t.forEach&&t.forEach===O)t.forEach(e,i);else if(t[G]===+t[G])for(var n=0,r=t[G];r>n;n++)e.call(i,t[n],n,t);else for(var a in t)t.hasOwnProperty(a)&&e.call(i,t[a],a,t)}function f(t,e,i){if(t&&e){if(t.map&&t.map===B)return t.map(e,i);for(var n=[],r=0,a=t[G];a>r;r++)n.push(e.call(i,t[r],r,t));return n}}function d(t,e,i,n){if(t&&e){if(t.reduce&&t.reduce===N)return t.reduce(e,i,n);for(var r=0,a=t[G];a>r;r++)i=e.call(n,i,t[r],r,t);return i}}function p(t,e,i){if(t&&e){if(t.filter&&t.filter===E)return t.filter(e,i);for(var n=[],r=0,a=t[G];a>r;r++)e.call(i,t[r],r,t)&&n.push(t[r]);return n}}function v(t,e,i){if(t&&e)for(var n=0,r=t[G];r>n;n++)if(e.call(i,t[n],n,t))return t[n]}function m(t,e){var i=R.call(arguments,2);return function(){return t.apply(e,i[b](R.call(arguments)))}}function g(t){var e=R.call(arguments,1);return function(){return t.apply(this,e[b](R.call(arguments)))}}function y(t){return"[object Array]"===I.call(t)}function _(t){return typeof t===C}function x(t){return"[object String]"===I.call(t)}function M(t){var e=typeof t;return e===C||!!t&&"object"==e}function T(t){return!!k[I.call(t)]}function S(t){return t&&1===t.nodeType&&typeof t.nodeName==Q}function P(){for(var t=0,e=arguments[G];e>t;t++)if(null!=arguments[t])return arguments[t]}function A(){return Function.call.apply(R,arguments)}function L(t,e){if(!t)throw new Error(e)}var z,k={"[object Function]":1,"[object RegExp]":1,"[object Date]":1,"[object Error]":1,"[object CanvasGradient]":1,"[object CanvasPattern]":1,"[object Image]":1},I=Object[K].toString,D=Array[K],O=D.forEach,E=D.filter,R=D.slice,B=D.map,N=D.reduce,V={inherits:l,mixin:u,clone:t,merge:e,mergeAll:i,extend:n,defaults:r,getContext:o,createCanvas:a,indexOf:s,slice:A,find:v,isArrayLike:c,each:h,map:f,reduce:d,filter:p,bind:m,curry:g,isArray:y,isString:x,isObject:M,isFunction:_,isBuildInObject:T,isDom:S,retrieve:P,assert:L,noop:function(){}};return V}),e("echarts/chart/line/LineSeries",[ne,"../helper/createListFromArray","../../model/Series"],function(t){var e=t("../helper/createListFromArray"),i=t("../../model/Series");return i[I]({type:"series.line",dependencies:["grid","polar"],getInitialData:function(t,i){return e(t.data,this,i)},defaultOption:{zlevel:0,z:2,coordinateSystem:"cartesian2d",legendHoverLink:!0,hoverAnimation:!0,clipOverflow:!0,label:{normal:{position:"top"}},lineStyle:{normal:{width:2,type:"solid"}},step:!1,smooth:!1,smoothMonotone:null,symbol:"emptyCircle",symbolSize:4,symbolRotate:null,showSymbol:!0,showAllSymbol:!1,connectNulls:!1,sampling:"none",animationEasing:"linear",progressive:0,hoverLayerThreshold:1/0}})}),e("echarts/chart/line/LineView",[ne,ie,"../helper/SymbolDraw","../helper/Symbol","./lineAnimationDiff","../../util/graphic","../../util/model","./poly","../../view/Chart"],function(t){function e(t,e){if(t[G]===e[G]){for(var i=0;i<t[G];i++){var n=t[i],r=e[i];if(n[0]!==r[0]||n[1]!==r[1])return}return!0}}function i(t){return"number"==typeof t?t:t?.3:0}function n(t){var e=t.getGlobalExtent();if(t.onBand){var i=t.getBandWidth()/2-1,n=e[1]>e[0]?1:-1;e[0]+=n*i,e[1]-=n*i}return e}function r(t){return t>=0?1:-1}function a(t,e){var i=t.getBaseAxis(),n=t.getOtherAxis(i),a=i.onZero?0:n.scale[M]()[0],o=n.dim,s="x"===o||"radius"===o?1:0;return e.mapArray([o],function(n,l){for(var u,c=e.stackedOn;c&&r(c.get(o,l))===r(n);){u=c;break}var h=[];return h[s]=e.get(i.dim,l),h[1-s]=u?u.get(o,l,!0):a,t.dataToPoint(h)},!0)}function o(t,e,i){var r=n(t.getAxis("x")),a=n(t.getAxis("y")),o=t.getBaseAxis().isHorizontal(),s=Math.min(r[0],r[1]),l=Math.min(a[0],a[1]),u=Math.max(r[0],r[1])-s,c=Math.max(a[0],a[1])-l,h=i.get("lineStyle.normal.width")||2,f=i.get("clipOverflow")?h/2:Math.max(u,c);o?(l-=f,c+=2*f):(s-=f,u+=2*f);var d=new m.Rect({shape:{x:s,y:l,width:u,height:c}});return e&&(d.shape[o?"width":$]=0,m.initProps(d,{shape:{width:u,height:c}},i)),d}function s(t,e,i){var n=t.getAngleAxis(),r=t.getRadiusAxis(),a=r[M](),o=n[M](),s=Math.PI/180,l=new m.Sector({shape:{cx:t.cx,cy:t.cy,r0:a[0],r:a[1],startAngle:-o[0]*s,endAngle:-o[1]*s,clockwise:n.inverse}});return e&&(l.shape.endAngle=-o[0]*s,m.initProps(l,{shape:{endAngle:-o[1]*s}},i)),l}function l(t,e,i){return"polar"===t.type?s(t,e,i):o(t,e,i)}function u(t,e,i){for(var n=e.getBaseAxis(),r="x"===n.dim||"radius"===n.dim?0:1,a=[],o=0;o<t[G]-1;o++){var s=t[o+1],l=t[o];a.push(l);var u=[];switch(i){case"end":u[r]=s[r],u[1-r]=l[1-r],a.push(u);break;case x:var c=(l[r]+s[r])/2,h=[];u[r]=h[r]=c,u[1-r]=l[1-r],h[1-r]=s[1-r],a.push(u),a.push(h);break;default:u[r]=l[r],u[1-r]=s[1-r],a.push(u)}}return t[o]&&a.push(t[o]),a}function c(t,e){return Math.max(Math.min(t,e[1]),e[0])}function h(t,e){var i=t.getVisual("visualMeta");if(i&&i[G]&&t.count()){for(var n,r=i[G]-1;r>=0;r--)if(i[r].dimension<2){n=i[r];break}if(n&&"cartesian2d"===e.type){var a=n.dimension,o=t[_][a],s=t.getDataExtent(o),l=n.stops,u=[];l[0].interval&&l.sort(function(t,e){return t.interval[0]-e.interval[0]});var h=l[0],f=l[l[G]-1],d=h.interval?c(h.interval[0],s):h.value,p=f.interval?c(f.interval[1],s):f.value,v=p-d;if(0===v)return t[R](0,"color");for(var r=0;r<l[G];r++)if(l[r].interval){if(l[r].interval[1]===l[r].interval[0])continue;u.push({offset:(c(l[r].interval[0],s)-d)/v,color:l[r].color},{offset:(c(l[r].interval[1],s)-d)/v,color:l[r].color})}else u.push({offset:(l[r].value-d)/v,color:l[r].color});var g=new m.LinearGradient(0,0,0,0,u,!0),y=e.getAxis(o),x=y.toGlobalCoord(y.dataToCoord(d)),b=y.toGlobalCoord(y.dataToCoord(p));return g[o]=x,g[o+"2"]=b,g}}}var f=t(ie),d=t("../helper/SymbolDraw"),p=t("../helper/Symbol"),v=t("./lineAnimationDiff"),m=t("../../util/graphic"),b=t("../../util/model"),w=t("./poly"),T=t("../../view/Chart");return T[I]({type:"line",init:function(){var t=new m.Group,e=new d;this.group.add(e.group),this._symbolDraw=e,this._lineGroup=t},render:function(t,n,r){var o=t[te],s=this.group,c=t[N](),d=t[U]("lineStyle.normal"),p=t[U]("areaStyle.normal"),v=c.mapArray(c.getItemLayout,!0),m="polar"===o.type,g=this._coordSys,y=this._symbolDraw,_=this._polyline,x=this._polygon,b=this._lineGroup,w=t.get(j),M=!p.isEmpty(),T=a(o,c),S=t.get("showSymbol"),C=S&&!m&&!t.get("showAllSymbol")&&this._getSymbolIgnoreFunc(c,o),P=this._data;P&&P.eachItemGraphicEl(function(t,e){t.__temp&&(s.remove(t),P.setItemGraphicEl(e,null))}),S||y.remove(),s.add(b);var A=!m&&t.get("step");_&&g.type===o.type&&A===this._step?(M&&!x?x=this._newPolygon(v,T,o,w):x&&!M&&(b.remove(x),x=this._polygon=null),b.setClipPath(l(o,!1,t)),S&&y.updateData(c,C),c.eachItemGraphicEl(function(t){t.stopAnimation(!0)}),e(this._stackedOnPoints,T)&&e(this._points,v)||(w?this._updateAnimation(c,T,o,r,A):(A&&(v=u(v,o,A),T=u(T,o,A)),_.setShape({points:v}),x&&x.setShape({points:v,stackedOnPoints:T})))):(S&&y.updateData(c,C),A&&(v=u(v,o,A),T=u(T,o,A)),_=this._newPolyline(v,o,w),M&&(x=this._newPolygon(v,T,o,w)),b.setClipPath(l(o,!0,t)));var L=h(c,o)||c.getVisual("color");_.useStyle(f.defaults(d.getLineStyle(),{fill:"none",stroke:L,lineJoin:"bevel"}));var z=t.get("smooth");if(z=i(t.get("smooth")),_.setShape({smooth:z,smoothMonotone:t.get("smoothMonotone"),connectNulls:t.get("connectNulls")}),x){var k=c.stackedOn,I=0;if(x.useStyle(f.defaults(p.getAreaStyle(),{fill:L,opacity:.7,lineJoin:"bevel"})),k){var D=k.hostModel;I=i(D.get("smooth"))}x.setShape({smooth:z,stackedOnSmooth:I,smoothMonotone:t.get("smoothMonotone"),connectNulls:t.get("connectNulls")})}this._data=c,this._coordSys=o,this._stackedOnPoints=T,this._points=v,this._step=A},dispose:function(){},highlight:function(t,e,i,n){var r=t[N](),a=b.queryDataIndex(r,n);if(!(a instanceof Array)&&null!=a&&a>=0){var o=r.getItemGraphicEl(a);if(!o){var s=r.getItemLayout(a);o=new p(r,a),o[y]=s,o.setZ(t.get(L),t.get("z")),o[H]=isNaN(s[0])||isNaN(s[1]),o.__temp=!0,r.setItemGraphicEl(a,o),o.stopSymbolAnimation(!0),this.group.add(o)}o.highlight()}else T[K].highlight.call(this,t,e,i,n)},downplay:function(t,e,i,n){var r=t[N](),a=b.queryDataIndex(r,n);if(null!=a&&a>=0){var o=r.getItemGraphicEl(a);o&&(o.__temp?(r.setItemGraphicEl(a,null),this.group.remove(o)):o.downplay())}else T[K].downplay.call(this,t,e,i,n)},_newPolyline:function(t){var e=this._polyline;return e&&this._lineGroup.remove(e),e=new w.Polyline({shape:{points:t},silent:!0,z2:10}),this._lineGroup.add(e),this._polyline=e,e},_newPolygon:function(t,e){var i=this._polygon;return i&&this._lineGroup.remove(i),i=new w.Polygon({shape:{points:t,stackedOnPoints:e},silent:!0}),this._lineGroup.add(i),this._polygon=i,i},_getSymbolIgnoreFunc:function(t,e){var i=e.getAxesByScale(g)[0];return i&&i.isLabelIgnored?f.bind(i.isLabelIgnored,i):void 0},_updateAnimation:function(t,e,i,n,r){var a=this._polyline,o=this._polygon,s=t.hostModel,l=v(this._data,t,this._stackedOnPoints,e,this._coordSys,i),c=l.current,h=l.stackedOnCurrent,f=l.next,d=l.stackedOnNext;r&&(c=u(l.current,i,r),h=u(l.stackedOnCurrent,i,r),f=u(l.next,i,r),d=u(l.stackedOnNext,i,r)),a.shape.__points=l.current,a.shape.points=c,m.updateProps(a,{shape:{points:f}},s),o&&(o.setShape({points:c,stackedOnPoints:h}),m.updateProps(o,{shape:{points:f,stackedOnPoints:d}},s));for(var p=[],g=l.status,_=0;_<g[G];_++){var x=g[_].cmd;if("="===x){var b=t.getItemGraphicEl(g[_].idx1);b&&p.push({el:b,ptIdx:_})}}a.animators&&a.animators[G]&&a.animators[0].during(function(){for(var t=0;t<p[G];t++){var e=p[t].el;e.attr(y,a.shape.__points[p[t].ptIdx])}})},remove:function(){var t=this.group,e=this._data;this._lineGroup[ee](),this._symbolDraw.remove(!0),e&&e.eachItemGraphicEl(function(i,n){i.__temp&&(t.remove(i),e.setItemGraphicEl(n,null))}),this._polyline=this._polygon=this._coordSys=this._points=this._stackedOnPoints=this._data=null}})}),e("echarts/visual/symbol",[ne],function(){return function(t,e,i,n){n.eachRawSeriesByType(t,function(t){var r=t[N](),a=t.get("symbol")||e,o=t.get("symbolSize");r.setVisual({legendSymbol:i||a,symbol:a,symbolSize:o}),n.isSeriesFiltered(t)||(typeof o===C&&r.each(function(e){var i=t.getRawValue(e),n=t.getDataParams(e);r.setItemVisual(e,"symbolSize",o(i,n))}),r.each(function(t){var e=r[m](t),i=e[v]("symbol",!0),n=e[v]("symbolSize",!0);
null!=i&&r.setItemVisual(t,"symbol",i),null!=n&&r.setItemVisual(t,"symbolSize",n)}))})}}),e("echarts/layout/points",[ne],function(){return function(t,e){e.eachSeriesByType(t,function(t){var e=t[N](),i=t[te];if(i){var n=i[_];"singleAxis"===i.type?e.each(n[0],function(t,n){e.setItemLayout(n,isNaN(t)?[0/0,0/0]:i.dataToPoint(t))}):e.each(n,function(t,n,r){e.setItemLayout(r,isNaN(t)||isNaN(n)?[0/0,0/0]:i.dataToPoint([t,n]))},!0)}})}}),e("echarts/processor/dataSample",[],function(){var t={average:function(t){for(var e=0,i=0,n=0;n<t[G];n++)isNaN(t[n])||(e+=t[n],i++);return 0===i?0/0:e/i},sum:function(t){for(var e=0,i=0;i<t[G];i++)e+=t[i]||0;return e},max:function(t){for(var e=-1/0,i=0;i<t[G];i++)t[i]>e&&(e=t[i]);return e},min:function(t){for(var e=1/0,i=0;i<t[G];i++)t[i]<e&&(e=t[i]);return e},nearest:function(t){return t[0]}},e=function(t){return Math.round(t[G]/2)};return function(i,n){n.eachSeriesByType(i,function(i){var n=i[N](),r=i.get("sampling"),a=i[te];if("cartesian2d"===a.type&&r){var o=a.getBaseAxis(),s=a.getOtherAxis(o),l=o[M](),u=l[1]-l[0],c=Math.round(n.count()/u);if(c>1){var h;typeof r===Q?h=t[r]:typeof r===C&&(h=r),h&&(n=n.downSample(s.dim,1/c,h,e),i.setData(n))}}},this)}}),e("echarts/chart/gauge/GaugeSeries",[ne,"../../data/List","../../model/Series",ie],function(t){var e=t("../../data/List"),i=t("../../model/Series"),n=t(ie),r=i[I]({type:"series.gauge",getInitialData:function(t){var i=new e(["value"],this),r=t.data||[];return n[P](r)||(r=[r]),i.initData(r),i},defaultOption:{zlevel:0,z:2,center:["50%","50%"],legendHoverLink:!0,radius:"75%",startAngle:225,endAngle:-45,clockwise:!0,min:0,max:100,splitNumber:10,axisLine:{show:!0,lineStyle:{color:[[.2,"#91c7ae"],[.8,"#63869e"],[1,"#c23531"]],width:30}},splitLine:{show:!0,length:30,lineStyle:{color:"#eee",width:2,type:"solid"}},axisTick:{show:!0,splitNumber:5,length:8,lineStyle:{color:"#eee",width:1,type:"solid"}},axisLabel:{show:!0,distance:5,textStyle:{color:"auto"}},pointer:{show:!0,length:"80%",width:8},itemStyle:{normal:{color:"auto"}},title:{show:!0,offsetCenter:[0,"-40%"],textStyle:{color:"#333",fontSize:15}},detail:{show:!0,backgroundColor:"rgba(0,0,0,0)",borderWidth:0,borderColor:"#ccc",width:100,height:40,offsetCenter:[0,"40%"],textStyle:{color:"auto",fontSize:30}}}});return r}),e("echarts/chart/gauge/GaugeView",[ne,"./PointerPath","../../util/graphic","../../util/number","../../view/Chart"],function(t){function e(t,e){var i=t.get(d),n=e[X](),r=e[Z](),a=Math.min(n,r),s=o(i[0],e[X]()),l=o(i[1],e[Z]()),u=o(t.get("radius"),a/2);return{cx:s,cy:l,r:u}}function i(t,e){return e&&(typeof e===Q?t=e[A]("{value}",t):typeof e===C&&(t=e(t))),t}var n=t("./PointerPath"),r=t("../../util/graphic"),a=t("../../util/number"),o=a[p],s=2*Math.PI,l=t("../../view/Chart")[I]({type:"gauge",render:function(t,i,n){this.group[ee]();var r=t.get("axisLine.lineStyle.color"),a=e(t,n);this._renderMain(t,i,n,r,a)},dispose:function(){},_renderMain:function(t,e,i,n,a){for(var o=this.group,l=t[U]("axisLine"),u=l[U]("lineStyle"),c=t.get("clockwise"),h=-t.get("startAngle")/180*Math.PI,f=-t.get("endAngle")/180*Math.PI,d=(f-h)%s,p=h,v=u.get("width"),m=0;m<n[G];m++){var g=Math.min(Math.max(n[m][0],0),1),f=h+d*g,y=new r.Sector({shape:{startAngle:p,endAngle:f,cx:a.cx,cy:a.cy,clockwise:c,r0:a.r-v,r:a.r},silent:!0});y.setStyle({fill:n[m][1]}),y.setStyle(u.getLineStyle(["color","borderWidth","borderColor"])),o.add(y),p=f}var _=function(t){if(0>=t)return n[0][1];for(var e=0;e<n[G];e++)if(n[e][0]>=t&&(0===e?0:n[e-1][0])<t)return n[e][1];return n[e-1][1]};if(!c){var x=h;h=f,f=x}this._renderTicks(t,e,i,_,a,h,f,c),this._renderPointer(t,e,i,_,a,h,f,c),this._renderTitle(t,e,i,_,a),this._renderDetail(t,e,i,_,a)},_renderTicks:function(t,e,n,s,l,u,c){for(var p=this.group,v=l.cx,m=l.cy,g=l.r,y=t.get("min"),_=t.get("max"),b=t[U]("splitLine"),w=t[U]("axisTick"),M=t[U]("axisLabel"),T=t.get("splitNumber"),S=w.get("splitNumber"),C=o(b.get(G),g),P=o(w.get(G),g),A=u,L=(c-u)/T,z=L/S,k=b[U]("lineStyle").getLineStyle(),I=w[U]("lineStyle").getLineStyle(),D=M[U](f),O=0;T>=O;O++){var E=Math.cos(A),R=Math.sin(A);if(b.get("show")){var B=new r.Line({shape:{x1:E*g+v,y1:R*g+m,x2:E*(g-C)+v,y2:R*(g-C)+m},style:k,silent:!0});"auto"===k[h]&&B.setStyle({stroke:s(O/T)}),p.add(B)}if(M.get("show")){var N=i(a.round(O/T*(_-y)+y),M.get("formatter")),F=M.get("distance"),H=new r.Text({style:{text:N,x:E*(g-C-F)+v,y:R*(g-C-F)+m,fill:D.getTextColor(),textFont:D.getFont(),textVerticalAlign:-.4>R?"top":R>.4?V:x,textAlign:-.4>E?"left":E>.4?"right":d},silent:!0});"auto"===H.style.fill&&H.setStyle({fill:s(O/T)}),p.add(H)}if(w.get("show")&&O!==T){for(var q=0;S>=q;q++){var E=Math.cos(A),R=Math.sin(A),W=new r.Line({shape:{x1:E*g+v,y1:R*g+m,x2:E*(g-P)+v,y2:R*(g-P)+m},silent:!0,style:I});"auto"===I[h]&&W.setStyle({stroke:s((O+q/S)/T)}),p.add(W),A+=z}A-=z}else A+=L}},_renderPointer:function(t,e,i,s,l,u,c){var h=[+t.get("min"),+t.get("max")],f=[u,c],d=t[N](),p=this._data,v=this.group;d.diff(p).add(function(e){var i=new n({shape:{angle:u}});r.updateProps(i,{shape:{angle:a.linearMap(d.get("value",e),h,f,!0)}},t),v.add(i),d.setItemGraphicEl(e,i)})[O](function(e,i){var n=p.getItemGraphicEl(i);r.updateProps(n,{shape:{angle:a.linearMap(d.get("value",e),h,f,!0)}},t),v.add(n),d.setItemGraphicEl(e,n)}).remove(function(t){var e=p.getItemGraphicEl(t);v.remove(e)}).execute(),d.eachItemGraphicEl(function(t,e){var i=d[m](e),n=i[U]("pointer");t.setShape({x:l.cx,y:l.cy,width:o(n.get("width"),l.r),r:o(n.get(G),l.r)}),t.useStyle(i[U]("itemStyle.normal").getItemStyle()),"auto"===t.style.fill&&t.setStyle("fill",s((d.get("value",e)-h[0])/(h[1]-h[0]))),r.setHoverStyle(t,i[U]("itemStyle.emphasis").getItemStyle())}),this._data=d},_renderTitle:function(t,e,i,n,a){var s=t[U]("title");if(s.get("show")){var l=s[U](f),u=s.get("offsetCenter"),c=a.cx+o(u[0],a.r),h=a.cy+o(u[1],a.r),d=new r.Text({style:{x:c,y:h,text:t[N]().getName(0),fill:l.getTextColor(),textFont:l.getFont(),textAlign:"center",textVerticalAlign:"middle"}});this.group.add(d)}},_renderDetail:function(t,e,n,s,l){var u=t[U]("detail"),c=t.get("min"),h=t.get("max");if(u.get("show")){var d=u[U](f),p=u.get("offsetCenter"),v=l.cx+o(p[0],l.r),m=l.cy+o(p[1],l.r),g=o(u.get("width"),l.r),y=o(u.get($),l.r),_=t[N]().get("value",0),x=new r.Rect({shape:{x:v-g/2,y:m-y/2,width:g,height:y},style:{text:i(_,u.get("formatter")),fill:u.get("backgroundColor"),textFill:d.getTextColor(),textFont:d.getFont()}});"auto"===x.style.textFill&&x.setStyle("textFill",s(a.linearMap(_,[c,h],[0,1],!0))),x.setStyle(u.getItemStyle(["color"])),this.group.add(x)}}});return l}),e("echarts/util/graphic",[ne,ie,"zrender/tool/path","zrender/graphic/Path","zrender/tool/color","zrender/core/matrix","zrender/core/vector","zrender/graphic/Gradient","zrender/container/Group","zrender/graphic/Image","zrender/graphic/Text","zrender/graphic/shape/Circle","zrender/graphic/shape/Sector","zrender/graphic/shape/Ring","zrender/graphic/shape/Polygon","zrender/graphic/shape/Polyline","zrender/graphic/shape/Rect","zrender/graphic/shape/Line","zrender/graphic/shape/BezierCurve","zrender/graphic/shape/Arc","zrender/graphic/CompoundPath","zrender/graphic/LinearGradient","zrender/graphic/RadialGradient","zrender/core/BoundingRect"],function(t){function e(t){return null!=t&&"none"!=t}function i(t){return typeof t===Q?S.lift(t,-.1):t}function n(t){if(t.__hoverStlDirty){var n=t.style[h],r=t.style.fill,a=t.__hoverStl;a.fill=a.fill||(e(r)?i(r):null),a[h]=a[h]||(e(n)?i(n):null);var o={};for(var s in a)a.hasOwnProperty(s)&&(o[s]=t.style[s]);t.__normalStl=o,t.__hoverStlDirty=!1}}function r(t){t.__isHover||(n(t),t.useHoverLayer?t.__zr&&t.__zr.addHover(t,t.__hoverStl):(t.setStyle(t.__hoverStl),t.z2+=1),t.__isHover=!0)}function a(t){if(t.__isHover){var e=t.__normalStl;t.useHoverLayer?t.__zr&&t.__zr.removeHover(t):(e&&t.setStyle(e),t.z2-=1),t.__isHover=!1}}function o(t){"group"===t.type?t.traverse(function(t){"group"!==t.type&&r(t)}):r(t)}function s(t){"group"===t.type?t.traverse(function(t){"group"!==t.type&&a(t)}):a(t)}function l(t,e){t.__hoverStl=t.hoverStyle||e||{},t.__hoverStlDirty=!0,t.__isHover&&n(t)}function p(){!this.__isEmphasis&&o(this)}function m(){!this.__isEmphasis&&s(this)}function g(){this.__isEmphasis=!0,o(this)}function _(){this.__isEmphasis=!1,s(this)}function x(t,e,i,n,r,a){typeof r===C&&(a=r,r=null);var o=n&&(n.ifEnableAnimation?n.ifEnableAnimation():n[v](j));if(o){var s=t?"Update":"",l=n&&n[v]("animationDuration"+s),u=n&&n[v]("animationEasing"+s),c=n&&n[v]("animationDelay"+s);typeof c===C&&(c=c(r)),l>0?e.animateTo(i,l,c||0,u,a):(e.attr(i),a&&a())}else e.attr(i),a&&a()}var b=t(ie),w=t("zrender/tool/path"),M=Math.round,T=t("zrender/graphic/Path"),S=t("zrender/tool/color"),P=t("zrender/core/matrix"),A=t("zrender/core/vector"),L=(t("zrender/graphic/Gradient"),{});return L.Group=t("zrender/container/Group"),L.Image=t("zrender/graphic/Image"),L.Text=t("zrender/graphic/Text"),L.Circle=t("zrender/graphic/shape/Circle"),L.Sector=t("zrender/graphic/shape/Sector"),L.Ring=t("zrender/graphic/shape/Ring"),L.Polygon=t("zrender/graphic/shape/Polygon"),L.Polyline=t("zrender/graphic/shape/Polyline"),L.Rect=t("zrender/graphic/shape/Rect"),L.Line=t("zrender/graphic/shape/Line"),L.BezierCurve=t("zrender/graphic/shape/BezierCurve"),L.Arc=t("zrender/graphic/shape/Arc"),L.CompoundPath=t("zrender/graphic/CompoundPath"),L.LinearGradient=t("zrender/graphic/LinearGradient"),L.RadialGradient=t("zrender/graphic/RadialGradient"),L.BoundingRect=t("zrender/core/BoundingRect"),L.extendShape=function(t){return T[I](t)},L.extendPath=function(t,e){return w.extendFromString(t,e)},L.makePath=function(t,e,i,n){var r=w.createFromString(t,e),a=r[c]();if(i){var o=a.width/a[$];if(n===d){var s,l=i[$]*o;l<=i.width?s=i[$]:(l=i.width,s=l/o);var u=i.x+i.width/2,h=i.y+i[$]/2;i.x=u-l/2,i.y=h-s/2,i.width=l,i[$]=s}this.resizePath(r,i)}return r},L.mergePath=w.mergePath,L.resizePath=function(t,e){if(t[u]){var i=t[c](),n=i.calculateTransform(e);t[u](n)}},L.subPixelOptimizeLine=function(t){var e=L.subPixelOptimize,i=t.shape,n=t.style.lineWidth;return M(2*i.x1)===M(2*i.x2)&&(i.x1=i.x2=e(i.x1,n,!0)),M(2*i.y1)===M(2*i.y2)&&(i.y1=i.y2=e(i.y1,n,!0)),t},L.subPixelOptimizeRect=function(t){var e=L.subPixelOptimize,i=t.shape,n=t.style.lineWidth,r=i.x,a=i.y,o=i.width,s=i[$];return i.x=e(i.x,n,!0),i.y=e(i.y,n,!0),i.width=Math.max(e(r+o,n,!1)-i.x,0===o?0:1),i[$]=Math.max(e(a+s,n,!1)-i.y,0===s?0:1),t},L.subPixelOptimize=function(t,e,i){var n=M(2*t);return(n+M(e))%2===0?n/2:(n+(i?1:-1))/2},L.setHoverStyle=function(t,e){"group"===t.type?t.traverse(function(t){"group"!==t.type&&l(t,e)}):l(t,e),t.on("mouseover",p).on("mouseout",m),t.on("emphasis",g).on("normal",_)},L.setText=function(t,e,i){var n=e[v](y)||"inside",r=n[F]("inside")>=0?"white":i,a=e[U](f);b[I](t,{textDistance:e[v]("distance")||5,textFont:a.getFont(),textPosition:n,textFill:a.getTextColor()||r})},L.updateProps=function(t,e,i,n,r){x(!0,t,e,i,n,r)},L.initProps=function(t,e,i,n,r){x(!1,t,e,i,n,r)},L.getTransform=function(t,e){for(var i=P.identity([]);t&&t!==e;)P.mul(i,t.getLocalTransform(),i),t=t.parent;return i},L[u]=function(t,e,i){return i&&(e=P.invert([],e)),A[u]([],t,e)},L.transformDirection=function(t,e,i){var n=0===e[4]||0===e[5]||0===e[0]?1:Math.abs(2*e[4]/e[0]),r=0===e[4]||0===e[5]||0===e[2]?1:Math.abs(2*e[4]/e[2]),a=["left"===t?-n:"right"===t?n:0,"top"===t?-r:t===V?r:0];return a=L[u](a,e,i),Math.abs(a[0])>Math.abs(a[1])?a[0]>0?"right":"left":a[1]>0?V:"top"},L.groupTransition=function(t,e,i){function n(t){var e={};return t.traverse(function(t){!t.isGroup&&t.anid&&(e[t.anid]=t)}),e}function r(t){var e={position:A.clone(t[y]),rotation:t.rotation};return t.shape&&(e.shape=b[I]({},t.shape)),e}if(t&&e){var a=n(t);e.traverse(function(t){if(!t.isGroup&&t.anid){var e=a[t.anid];if(e){var n=r(t);t.attr(r(e)),L.updateProps(t,n,i,t[B])}}})}},L}),e("echarts/coord/cartesian/Grid",[ne,"exports","../../util/layout","../../coord/axisHelper",ie,"./Cartesian2D","./Axis2D","./GridModel","../../CoordinateSystem"],function(t){function e(t,e){return t.findGridModel()===e}function i(t){var e,i=t.model,n=i.getFormattedLabels(),r=1,a=n[G];a>40&&(r=Math.ceil(a/40));for(var o=0;a>o;o+=r)if(!t.isLabelIgnored(o)){var s=i.getTextRect(n[o]);e?e.union(s):e=s}return e}function n(t,e,i){this._coordsMap={},this._coordsList=[],this._axesMap={},this._axesList=[],this._initCartesian(t,e,i),this._model=t}function r(t,e){var i=t[M](),n=i[0]+i[1];t.toGlobalCoord="x"===t.dim?function(t){return t+e}:function(t){return n-t+e},t.toLocalCoord="x"===t.dim?function(t){return t-e}:function(t){return n-t+e}}function a(t){return u.map(m,function(e){var i=t.getReferringComponents(e)[0];return i})}function o(t){return"cartesian2d"===t.get(te)}var s=t("../../util/layout"),l=t("../../coord/axisHelper"),u=t(ie),c=t("./Cartesian2D"),h=t("./Axis2D"),f=u.each,d=l.ifAxisCrossZero,p=l.niceScaleExtent;t("./GridModel");var v=n[K];v.type="grid",v.getRect=function(){return this._rect},v[O]=function(t,e){function i(t){var e=n[t];for(var i in e)if(e.hasOwnProperty(i)){var r=e[i];if(r&&("category"===r.type||!d(r)))return!0}return!1}var n=this._axesMap;this._updateScale(t,this._model),f(n.x,function(t){p(t,t.model)}),f(n.y,function(t){p(t,t.model)}),f(n.x,function(t){i("y")&&(t.onZero=!1)}),f(n.y,function(t){i("x")&&(t.onZero=!1)}),this[Y](this._model,e)},v[Y]=function(t,e){function n(){f(o,function(t){var e=t.isHorizontal(),i=e?[0,a.width]:[0,a[$]],n=t.inverse?1:0;t.setExtent(i[n],i[1-n]),r(t,e?a.x:a.y)})}var a=s.getLayoutRect(t.getBoxLayoutParams(),{width:e[X](),height:e[Z]()});this._rect=a;var o=this._axesList;n(),t.get("containLabel")&&(f(o,function(t){if(!t.model.get("axisLabel.inside")){var e=i(t);if(e){var n=t.isHorizontal()?$:"width",r=t.model.get("axisLabel.margin");a[n]-=e[n]+r,"top"===t[y]?a.y+=e[$]+r:"left"===t[y]&&(a.x+=e.width+r)}}}),n())},v.getAxis=function(t,e){var i=this._axesMap[t];if(null!=i){if(null==e)for(var n in i)if(i.hasOwnProperty(n))return i[n];return i[e]}},v.getCartesian=function(t,e){if(null!=t&&null!=e){var i="x"+t+"y"+e;return this._coordsMap[i]}for(var n=0,r=this._coordsList;n<r[G];n++)if(r[n].getAxis("x").index===t||r[n].getAxis("y").index===e)return r[n]},v.convertToPixel=function(t,e,i){var n=this._findConvertTarget(t,e);return n.cartesian?n.cartesian.dataToPoint(i):n.axis?n.axis.toGlobalCoord(n.axis.dataToCoord(i)):null},v.convertFromPixel=function(t,e,i){var n=this._findConvertTarget(t,e);return n.cartesian?n.cartesian.pointToData(i):n.axis?n.axis.coordToData(n.axis.toLocalCoord(i)):null},v._findConvertTarget=function(t,e){var i,n,r=e.seriesModel,a=e.xAxisModel||r&&r.getReferringComponents("xAxis")[0],o=e.yAxisModel||r&&r.getReferringComponents("yAxis")[0],s=e.gridModel,l=this._coordsList;if(r)i=r[te],u[F](l,i)<0&&(i=null);else if(a&&o)i=this.getCartesian(a.componentIndex,o.componentIndex);else if(a)n=this.getAxis("x",a.componentIndex);else if(o)n=this.getAxis("y",o.componentIndex);else if(s){var c=s[te];c===this&&(i=this._coordsList[0])}return{cartesian:i,axis:n}},v.containPoint=function(t){var e=this._coordsList[0];return e?e.containPoint(t):void 0},v._initCartesian=function(t,i){function n(n){return function(s,u){if(e(s,t,i)){var c=s.get(y);"x"===n?"top"!==c&&c!==V&&(c=V,r[c]&&(c="top"===c?V:"top")):"left"!==c&&"right"!==c&&(c="left",r[c]&&(c="left"===c?"right":"left")),r[c]=!0;var f=new h(n,l.createScaleByModel(s),[0,0],s.get("type"),c),d="category"===f.type;f.onBand=d&&s.get("boundaryGap"),f.inverse=s.get("inverse"),f.onZero=s.get("axisLine.onZero"),s.axis=f,f.model=s,f.grid=this,f.index=u,this._axesList.push(f),a[n][u]=f,o[n]++}}}var r={left:!1,right:!1,top:!1,bottom:!1},a={x:{},y:{}},o={x:0,y:0};return i.eachComponent("xAxis",n("x"),this),i.eachComponent("yAxis",n("y"),this),o.x&&o.y?(this._axesMap=a,void f(a.x,function(t,e){f(a.y,function(i,n){var r="x"+e+"y"+n,a=new c(r);a.grid=this,this._coordsMap[r]=a,this._coordsList.push(a),a.addAxis(t),a.addAxis(i)},this)},this)):(this._axesMap={},void(this._axesList=[]))},v._updateScale=function(t,i){function n(t,e,i){f(i.coordDimToDataDim(e.dim),function(i){e.scale.unionExtent(t.getDataExtent(i,e.scale.type!==g))})}u.each(this._axesList,function(t){t.scale.setExtent(1/0,-1/0)}),t.eachSeries(function(r){if(o(r)){var s=a(r,t),l=s[0],u=s[1];if(!e(l,i,t)||!e(u,i,t))return;var c=this.getCartesian(l.componentIndex,u.componentIndex),h=r[N](),f=c.getAxis("x"),d=c.getAxis("y");"list"===h.type&&(n(h,f,r),n(h,d,r))}},this)};var m=["xAxis","yAxis"];return n[E]=function(t,e){var i=[];return t.eachComponent("grid",function(r,a){var o=new n(r,t,e);o.name="grid_"+a,o[Y](r,e),r[te]=o,i.push(o)}),t.eachSeries(function(e){if(o(e)){var i=a(e,t),n=i[0],r=i[1],s=n.findGridModel(),l=s[te];e[te]=l.getCartesian(n.componentIndex,r.componentIndex)}}),i},n[_]=c[K][_],t("../../CoordinateSystem").register("cartesian2d",n),n}),e("echarts/component/axis",[ne,"../coord/cartesian/AxisModel","./axis/AxisView"],function(t){t("../coord/cartesian/AxisModel"),t("./axis/AxisView")}),e("echarts/component/legend/LegendModel",[ne,ie,"../../model/Model","../../echarts"],function(t){var e=t(ie),i=t("../../model/Model"),n=t("../../echarts").extendComponentModel({type:"legend",dependencies:["series"],layoutMode:{type:"box",ignoreSize:!0},init:function(t,e,i){this.mergeDefaultAndTheme(t,i),t.selected=t.selected||{}},mergeOption:function(t){n.superCall(this,"mergeOption",t)},optionUpdated:function(){this._updateData(this[s]);var t=this._data;if(t[0]&&"single"===this.get("selectedMode")){for(var e=!1,i=0;i<t[G];i++){var n=t[i].get("name");if(this.isSelected(n)){this.select(n),e=!0;break}}!e&&this.select(t[0].get("name"))}},_updateData:function(t){var n=e.map(this.get("data")||[],function(t){return(typeof t===Q||"number"==typeof t)&&(t={name:t}),new i(t,this,this[s])},this);this._data=n;var r=e.map(t.getSeries(),function(t){return t.name});t.eachSeries(function(t){if(t.legendDataProvider){var e=t.legendDataProvider();r=r[b](e.mapArray(e.getName))}}),this._availableNames=r},getData:function(){return this._data},select:function(t){var i=this.option.selected,n=this.get("selectedMode");if("single"===n){var r=this._data;e.each(r,function(t){i[t.get("name")]=!1})}i[t]=!0},unSelect:function(t){"single"!==this.get("selectedMode")&&(this.option.selected[t]=!1)},toggleSelected:function(t){var e=this.option.selected;e.hasOwnProperty(t)||(e[t]=!0),this[e[t]?"unSelect":"select"](t)},isSelected:function(t){var i=this.option.selected;return!(i.hasOwnProperty(t)&&!i[t])&&e[F](this._availableNames,t)>=0},defaultOption:{zlevel:0,z:4,show:!0,orient:"horizontal",left:"center",top:"top",align:"auto",backgroundColor:"rgba(0,0,0,0)",borderColor:"#ccc",borderWidth:0,padding:5,itemGap:10,itemWidth:25,itemHeight:14,inactiveColor:"#ccc",textStyle:{color:"#333"},selectedMode:!0,tooltip:{show:!1}}});return n}),e("echarts/component/legend/legendAction",[ne,"../../echarts",ie],function(t){function e(t,e,i){var r,a={},o="toggleSelected"===t;return i.eachComponent("legend",function(i){o&&null!=r?i[r?"select":"unSelect"](e.name):(i[t](e.name),r=i.isSelected(e.name));var s=i[N]();n.each(s,function(t){var e=t.get("name");if("\n"!==e&&""!==e){var n=i.isSelected(e);a[e]=e in a?a[e]&&n:n}})}),{name:e.name,selected:a}}var i=t("../../echarts"),n=t(ie);i.registerAction("legendToggleSelect","legendselectchanged",n.curry(e,"toggleSelected")),i.registerAction("legendSelect","legendselected",n.curry(e,"select")),i.registerAction("legendUnSelect","legendunselected",n.curry(e,"unSelect"))}),e("echarts/component/legend/legendFilter",[],function(){return function(t){var e=t.findComponents({mainType:"legend"});e&&e[G]&&t.filterSeries(function(t){for(var i=0;i<e[G];i++)if(!e[i].isSelected(t.name))return!1;return!0})}}),e("echarts/component/legend/LegendView",[ne,ie,"../../util/symbol","../../util/graphic","../helper/listComponent","../../echarts"],function(t){function e(t,e){e.dispatchAction({type:"legendToggleSelect",name:t})}function i(t,e,i){var n=i.getZr()[q].getDisplayList()[0];n&&n.useHoverLayer||t.get("legendHoverLink")&&i.dispatchAction({type:"highlight",seriesName:t.name,name:e})}function n(t,e,i){var n=i.getZr()[q].getDisplayList()[0];n&&n.useHoverLayer||t.get("legendHoverLink")&&i.dispatchAction({type:"downplay",seriesName:t.name,name:e})}var r=t(ie),a=t("../../util/symbol"),o=t("../../util/graphic"),s=t("../helper/listComponent"),l=r.curry;return t("../../echarts").extendComponentView({type:"legend",init:function(){this._symbolTypeStore={}},render:function(t,a,u){var c=this.group;if(c[ee](),t.get("show")){var h=t.get("selectedMode"),f=t.get("align");"auto"===f&&(f="right"===t.get("left")&&"vertical"===t.get("orient")?"right":"left");var d={};r.each(t[N](),function(r){var s=r.get("name");if(""===s||"\n"===s)return void c.add(new o.Group({newline:!0}));var p=a.getSeriesByName(s)[0];if(!d[s])if(p){var v=p[N](),m=v.getVisual("color");typeof m===C&&(m=m(p.getDataParams(0)));var g=v.getVisual("legendSymbol")||"roundRect",y=v.getVisual("symbol"),_=this._createItem(s,r,t,g,y,f,m,h);_.on("click",l(e,s,u)).on("mouseover",l(i,p,null,u)).on("mouseout",l(n,p,null,u)),d[s]=!0}else a.eachRawSeries(function(a){if(!d[s]&&a.legendDataProvider){var o=a.legendDataProvider(),c=o.indexOfName(s);if(0>c)return;var p=o[R](c,"color"),v="roundRect",m=this._createItem(s,r,t,v,null,f,p,h);m.on("click",l(e,s,u)).on("mouseover",l(i,a,s,u)).on("mouseout",l(n,a,s,u)),d[s]=!0}},this)},this),s.layout(c,t,u),s.addBackground(c,t)}},_createItem:function(t,e,i,n,s,l,u,h){var d=i.get("itemWidth"),p=i.get("itemHeight"),v=i.get("inactiveColor"),m=i.isSelected(t),g=new o.Group,y=e[U](f),_=e.get("icon"),x=e[U]("tooltip"),b=x.parentModel;if(n=_||n,g.add(a.createSymbol(n,0,0,d,p,m?u:v)),!_&&s&&(s!==n||"none"==s)){var w=.8*p;"none"===s&&(s="circle"),g.add(a.createSymbol(s,(d-w)/2,(p-w)/2,w,w,m?u:v))}var M="left"===l?d+5:-5,T=l,S=i.get("formatter"),P=t;typeof S===Q&&S?P=S[A]("{name}",t):typeof S===C&&(P=S(t));var L=new o.Text({style:{text:P,x:M,y:p/2,fill:m?y.getTextColor():v,textFont:y.getFont(),textAlign:T,textVerticalAlign:"middle"}});g.add(L);var z=new o.Rect({shape:g[c](),invisible:!0,tooltip:x.get("show")?r[I]({content:t,formatter:b.get("formatter",!0)||function(){return t},formatterParams:{componentType:"legend",legendIndex:i.componentIndex,name:t,$vars:["name"]}},x.option):null});return g.add(z),g.eachChild(function(t){t.silent=!0}),z.silent=!h,this.group.add(g),o.setHoverStyle(g),g}})}),e("echarts/component/tooltip/TooltipModel",[ne,"../../echarts"],function(t){t("../../echarts").extendComponentModel({type:"tooltip",defaultOption:{zlevel:0,z:8,show:!0,showContent:!0,trigger:"item",triggerOn:"mousemove",alwaysShowContent:!1,showDelay:0,hideDelay:100,transitionDuration:.4,enterable:!1,backgroundColor:"rgba(50,50,50,0.7)",borderColor:"#333",borderRadius:4,borderWidth:0,padding:5,extraCssText:"",axisPointer:{type:"line",axis:"auto",animation:!0,animationDurationUpdate:200,animationEasingUpdate:"exponentialOut",lineStyle:{color:"#555",width:1,type:"solid"},crossStyle:{color:"#555",width:1,type:"dashed",textStyle:{}},shadowStyle:{color:"rgba(150,150,150,0.3)"}},textStyle:{color:"#fff",fontSize:14}}})}),e("echarts/component/tooltip/TooltipView",[ne,"./TooltipContent","../../util/graphic",ie,"../../util/format","../../util/number","../../util/model","zrender/core/env","../../model/Model","../../echarts"],function(t){function e(t,e){if(!t||!e)return!1;var i=S.round;return i(t[0])===i(e[0])&&i(t[1])===i(e[1])}function i(t,e,i,n){return{x1:t,y1:e,x2:i,y2:n}}function n(t,e,i,n){return{x:t,y:e,width:i,height:n}}function r(t,e,i,n,r,a){return{cx:t,cy:e,r0:i,r:n,startAngle:r,endAngle:a,clockwise:!0}}function l(t,e,i,n,r){var a=i.clientWidth,o=i.clientHeight,s=20;return t+a+s>n?t-=a+s:t+=s,e+o+s>r?e-=o+s:e+=s,[t,e]}function d(t,e,i){var n=i.clientWidth,r=i.clientHeight,a=5,o=0,s=0,l=e.width,u=e[$];switch(t){case"inside":o=e.x+l/2-n/2,s=e.y+u/2-r/2;break;case"top":o=e.x+l/2-n/2,s=e.y-r-a;break;case V:o=e.x+l/2-n/2,s=e.y+u+a;break;case"left":o=e.x-n-a,s=e.y+u/2-r/2;break;case"right":o=e.x+l+a,s=e.y+u/2-r/2}return[o,s]}function v(t,e,i,n,r,s,h){var f=h[X](),p=h[Z](),v=s&&s[c]().clone();if(s&&v[u](s[o]),typeof t===C&&(t=t([e,i],r,n.el,v)),w[P](t))e=k(t[0],f),i=k(t[1],p);else if(typeof t===Q&&s){var m=d(t,v,n.el);e=m[0],i=m[1]}else{var m=l(e,i,n.el,f,p);e=m[0],i=m[1]}n[a](e,i)}function g(t){var e=t[te],i=t.get("tooltip.trigger",!0);return!(!e||"cartesian2d"!==e.type&&"polar"!==e.type&&"singleAxis"!==e.type||"item"===i)}var x=t("./TooltipContent"),b=t("../../util/graphic"),w=t(ie),T=t("../../util/format"),S=t("../../util/number"),A=t("../../util/model"),k=S[p],I=t("zrender/core/env"),D=t("../../model/Model");t("../../echarts").extendComponentView({type:"tooltip",_axisPointers:{},init:function(t,e){if(!I.node){var i=new x(e.getDom(),e);this._tooltipContent=i,e.on("showTip",this._manuallyShowTip,this),e.on("hideTip",this._manuallyHideTip,this)}},render:function(t,e,i){if(!I.node){this.group[ee](),this._axisPointers={},this._tooltipModel=t,this._ecModel=e,this._api=i,this._lastHover={};var n=this._tooltipContent;n[O](),n.enterable=t.get("enterable"),this._alwaysShowContent=t.get("alwaysShowContent"),this._seriesGroupByAxis=this._prepareAxisTriggerData(t,e);var r=this._crossText;r&&this.group.add(r);var a=t.get("triggerOn");if(null!=this._lastX&&null!=this._lastY&&"none"!==a){var o=this;clearTimeout(this._refreshUpdateTimeout),this._refreshUpdateTimeout=setTimeout(function(){o._manuallyShowTip({x:o._lastX,y:o._lastY})})}var s=this._api.getZr();s.off("click",this._tryShow),s.off("mousemove",this._mousemove),s.off("mouseout",this._hide),s.off("globalout",this._hide),"click"===a?s.on("click",this._tryShow,this):"mousemove"===a&&(s.on("mousemove",this._mousemove,this),s.on("mouseout",this._hide,this),s.on("globalout",this._hide,this))}},_mousemove:function(t){var e=this._tooltipModel.get("showDelay"),i=this;clearTimeout(this._showTimeout),e>0?this._showTimeout=setTimeout(function(){i._tryShow(t)},e):this._tryShow(t)},_manuallyShowTip:function(t){if(t.from!==this.uid){var e=this._ecModel,i=t.seriesIndex,n=e.getSeriesByIndex(i),r=this._api;if(null==t.x||null==t.y){if(n||e.eachSeries(function(t){g(t)&&!n&&(n=t)}),n){var a=n[N](),s=A.queryDataIndex(a,t);if(null==s||w[P](s))return;var l,h,f=a.getItemGraphicEl(s),d=n[te];if(n.getTooltipPosition){var p=n.getTooltipPosition(s)||[];l=p[0],h=p[1]}else if(d&&d.dataToPoint){var p=d.dataToPoint(a.getValues(w.map(d[_],function(t){return n.coordDimToDataDim(t)[0]}),s,!0));l=p&&p[0],h=p&&p[1]}else if(f){var v=f[c]().clone();v[u](f[o]),l=v.x+v.width/2,h=v.y+v[$]/2}null!=l&&null!=h&&this._tryShow({offsetX:l,offsetY:h,position:t[y],target:f,event:{}})}}else{var f=r.getZr().handler.findHover(t.x,t.y);this._tryShow({offsetX:t.x,offsetY:t.y,position:t[y],target:f,event:{}})}}},_manuallyHideTip:function(t){t.from!==this.uid&&this._hide()},_prepareAxisTriggerData:function(t,e){var i={};return e.eachSeries(function(t){if(g(t)){var e,n,r=t[te];"cartesian2d"===r.type?(e=r.getBaseAxis(),n=e.dim+e.index):"singleAxis"===r.type?(e=r.getAxis(),n=e.dim+e.type):(e=r.getBaseAxis(),n=e.dim+r.name),i[n]=i[n]||{coordSys:[],series:[]},i[n].coordSys.push(r),i[n].series.push(t)}},this),i},_tryShow:function(t){var e=t[z],i=this._tooltipModel,n=i.get("trigger"),r=this._ecModel,a=this._api;if(i)if(this._lastX=t.offsetX,this._lastY=t.offsetY,e&&null!=e[B]){var o=e.dataModel||r.getSeriesByIndex(e.seriesIndex),s=e[B],l=o[N]()[m](s);"axis"===(l.get("tooltip.trigger")||n)?this._showAxisTooltip(i,r,t):(this._ticket="",this._hideAxisPointer(),this._resetLastHover(),this._showItemTooltipContent(o,s,e.dataType,t)),a.dispatchAction({type:"showTip",from:this.uid,dataIndexInside:e[B],seriesIndex:e.seriesIndex})}else if(e&&e.tooltip){var u=e.tooltip;if(typeof u===Q){var c=u;u={content:c,formatter:c}}var h=new D(u,i),f=h.get("content"),d=Math.random();this._showTooltipContent(h,f,h.get("formatterParams")||{},d,t.offsetX,t.offsetY,t[y],e,a)}else"item"===n?this._hide():this._showAxisTooltip(i,r,t),"cross"===i.get("axisPointer.type")&&a.dispatchAction({type:"showTip",from:this.uid,x:t.offsetX,y:t.offsetY})},_showAxisTooltip:function(t,i,n){var r=t[U]("axisPointer"),a=r.get("type");if("cross"===a){var o=n[z];if(o&&null!=o[B]){var s=i.getSeriesByIndex(o.seriesIndex),l=o[B];this._showItemTooltipContent(s,l,o.dataType,n)}}this._showAxisPointer();var u=!0;w.each(this._seriesGroupByAxis,function(t){var i=t.coordSys,o=i[0],s=[n.offsetX,n.offsetY];if(!o.containPoint(s))return void this._hideAxisPointer(o.name);u=!1;var l=o[_],c=o.pointToData(s,!0);s=o.dataToPoint(c);var h=o.getBaseAxis(),f=r.get("axis");"auto"===f&&(f=h.dim);var d=!1,p=this._lastHover;if("cross"===a)e(p.data,c)&&(d=!0),p.data=c;else{var v=w[F](l,f);p.data===c[v]&&(d=!0),p.data=c[v]}"cartesian2d"!==o.type||d?"polar"!==o.type||d?"singleAxis"!==o.type||d||this._showSinglePointer(r,o,f,s):this._showPolarPointer(r,o,f,s):this._showCartesianPointer(r,o,f,s),"cross"!==a&&this._dispatchAndShowSeriesTooltipContent(o,t.series,s,c,d,n[y])},this),this._tooltipModel.get("show")||this._hideAxisPointer(),u&&this._hide()},_showCartesianPointer:function(t,e,r,a){function o(n,r,a){var o="x"===n?i(r[0],a[0],r[0],a[1]):i(a[0],r[1],a[1],r[1]),s=l._getPointerElement(e,t,n,o);b.subPixelOptimizeLine({shape:o,style:s.style}),h?b.updateProps(s,{shape:o},t):s.attr({shape:o})}function s(i,r,a){var o=e.getAxis(i),s=o.getBandWidth(),u=a[1]-a[0],c="x"===i?n(r[0]-s/2,a[0],s,u):n(a[0],r[1]-s/2,u,s),f=l._getPointerElement(e,t,i,c);h?b.updateProps(f,{shape:c},t):f.attr({shape:c})}var l=this,u=t.get("type"),c=e.getBaseAxis(),h="cross"!==u&&"category"===c.type&&c.getBandWidth()>20;if("cross"===u)o("x",a,e.getAxis("y").getGlobalExtent()),o("y",a,e.getAxis("x").getGlobalExtent()),this._updateCrossText(e,a,t);else{var f=e.getAxis("x"===r?"y":"x"),d=f.getGlobalExtent();"cartesian2d"===e.type&&("line"===u?o:s)(r,a,d)}},_showSinglePointer:function(t,e,n,r){function a(n,r,a){var s=e.getAxis(),u=s.orient,c="horizontal"===u?i(r[0],a[0],r[0],a[1]):i(a[0],r[1],a[1],r[1]),h=o._getPointerElement(e,t,n,c);l?b.updateProps(h,{shape:c},t):h.attr({shape:c})}var o=this,s=t.get("type"),l="cross"!==s&&"category"===e.getBaseAxis().type,u=e.getRect(),c=[u.y,u.y+u[$]];a(n,r,c)},_showPolarPointer:function(t,e,n,a){function o(n,r,a){var o,s=e.pointToCoord(r);if("angle"===n){var u=e.coordToPoint([a[0],s[1]]),c=e.coordToPoint([a[1],s[1]]);o=i(u[0],u[1],c[0],c[1])}else o={cx:e.cx,cy:e.cy,r:s[0]};var h=l._getPointerElement(e,t,n,o);f?b.updateProps(h,{shape:o},t):h.attr({shape:o})}function s(i,n,a){var o,s=e.getAxis(i),u=s.getBandWidth(),c=e.pointToCoord(n),h=Math.PI/180;o="angle"===i?r(e.cx,e.cy,a[0],a[1],(-c[1]-u/2)*h,(-c[1]+u/2)*h):r(e.cx,e.cy,c[0]-u/2,c[0]+u/2,0,2*Math.PI);var d=l._getPointerElement(e,t,i,o);f?b.updateProps(d,{shape:o},t):d.attr({shape:o})}var l=this,u=t.get("type"),c=e.getAngleAxis(),h=e.getRadiusAxis(),f="cross"!==u&&"category"===e.getBaseAxis().type;if("cross"===u)o("angle",a,h[M]()),o("radius",a,c[M]()),this._updateCrossText(e,a,t);else{var d=e.getAxis("radius"===n?"angle":"radius"),p=d[M]();("line"===u?o:s)(n,a,p)}},_updateCrossText:function(t,e,i){var n=i[U]("crossStyle"),r=n[U](f),a=this._tooltipModel,o=this._crossText;o||(o=this._crossText=new b.Text({style:{textAlign:"left",textVerticalAlign:"bottom"}}),this.group.add(o));var s=t.pointToData(e),l=t[_];s=w.map(s,function(e,i){var n=t.getAxis(l[i]);return e="category"===n.type||"time"===n.type?n.scale.getLabel(e):T.addCommas(e.toFixed(n.getPixelPrecision()))}),o.setStyle({fill:r.getTextColor()||n.get("color"),textFont:r.getFont(),text:s.join(", "),x:e[0]+5,y:e[1]-5}),o.z=a.get("z"),o[L]=a.get(L)},_getPointerElement:function(t,e,i,n){var r=this._tooltipModel,a=r.get("z"),o=r.get(L),s=this._axisPointers,l=t.name;if(s[l]=s[l]||{},s[l][i])return s[l][i];var u=e.get("type"),c=e[U](u+"Style"),f="shadow"===u,d=c[f?"getAreaStyle":"getLineStyle"](),p="polar"===t.type?f?"Sector":"radius"===i?"Circle":"Line":f?"Rect":"Line";f?d[h]=null:d.fill=null;var v=s[l][i]=new b[p]({style:d,z:a,zlevel:o,silent:!0,shape:n});return this.group.add(v),v
},_dispatchAndShowSeriesTooltipContent:function(t,e,i,n,r,a){var o=this._tooltipModel,s=t.getBaseAxis(),l="x"===s.dim||"radius"===s.dim?0:1,u=w.map(e,function(t){return{seriesIndex:t.seriesIndex,dataIndexInside:t.getAxisTooltipDataIndex?t.getAxisTooltipDataIndex(t.coordDimToDataDim(s.dim),n,s):t[N]().indexOfNearest(t.coordDimToDataDim(s.dim)[0],n[l],!1,"category"===s.type?.5:null)}}),c=this._lastHover,h=this._api;if(c.payloadBatch&&!r&&h.dispatchAction({type:"downplay",batch:c.payloadBatch}),r||(h.dispatchAction({type:"highlight",batch:u}),c.payloadBatch=u),h.dispatchAction({type:"showTip",dataIndexInside:u[0].dataIndexInside,seriesIndex:u[0].seriesIndex,from:this.uid}),s&&o.get("showContent")&&o.get("show")){var f=w.map(e,function(t,e){return t.getDataParams(u[e].dataIndexInside)});if(r)v(a||o.get(y),i[0],i[1],this._tooltipContent,f,null,h);else{var d=u[0].dataIndexInside,p="time"===s.type?s.scale.getLabel(n[l]):e[0][N]().getName(d),m=(p?p+"<br />":"")+w.map(e,function(t,e){return t.formatTooltip(u[e].dataIndexInside,!0)}).join("<br />"),g="axis_"+t.name+"_"+d;this._showTooltipContent(o,m,f,g,i[0],i[1],a,null,h)}}},_showItemTooltipContent:function(t,e,i,n){var r=this._api,a=t[N](i),o=a[m](e),l=o.get("tooltip",!0);if(typeof l===Q){var u=l;l={formatter:u}}var c=this._tooltipModel,h=t[U]("tooltip",c),f=new D(l,h,h[s]),d=t.getDataParams(e,i),p=t.formatTooltip(e,!1,i),v="item_"+t.name+"_"+e;this._showTooltipContent(f,p,d,v,n.offsetX,n.offsetY,n[y],n[z],r)},_showTooltipContent:function(t,e,i,n,r,a,o,s,l){if(this._ticket="",t.get("showContent")&&t.get("show")){var u=this._tooltipContent,c=t.get("formatter");o=o||t.get(y);var h=e;if(c)if(typeof c===Q)h=T.formatTpl(c,i);else if(typeof c===C){var f=this,d=n,p=function(t,e){t===f._ticket&&(u.setContent(e),v(o,r,a,u,i,s,l))};f._ticket=d,h=c(i,d,p)}u.show(t),u.setContent(h),v(o,r,a,u,i,s,l)}},_showAxisPointer:function(t){if(t){var e=this._axisPointers[t];e&&w.each(e,function(t){t.show()})}else this.group.eachChild(function(t){t.show()}),this.group.show()},_resetLastHover:function(){var t=this._lastHover;t.payloadBatch&&this._api.dispatchAction({type:"downplay",batch:t.payloadBatch}),this._lastHover={}},_hideAxisPointer:function(t){if(t){var e=this._axisPointers[t];e&&w.each(e,function(t){t.hide()})}else this.group.children()[G]&&this.group.hide()},_hide:function(){clearTimeout(this._showTimeout),this._hideAxisPointer(),this._resetLastHover(),this._alwaysShowContent||this._tooltipContent.hideLater(this._tooltipModel.get("hideDelay")),this._api.dispatchAction({type:"hideTip",from:this.uid}),this._lastX=this._lastY=null},dispose:function(t,e){if(!I.node){var i=e.getZr();this._tooltipContent.hide(),i.off("click",this._tryShow),i.off("mousemove",this._mousemove),i.off("mouseout",this._hide),i.off("globalout",this._hide),e.off("showTip",this._manuallyShowTip),e.off("hideTip",this._manuallyHideTip)}}})}),e("zrender/core/env",[],function(){function t(t){var e={},i={},n=t.match(/Firefox\/([\d.]+)/),r=t.match(/MSIE\s([\d.]+)/)||t.match(/Trident\/.+?rv:(([\d.]+))/),a=t.match(/Edge\/([\d.]+)/);return n&&(i.firefox=!0,i.version=n[1]),r&&(i.ie=!0,i.version=r[1]),a&&(i.edge=!0,i.version=a[1]),{browser:i,os:e,node:!1,canvasSupported:document[w]("canvas").getContext?!0:!1,touchEventsSupported:"ontouchstart"in window&&!i.ie&&!i.edge,pointerEventsSupported:"onpointerdown"in window&&(i.edge||i.ie&&i.version>=10)}}var e={};return e=typeof navigator===r?{browser:{},os:{},node:!0,canvasSupported:!0}:t(navigator.userAgent)}),e("echarts/model/Global",[ne,ie,"../util/model","./Model","./Component","./globalDefault","./mixin/colorPalette"],function(t){function e(t,e){u.each(e,function(e,i){y.hasClass(i)||("object"==typeof e?t[i]=t[i]?u.merge(t[i],e,!1):u.clone(e):null==t[i]&&(t[i]=e))})}function i(t){t=t,this.option={},this.option[x]=1,this._componentsMap={},this._seriesIndices=null,e(t,this._theme.option),u.merge(t,_,!1),this.mergeOption(t)}function n(t,e){u[P](e)||(e=e?[e]:[]);var i={};return f(e,function(e){i[e]=(t[e]||[]).slice()}),i}function r(t,e){var i={};f(e,function(t){var e=t.exist;e&&(i[e.id]=t)}),f(e,function(e){var n=e.option;if(u.assert(!n||null==n.id||!i[n.id]||i[n.id]===e,"id duplicates: "+(n&&n.id)),n&&null!=n.id&&(i[n.id]=e),g(n)){var r=a(t,n,e.exist);e.keyInfo={mainType:t,subType:r}}}),f(e,function(t){var e=t.exist,n=t.option,r=t.keyInfo;if(g(n)){if(r.name=null!=n.name?n.name+"":e?e.name:"\x00-",e)r.id=e.id;else if(null!=n.id)r.id=n.id+"";else{var a=0;do r.id="\x00"+r.name+"\x00"+a++;while(i[r.id])}i[r.id]=t}})}function a(t,e,i){var n=e.type?e.type:i?i.subType:y.determineSubType(t,e);return n}function o(t){return p(t,function(t){return t.componentIndex})||[]}function s(t,e){return e.hasOwnProperty("subType")?d(t,function(t){return t.subType===e.subType}):t}function l(t){}var u=t(ie),c=t("../util/model"),h=t("./Model"),f=u.each,d=u.filter,p=u.map,v=u[P],m=u[F],g=u[D],y=t("./Component"),_=t("./globalDefault"),x="\x00_ec_inner",b=h[I]({constructor:b,init:function(t,e,i,n){i=i||{},this.option=null,this._theme=new h(i),this._optionManager=n},setOption:function(t,e){u.assert(!(x in t),"please use chart.getOption()"),this._optionManager.setOption(t,e),this.resetOption()},resetOption:function(t){var e=!1,n=this._optionManager;if(!t||"recreate"===t){var r=n.mountOption("recreate"===t);this.option&&"recreate"!==t?(this.restoreData(),this.mergeOption(r)):i.call(this,r),e=!0}if(("timeline"===t||"media"===t)&&this.restoreData(),!t||"recreate"===t||"timeline"===t){var a=n.getTimelineOption(this);a&&(this.mergeOption(a),e=!0)}if(!t||"recreate"===t||"media"===t){var o=n.getMediaOption(this,this._api);o[G]&&f(o,function(t){this.mergeOption(t,e=!0)},this)}return e},mergeOption:function(t){function e(e,s){var l=c.normalizeToArray(t[e]),h=c.mappingToExists(a[e],l);r(e,h);var d=n(a,s);i[e]=[],a[e]=[],f(h,function(t,n){var r=t.exist,o=t.option;if(u.assert(g(o)||r,"Empty component definition"),o){var s=y.getClass(e,t.keyInfo.subType,!0);if(r&&r instanceof s)r.name=t.keyInfo.name,r.mergeOption(o,this),r.optionUpdated(o,!1);else{var l=u[I]({dependentModels:d,componentIndex:n},t.keyInfo);r=new s(o,this,this,l),u[I](r,l),r.init(o,this,this,l),r.optionUpdated(null,!0)}}else r.mergeOption({},this),r.optionUpdated({},!1);a[e][n]=r,i[e][n]=r.option},this),"series"===e&&(this._seriesIndices=o(a.series))}var i=this.option,a=this._componentsMap,s=[];f(t,function(t,e){null!=t&&(y.hasClass(e)?s.push(e):i[e]=null==i[e]?u.clone(t):u.merge(i[e],t,!0))}),y.topologicalTravel(s,y.getAllClassMainTypes(),e,this),this._seriesIndices=this._seriesIndices||[]},getOption:function(){var t=u.clone(this.option);return f(t,function(e,i){if(y.hasClass(i)){for(var e=c.normalizeToArray(e),n=e[G]-1;n>=0;n--)c.isIdInner(e[n])&&e[k](n,1);t[i]=e}}),delete t[x],t},getTheme:function(){return this._theme},getComponent:function(t,e){var i=this._componentsMap[t];return i?i[e||0]:void 0},queryComponents:function(t){var e=t.mainType;if(!e)return[];var i=t.index,n=t.id,r=t.name,a=this._componentsMap[e];if(!a||!a[G])return[];var o;if(null!=i)v(i)||(i=[i]),o=d(p(i,function(t){return a[t]}),function(t){return!!t});else if(null!=n){var l=v(n);o=d(a,function(t){return l&&m(n,t.id)>=0||!l&&t.id===n})}else if(null!=r){var u=v(r);o=d(a,function(t){return u&&m(r,t.name)>=0||!u&&t.name===r})}else o=a;return s(o,t)},findComponents:function(t){function e(t){var e=r+"Index",i=r+"Id",n=r+"Name";return t&&(t.hasOwnProperty(e)||t.hasOwnProperty(i)||t.hasOwnProperty(n))?{mainType:r,index:t[e],id:t[i],name:t[n]}:null}function i(e){return t.filter?d(e,t.filter):e}var n=t.query,r=t.mainType,a=e(n),o=a?this.queryComponents(a):this._componentsMap[r];return i(s(o,t))},eachComponent:function(t,e,i){var n=this._componentsMap;if(typeof t===C)i=e,e=t,f(n,function(t,n){f(t,function(t,r){e.call(i,n,t,r)})});else if(u.isString(t))f(n[t],e,i);else if(g(t)){var r=this.findComponents(t);f(r,e,i)}},getSeriesByName:function(t){var e=this._componentsMap.series;return d(e,function(e){return e.name===t})},getSeriesByIndex:function(t){return this._componentsMap.series[t]},getSeriesByType:function(t){var e=this._componentsMap.series;return d(e,function(e){return e.subType===t})},getSeries:function(){return this._componentsMap.series.slice()},eachSeries:function(t,e){l(this),f(this._seriesIndices,function(i){var n=this._componentsMap.series[i];t.call(e,n,i)},this)},eachRawSeries:function(t,e){f(this._componentsMap.series,t,e)},eachSeriesByType:function(t,e,i){l(this),f(this._seriesIndices,function(n){var r=this._componentsMap.series[n];r.subType===t&&e.call(i,r,n)},this)},eachRawSeriesByType:function(t,e,i){return f(this.getSeriesByType(t),e,i)},isSeriesFiltered:function(t){return l(this),u[F](this._seriesIndices,t.componentIndex)<0},filterSeries:function(t,e){l(this);var i=d(this._componentsMap.series,t,e);this._seriesIndices=o(i)},restoreData:function(){var t=this._componentsMap;this._seriesIndices=o(t.series);var e=[];f(t,function(t,i){e.push(i)}),y.topologicalTravel(e,y.getAllClassMainTypes(),function(e){f(t[e],function(t){t.restoreData()})})}});return u.mixin(b,t("./mixin/colorPalette")),b}),e("echarts/ExtensionAPI",[ne,ie],function(t){function e(t){i.each(n,function(e){this[e]=i.bind(t[e],t)},this)}var i=t(ie),n=["getDom","getZr",X,Z,"dispatchAction","isDisposed","on","off","getDataURL","getConnectedDataURL",U,"getOption"];return e}),e("echarts/CoordinateSystem",[ne,ie],function(t){function e(){this._coordinateSystems=[]}var i=t(ie),n={};return e[K]={constructor:e,create:function(t,e){var r=[];i.each(n,function(i){var n=i[E](t,e);r=r[b](n||[])}),this._coordinateSystems=r},update:function(t,e){i.each(this._coordinateSystems,function(i){i[O]&&i[O](t,e)})},getCoordinateSystems:function(){return this._coordinateSystems.slice()}},e.register=function(t,e){n[t]=e},e.get=function(t){return n[t]},e}),e("echarts/model/OptionManager",[ne,ie,"../util/model","./Component"],function(t){function e(t){this._api=t,this._timelineOptions=[],this._mediaList=[],this._mediaDefault,this._currentMediaIndices=[],this._optionBackup,this._newBaseOption}function i(t,e,i){var n,r,a=[],o=[],l=t.timeline;if(t.baseOption&&(r=t.baseOption),(l||t.options)&&(r=r||{},a=(t.options||[]).slice()),t.media){r=r||{};var u=t.media;c(u,function(t){t&&t.option&&(t.query?o.push(t):n||(n=t))})}return r||(r=t),r.timeline||(r.timeline=l),c([r][b](a)[b](s.map(o,function(t){return t.option})),function(t){c(e,function(e){e(t,i)})}),{baseOption:r,timelineOptions:a,mediaDefault:n,mediaList:o}}function n(t,e,i){var n={width:e,height:i,aspectratio:e/i},a=!0;return s.each(t,function(t,e){var i=e.match(p);if(i&&i[1]&&i[2]){var o=i[1],s=i[2][J]();r(n[s],t,o)||(a=!1)}}),a}function r(t,e,i){return"min"===i?t>=e:"max"===i?e>=t:t===e}function a(t,e){return t.join(",")===e.join(",")}function o(t,e){e=e||{},c(e,function(e,i){if(null!=e){var n=t[i];if(u.hasClass(i)){e=l.normalizeToArray(e),n=l.normalizeToArray(n);var r=l.mappingToExists(n,e);t[i]=f(r,function(t){return t.option&&t.exist?d(t.exist,t.option,!0):t.exist||t.option})}else t[i]=d(n,e,!0)}})}var s=t(ie),l=t("../util/model"),u=t("./Component"),c=s.each,h=s.clone,f=s.map,d=s.merge,p=/^(min|max)?(.+)$/;return e[K]={constructor:e,setOption:function(t,e){t=h(t,!0);var n=this._optionBackup,r=i.call(this,t,e,!n);this._newBaseOption=r.baseOption,n?(o(n.baseOption,r.baseOption),r.timelineOptions[G]&&(n.timelineOptions=r.timelineOptions),r.mediaList[G]&&(n.mediaList=r.mediaList),r.mediaDefault&&(n.mediaDefault=r.mediaDefault)):this._optionBackup=r},mountOption:function(t){var e=this._optionBackup;return this._timelineOptions=f(e.timelineOptions,h),this._mediaList=f(e.mediaList,h),this._mediaDefault=h(e.mediaDefault),this._currentMediaIndices=[],h(t?e.baseOption:this._newBaseOption)},getTimelineOption:function(t){var e,i=this._timelineOptions;if(i[G]){var n=t.getComponent("timeline");n&&(e=h(i[n.getCurrentIndex()],!0))}return e},getMediaOption:function(){var t=this._api[X](),e=this._api[Z](),i=this._mediaList,r=this._mediaDefault,o=[],s=[];if(!i[G]&&!r)return s;for(var l=0,u=i[G];u>l;l++)n(i[l].query,t,e)&&o.push(l);return!o[G]&&r&&(o=[-1]),o[G]&&!a(o,this._currentMediaIndices)&&(s=f(o,function(t){return h(-1===t?r.option:i[t].option)})),this._currentMediaIndices=o,s}},e}),e("echarts/model/Component",[ne,"./Model",ie,"../util/component","../util/clazz","../util/layout","./mixin/boxLayout"],function(t){function e(t){var e=[];return n.each(u.getClassesByMainType(t),function(t){r.apply(e,t[K].dependencies||[])}),n.map(e,function(t){return o.parseClassType(t).main})}var i=t("./Model"),n=t(ie),r=Array[K].push,a=t("../util/component"),o=t("../util/clazz"),l=t("../util/layout"),u=i[I]({type:"component",id:"",name:"",mainType:"",subType:"",componentIndex:0,defaultOption:null,ecModel:null,dependentModels:[],uid:null,layoutMode:null,$constructor:function(t,e,n,r){i.call(this,t,e,n,r),this.uid=a.getUID("componentModel")},init:function(t,e,i){this.mergeDefaultAndTheme(t,i)},mergeDefaultAndTheme:function(t,e){var i=this.layoutMode,r=i?l.getLayoutParams(t):{},a=e.getTheme();n.merge(t,a.get(this.mainType)),n.merge(t,this.getDefaultOption()),i&&l.mergeLayoutParam(t,r,i)},mergeOption:function(t){n.merge(this.option,t,!0);var e=this.layoutMode;e&&l.mergeLayoutParam(this.option,t,e)},optionUpdated:function(){},getDefaultOption:function(){if(!this.hasOwnProperty("__defaultOption")){for(var t=[],e=this.constructor;e;){var i=e[K].defaultOption;i&&t.push(i),e=e.superClass}for(var r={},a=t[G]-1;a>=0;a--)r=n.merge(r,t[a],!0);this.__defaultOption=r}return this.__defaultOption},getReferringComponents:function(t){return this[s].queryComponents({mainType:t,index:this.get(t+"Index",!0),id:this.get(t+"Id",!0)})}});return o.enableClassManagement(u,{registerWhenExtend:!0}),a.enableSubTypeDefaulter(u),a.enableTopologicalTravel(u,e),n.mixin(u,t("./mixin/boxLayout")),u}),e("echarts/model/Series",[ne,ie,"../util/format","../util/model","./Component","./mixin/colorPalette","zrender/core/env"],function(t){var e=t(ie),i=t("../util/format"),n=t("../util/model"),r=t("./Component"),a=t("./mixin/colorPalette"),o=t("zrender/core/env"),l=i.encodeHTML,u=i.addCommas,c=r[I]({type:"series.__base__",seriesIndex:0,coordinateSystem:null,defaultOption:null,legendDataProvider:null,visualColorAccessPath:"itemStyle.normal.color",init:function(t,e,i){this.seriesIndex=this.componentIndex,this.mergeDefaultAndTheme(t,i),this._dataBeforeProcessed=this.getInitialData(t,i),this._data=this._dataBeforeProcessed.cloneShallow()},mergeDefaultAndTheme:function(t,i){e.merge(t,i.getTheme().get(this.subType)),e.merge(t,this.getDefaultOption()),n.defaultEmphasis(t.label,n.LABEL_OPTIONS),this.fillDataTextStyle(t.data)},mergeOption:function(t,i){t=e.merge(this.option,t,!0),this.fillDataTextStyle(t.data);var n=this.getInitialData(t,i);n&&(this._data=n,this._dataBeforeProcessed=n.cloneShallow())},fillDataTextStyle:function(t){if(t)for(var e=0;e<t[G];e++)t[e]&&t[e].label&&n.defaultEmphasis(t[e].label,n.LABEL_OPTIONS)},getInitialData:function(){},getData:function(t){return null==t?this._data:this._data.getLinkedData(t)},setData:function(t){this._data=t},getRawData:function(){return this._dataBeforeProcessed},coordDimToDataDim:function(t){return[t]},dataDimToCoordDim:function(t){return t},getBaseAxis:function(){var t=this[te];return t&&t.getBaseAxis&&t.getBaseAxis()},formatTooltip:function(t,n){function r(t){var r=[];return e.each(t,function(t,e){var o,s=a.getDimensionInfo(e),l=s&&s.type;o=l===g?t+"":"time"===l?n?"":i.formatTime("yyyy/mm/dd hh:mm:ss",t):u(t),o&&r.push(o)}),r.join(", ")}var a=this._data,o=this.getRawValue(t),s=e[P](o)?r(o):u(o),c=a.getName(t),h=a[R](t,"color"),f='<span style="display:inline-block;margin-right:5px;border-radius:10px;width:9px;height:9px;background-color:'+h+'"></span>',d=this.name;return"\x00-"===d&&(d=""),n?f+l(this.name)+" : "+s:(d&&l(d)+"<br />")+f+(c?l(c)+" : "+s:s)},ifEnableAnimation:function(){if(o.node)return!1;var t=this[v](j);return t&&this[N]().count()>this[v]("animationThreshold")&&(t=!1),t},restoreData:function(){this._data=this._dataBeforeProcessed.cloneShallow()},getColorFromPalette:function(t,e){var i=this[s],n=a.getColorFromPalette.call(this,t,e);return n||(n=i.getColorFromPalette(t,e)),n},getAxisTooltipDataIndex:null,getTooltipPosition:null});return e.mixin(c,n.dataFormatMixin),e.mixin(c,a),c}),e("echarts/view/Component",[ne,"zrender/container/Group","../util/component","../util/clazz"],function(t){var e=t("zrender/container/Group"),i=t("../util/component"),n=t("../util/clazz"),r=function(){this.group=new e,this.uid=i.getUID("viewComponent")};r[K]={constructor:r,init:function(){},render:function(){},dispose:function(){}};var a=r[K];return a.updateView=a.updateLayout=a.updateVisual=function(){},n.enableClassExtend(r),n.enableClassManagement(r,{registerWhenExtend:!0}),r}),e("echarts/view/Chart",[ne,"zrender/container/Group","../util/component","../util/clazz","../util/model",ie],function(t){function e(){this.group=new r,this.uid=a.getUID("viewChart")}function i(t,e){if(t&&(t.trigger(e),"group"===t.type))for(var n=0;n<t.childCount();n++)i(t.childAt(n),e)}function n(t,e,n){var r=s.queryDataIndex(t,e);null!=r?l.each(s.normalizeToArray(r),function(e){i(t.getItemGraphicEl(e),n)}):t.eachItemGraphicEl(function(t){i(t,n)})}var r=t("zrender/container/Group"),a=t("../util/component"),o=t("../util/clazz"),s=t("../util/model"),l=t(ie);e[K]={type:"chart",init:function(){},render:function(){},highlight:function(t,e,i,r){n(t[N](),r,"emphasis")},downplay:function(t,e,i,r){n(t[N](),r,"normal")},remove:function(){this.group[ee]()},dispose:function(){}};var u=e[K];return u.updateView=u.updateLayout=u.updateVisual=function(t,e,i,n){this.render(t,e,i,n)},o.enableClassExtend(e,["dispose"]),o.enableClassManagement(e,{registerWhenExtend:!0}),e}),e("echarts/util/model",[ne,"./format","./number","../model/Model",ie],function(t){function e(t,e){return t&&t.hasOwnProperty(e)}var i=t("./format"),n=t("./number"),r=t("../model/Model"),a=t(ie),o={};return o.normalizeToArray=function(t){return t instanceof Array?t:null==t?[]:[t]},o.defaultEmphasis=function(t,e){if(t){var i=t.emphasis=t.emphasis||{},n=t.normal=t.normal||{};a.each(e,function(t){var e=a[l](i[t],n[t]);null!=e&&(i[t]=e)})}},o.LABEL_OPTIONS=[y,"show",f,"distance","formatter"],o.getDataItemValue=function(t){return t&&(null==t.value?t:t.value)},o.isDataItemOption=function(t){return a[D](t)&&!(t instanceof Array)},o.converDataValue=function(t,e){var i=e&&e.type;return i===g?t:("time"!==i||isFinite(t)||null==t||"-"===t||(t=+n.parseDate(t)),null==t||""===t?0/0:+t)},o.createDataFormatModel=function(t,e){var i=new r;return a.mixin(i,o.dataFormatMixin),i.seriesIndex=e.seriesIndex,i.name=e.name||"",i.mainType=e.mainType,i.subType=e.subType,i[N]=function(){return t},i},o.dataFormatMixin={getDataParams:function(t,e){var i=this[N](e),n=this.seriesIndex,r=this.name,a=this.getRawValue(t,e),o=i.getRawIndex(t),s=i.getName(t,!0),l=i.getRawDataItem(t);return{componentType:this.mainType,componentSubType:this.subType,seriesType:"series"===this.mainType?this.subType:null,seriesIndex:n,seriesName:r,name:s,dataIndex:o,data:l,dataType:e,value:a,color:i[R](t,"color"),$vars:["seriesName","name","value"]}},getFormattedLabel:function(t,e,n,r){e=e||"normal";var a=this[N](n),o=a[m](t),s=this.getDataParams(t,n);null!=r&&s.value instanceof Array&&(s.value=s.value[r]);var l=o.get(["label",e,"formatter"]);return typeof l===C?(s.status=e,l(s)):typeof l===Q?i.formatTpl(l,s):void 0},getRawValue:function(t,e){var i=this[N](e),n=i.getRawDataItem(t);return null!=n?!a[D](n)||n instanceof Array?n:n.value:void 0},formatTooltip:a.noop},o.mappingToExists=function(t,e){e=(e||[]).slice();var i=a.map(t||[],function(t){return{exist:t}});return a.each(e,function(t,n){if(a[D](t)){for(var r=0;r<i[G];r++)if(!i[r].option&&null!=t.id&&i[r].exist.id===t.id+"")return i[r].option=t,void(e[n]=null);for(var r=0;r<i[G];r++){var s=i[r].exist;if(!(i[r].option||null!=s.id&&null!=t.id||null==t.name||o.isIdInner(t)||o.isIdInner(s)||s.name!==t.name+""))return i[r].option=t,void(e[n]=null)}}}),a.each(e,function(t){if(a[D](t)){for(var e=0;e<i[G];e++){var n=i[e].exist;if(!i[e].option&&!o.isIdInner(n)&&null==t.id){i[e].option=t;break}}e>=i[G]&&i.push({option:t})}}),i},o.isIdInner=function(t){return a[D](t)&&t.id&&0===(t.id+"")[F]("\x00_ec_\x00")},o.compressBatches=function(t,e){function i(t,e,i){for(var n=0,r=t[G];r>n;n++)for(var a=t[n].seriesId,s=o.normalizeToArray(t[n][B]),l=i&&i[a],u=0,c=s[G];c>u;u++){var h=s[u];l&&l[h]?l[h]=null:(e[a]||(e[a]={}))[h]=1}}function n(t,e){var i=[];for(var r in t)if(t.hasOwnProperty(r)&&null!=t[r])if(e)i.push(+r);else{var a=n(t[r],!0);a[G]&&i.push({seriesId:r,dataIndex:a})}return i}var r={},a={};return i(t||[],r),i(e||[],a,r),[n(r),n(a)]},o.queryDataIndex=function(t,e){return null!=e.dataIndexInside?e.dataIndexInside:null!=e[B]?a[P](e[B])?a.map(e[B],function(e){return t.indexOfRawIndex(e)}):t.indexOfRawIndex(e[B]):null!=e.name?a[P](e.name)?a.map(e.name,function(e){return t.indexOfName(e)}):t.indexOfName(e.name):void 0},o.parseFinder=function(t,i,n){if(a.isString(i)){var r={};r[i+"Index"]=0,i=r}var o=n&&n.defaultMainType;!o||e(i,o+"Index")||e(i,o+"Id")||e(i,o+"Name")||(i[o+"Index"]=0);var s={};return a.each(i,function(e,n){var e=i[n];if(n===B||"dataIndexInside"===n)return void(s[n]=e);var r=n.match(/^(\w+)(Index|Id|Name)$/)||[],a=r[1],o=r[2];if(a&&o){var l={mainType:a};l[o[J]()]=e;var u=t.queryComponents(l);s[a+"Models"]=u,s[a+"Model"]=u[0]}}),s},o}),e("zrender/zrender",[ne,"./core/guid","./core/env","./Handler","./Storage","./animation/Animation","./dom/HandlerProxy","./Painter"],function(t){function e(t){delete c[t]}var i=t("./core/guid"),n=t("./core/env"),r=t("./Handler"),a=t("./Storage"),o=t("./animation/Animation"),s=t("./dom/HandlerProxy"),l=!n[W],u={canvas:t("./Painter")},c={},h={};h.version="3.2.1",h.init=function(t,e){var n=new f(i(),t,e);return c[n.id]=n,n},h.dispose=function(t){if(t)t.dispose();else{for(var e in c)c.hasOwnProperty(e)&&c[e].dispose();c={}}return h},h.getInstance=function(t){return c[t]},h.registerPainter=function(t,e){u[t]=e};var f=function(t,e,i){i=i||{},this.dom=e,this.id=t;var c=this,h=new a,f=i.renderer;if(l){if(!u.vml)throw new Error("You need to require 'zrender/vml/vml' to support IE8");f="vml"}else f&&u[f]||(f="canvas");var d=new u[f](e,h,i);this[q]=h,this.painter=d;var p=n.node?null:new s(d.getViewportRoot());this.handler=new r(h,d,p,d.root),this[j]=new o({stage:{update:function(){c._needsRefresh&&c.refreshImmediately(),c._needsRefreshHover&&c.refreshHoverImmediately()}}}),this[j].start(),this._needsRefresh;var v=h.delFromMap,m=h.addToMap;h.delFromMap=function(t){var e=h.get(t);v.call(h,t),e&&e.removeSelfFromZr(c)},h.addToMap=function(t){m.call(h,t),t.addSelfToZr(c)}};return f[K]={constructor:f,getId:function(){return this.id},add:function(t){this[q].addRoot(t),this._needsRefresh=!0},remove:function(t){this[q].delRoot(t),this._needsRefresh=!0},configLayer:function(t,e){this.painter.configLayer(t,e),this._needsRefresh=!0},refreshImmediately:function(){this._needsRefresh=!1,this.painter.refresh(),this._needsRefresh=!1},refresh:function(){this._needsRefresh=!0},addHover:function(t,e){this.painter.addHover&&(this.painter.addHover(t,e),this.refreshHover())},removeHover:function(t){this.painter.removeHover&&(this.painter.removeHover(t),this.refreshHover())},clearHover:function(){this.painter.clearHover&&(this.painter.clearHover(),this.refreshHover())},refreshHover:function(){this._needsRefreshHover=!0},refreshHoverImmediately:function(){this._needsRefreshHover=!1,this.painter.refreshHover&&this.painter.refreshHover()},resize:function(t){t=t||{},this.painter[Y](t.width,t[$]),this.handler[Y]()},clearAnimation:function(){this[j].clear()},getWidth:function(){return this.painter[X]()},getHeight:function(){return this.painter[Z]()},pathToImage:function(t,e,n){var r=i();return this.painter.pathToImage(r,t,e,n)},setCursorStyle:function(t){this.handler.setCursorStyle(t)},on:function(t,e,i){this.handler.on(t,e,i)},off:function(t,e){this.handler.off(t,e)},trigger:function(t,e){this.handler.trigger(t,e)},clear:function(){this[q].delRoot(),this.painter.clear()},dispose:function(){this[j].stop(),this.clear(),this[q].dispose(),this.painter.dispose(),this.handler.dispose(),this[j]=this[q]=this.painter=this.handler=null,e(this.id)}},h}),e("zrender/tool/color",[ne],function(){function t(t){return t=Math.round(t),0>t?0:t>255?255:t}function e(t){return t=Math.round(t),0>t?0:t>360?360:t}function i(t){return 0>t?0:t>1?1:t}function n(e){return t(e[G]&&"%"===e.charAt(e[G]-1)?parseFloat(e)/100*255:parseInt(e,10))}function r(t){return i(t[G]&&"%"===t.charAt(t[G]-1)?parseFloat(t)/100:parseFloat(t))}function a(t,e,i){return 0>i?i+=1:i>1&&(i-=1),1>6*i?t+(e-t)*i*6:1>2*i?e:2>3*i?t+(e-t)*(2/3-i)*6:t}function o(t,e,i){return t+(e-t)*i}function s(t){if(t){t+="";var e=t[A](/ /g,"")[J]();if(e in g)return g[e].slice();if("#"!==e.charAt(0)){var i=e[F]("("),a=e[F](")");if(-1!==i&&a+1===e[G]){var o=e.substr(0,i),s=e.substr(i+1,a-(i+1)).split(","),u=1;switch(o){case"rgba":if(4!==s[G])return;u=r(s.pop());case"rgb":if(3!==s[G])return;return[n(s[0]),n(s[1]),n(s[2]),u];case"hsla":if(4!==s[G])return;return s[3]=r(s[3]),l(s);case"hsl":if(3!==s[G])return;return l(s);default:return}}}else{if(4===e[G]){var c=parseInt(e.substr(1),16);if(!(c>=0&&4095>=c))return;return[(3840&c)>>4|(3840&c)>>8,240&c|(240&c)>>4,15&c|(15&c)<<4,1]}if(7===e[G]){var c=parseInt(e.substr(1),16);if(!(c>=0&&16777215>=c))return;return[(16711680&c)>>16,(65280&c)>>8,255&c,1]}}}}function l(e){var i=(parseFloat(e[0])%360+360)%360/360,n=r(e[1]),o=r(e[2]),s=.5>=o?o*(n+1):o+n-o*n,l=2*o-s,u=[t(255*a(l,s,i+1/3)),t(255*a(l,s,i)),t(255*a(l,s,i-1/3))];return 4===e[G]&&(u[3]=e[3]),u}function u(t){if(t){var e,i,n=t[0]/255,r=t[1]/255,a=t[2]/255,o=Math.min(n,r,a),s=Math.max(n,r,a),l=s-o,u=(s+o)/2;if(0===l)e=0,i=0;else{i=.5>u?l/(s+o):l/(2-s-o);var c=((s-n)/6+l/2)/l,h=((s-r)/6+l/2)/l,f=((s-a)/6+l/2)/l;n===s?e=f-h:r===s?e=1/3+c-f:a===s&&(e=2/3+h-c),0>e&&(e+=1),e>1&&(e-=1)}var d=[360*e,i,u];return null!=t[3]&&d.push(t[3]),d}}function c(t,e){var i=s(t);if(i){for(var n=0;3>n;n++)i[n]=0>e?i[n]*(1-e)|0:(255-i[n])*e+i[n]|0;return m(i,4===i[G]?"rgba":"rgb")}}function h(t){var e=s(t);return e?((1<<24)+(e[0]<<16)+(e[1]<<8)+ +e[2]).toString(16).slice(1):void 0}function f(e,i,n){if(i&&i[G]&&e>=0&&1>=e){n=n||[0,0,0,0];var r=e*(i[G]-1),a=Math.floor(r),s=Math.ceil(r),l=i[a],u=i[s],c=r-a;return n[0]=t(o(l[0],u[0],c)),n[1]=t(o(l[1],u[1],c)),n[2]=t(o(l[2],u[2],c)),n[3]=t(o(l[3],u[3],c)),n}}function d(e,n,r){if(n&&n[G]&&e>=0&&1>=e){var a=e*(n[G]-1),l=Math.floor(a),u=Math.ceil(a),c=s(n[l]),h=s(n[u]),f=a-l,d=m([t(o(c[0],h[0],f)),t(o(c[1],h[1],f)),t(o(c[2],h[2],f)),i(o(c[3],h[3],f))],"rgba");return r?{color:d,leftIndex:l,rightIndex:u,value:a}:d}}function p(t,i,n,a){return t=s(t),t?(t=u(t),null!=i&&(t[0]=e(i)),null!=n&&(t[1]=r(n)),null!=a&&(t[2]=r(a)),m(l(t),"rgba")):void 0}function v(t,e){return t=s(t),t&&null!=e?(t[3]=i(e),m(t,"rgba")):void 0}function m(t,e){var i=t[0]+","+t[1]+","+t[2];return("rgba"===e||"hsva"===e||"hsla"===e)&&(i+=","+t[3]),e+"("+i+")"}var g={transparent:[0,0,0,0],aliceblue:[240,248,255,1],antiquewhite:[250,235,215,1],aqua:[0,255,255,1],aquamarine:[127,255,212,1],azure:[240,255,255,1],beige:[245,245,220,1],bisque:[255,228,196,1],black:[0,0,0,1],blanchedalmond:[255,235,205,1],blue:[0,0,255,1],blueviolet:[138,43,226,1],brown:[165,42,42,1],burlywood:[222,184,135,1],cadetblue:[95,158,160,1],chartreuse:[127,255,0,1],chocolate:[210,105,30,1],coral:[255,127,80,1],cornflowerblue:[100,149,237,1],cornsilk:[255,248,220,1],crimson:[220,20,60,1],cyan:[0,255,255,1],darkblue:[0,0,139,1],darkcyan:[0,139,139,1],darkgoldenrod:[184,134,11,1],darkgray:[169,169,169,1],darkgreen:[0,100,0,1],darkgrey:[169,169,169,1],darkkhaki:[189,183,107,1],darkmagenta:[139,0,139,1],darkolivegreen:[85,107,47,1],darkorange:[255,140,0,1],darkorchid:[153,50,204,1],darkred:[139,0,0,1],darksalmon:[233,150,122,1],darkseagreen:[143,188,143,1],darkslateblue:[72,61,139,1],darkslategray:[47,79,79,1],darkslategrey:[47,79,79,1],darkturquoise:[0,206,209,1],darkviolet:[148,0,211,1],deeppink:[255,20,147,1],deepskyblue:[0,191,255,1],dimgray:[105,105,105,1],dimgrey:[105,105,105,1],dodgerblue:[30,144,255,1],firebrick:[178,34,34,1],floralwhite:[255,250,240,1],forestgreen:[34,139,34,1],fuchsia:[255,0,255,1],gainsboro:[220,220,220,1],ghostwhite:[248,248,255,1],gold:[255,215,0,1],goldenrod:[218,165,32,1],gray:[128,128,128,1],green:[0,128,0,1],greenyellow:[173,255,47,1],grey:[128,128,128,1],honeydew:[240,255,240,1],hotpink:[255,105,180,1],indianred:[205,92,92,1],indigo:[75,0,130,1],ivory:[255,255,240,1],khaki:[240,230,140,1],lavender:[230,230,250,1],lavenderblush:[255,240,245,1],lawngreen:[124,252,0,1],lemonchiffon:[255,250,205,1],lightblue:[173,216,230,1],lightcoral:[240,128,128,1],lightcyan:[224,255,255,1],lightgoldenrodyellow:[250,250,210,1],lightgray:[211,211,211,1],lightgreen:[144,238,144,1],lightgrey:[211,211,211,1],lightpink:[255,182,193,1],lightsalmon:[255,160,122,1],lightseagreen:[32,178,170,1],lightskyblue:[135,206,250,1],lightslategray:[119,136,153,1],lightslategrey:[119,136,153,1],lightsteelblue:[176,196,222,1],lightyellow:[255,255,224,1],lime:[0,255,0,1],limegreen:[50,205,50,1],linen:[250,240,230,1],magenta:[255,0,255,1],maroon:[128,0,0,1],mediumaquamarine:[102,205,170,1],mediumblue:[0,0,205,1],mediumorchid:[186,85,211,1],mediumpurple:[147,112,219,1],mediumseagreen:[60,179,113,1],mediumslateblue:[123,104,238,1],mediumspringgreen:[0,250,154,1],mediumturquoise:[72,209,204,1],mediumvioletred:[199,21,133,1],midnightblue:[25,25,112,1],mintcream:[245,255,250,1],mistyrose:[255,228,225,1],moccasin:[255,228,181,1],navajowhite:[255,222,173,1],navy:[0,0,128,1],oldlace:[253,245,230,1],olive:[128,128,0,1],olivedrab:[107,142,35,1],orange:[255,165,0,1],orangered:[255,69,0,1],orchid:[218,112,214,1],palegoldenrod:[238,232,170,1],palegreen:[152,251,152,1],paleturquoise:[175,238,238,1],palevioletred:[219,112,147,1],papayawhip:[255,239,213,1],peachpuff:[255,218,185,1],peru:[205,133,63,1],pink:[255,192,203,1],plum:[221,160,221,1],powderblue:[176,224,230,1],purple:[128,0,128,1],red:[255,0,0,1],rosybrown:[188,143,143,1],royalblue:[65,105,225,1],saddlebrown:[139,69,19,1],salmon:[250,128,114,1],sandybrown:[244,164,96,1],seagreen:[46,139,87,1],seashell:[255,245,238,1],sienna:[160,82,45,1],silver:[192,192,192,1],skyblue:[135,206,235,1],slateblue:[106,90,205,1],slategray:[112,128,144,1],slategrey:[112,128,144,1],snow:[255,250,250,1],springgreen:[0,255,127,1],steelblue:[70,130,180,1],tan:[210,180,140,1],teal:[0,128,128,1],thistle:[216,191,216,1],tomato:[255,99,71,1],turquoise:[64,224,208,1],violet:[238,130,238,1],wheat:[245,222,179,1],white:[255,255,255,1],whitesmoke:[245,245,245,1],yellow:[255,255,0,1],yellowgreen:[154,205,50,1]};return{parse:s,lift:c,toHex:h,fastMapToColor:f,mapToColor:d,modifyHSL:p,modifyAlpha:v,stringify:m}}),e("zrender/mixin/Eventful",[ne],function(){var t=Array[K].slice,e=function(){this._$handlers={}};return e[K]={constructor:e,one:function(t,e,i){var n=this._$handlers;if(!e||!t)return this;n[t]||(n[t]=[]);for(var r=0;r<n[t][G];r++)if(n[t][r].h===e)return this;return n[t].push({h:e,one:!0,ctx:i||this}),this},on:function(t,e,i){var n=this._$handlers;if(!e||!t)return this;n[t]||(n[t]=[]);for(var r=0;r<n[t][G];r++)if(n[t][r].h===e)return this;return n[t].push({h:e,one:!1,ctx:i||this}),this},isSilent:function(t){var e=this._$handlers;return e[t]&&e[t][G]},off:function(t,e){var i=this._$handlers;if(!t)return this._$handlers={},this;if(e){if(i[t]){for(var n=[],r=0,a=i[t][G];a>r;r++)i[t][r].h!=e&&n.push(i[t][r]);i[t]=n}i[t]&&0===i[t][G]&&delete i[t]}else delete i[t];return this},trigger:function(e){if(this._$handlers[e]){var i=arguments,n=i[G];n>3&&(i=t.call(i,1));for(var r=this._$handlers[e],a=r[G],o=0;a>o;){switch(n){case 1:r[o].h.call(r[o].ctx);break;case 2:r[o].h.call(r[o].ctx,i[1]);break;case 3:r[o].h.call(r[o].ctx,i[1],i[2]);break;default:r[o].h.apply(r[o].ctx,i)}r[o].one?(r[k](o,1),a--):o++}}return this},triggerWithContext:function(e){if(this._$handlers[e]){var i=arguments,n=i[G];
n>4&&(i=t.call(i,1,i[G]-1));for(var r=i[i[G]-1],a=this._$handlers[e],o=a[G],s=0;o>s;){switch(n){case 1:a[s].h.call(r);break;case 2:a[s].h.call(r,i[1]);break;case 3:a[s].h.call(r,i[1],i[2]);break;default:a[s].h.apply(r,i)}a[s].one?(a[k](s,1),o--):s++}}return this}},e}),e("zrender/core/timsort",[],function(){function t(t){for(var e=0;t>=l;)e|=1&t,t>>=1;return t+e}function e(t,e,n,r){var a=e+1;if(a===n)return 1;if(r(t[a++],t[e])<0){for(;n>a&&r(t[a],t[a-1])<0;)a++;i(t,e,a)}else for(;n>a&&r(t[a],t[a-1])>=0;)a++;return a-e}function i(t,e,i){for(i--;i>e;){var n=t[e];t[e++]=t[i],t[i--]=n}}function n(t,e,i,n,r){for(n===e&&n++;i>n;n++){for(var a,o=t[n],s=e,l=n;l>s;)a=s+l>>>1,r(o,t[a])<0?l=a:s=a+1;var u=n-s;switch(u){case 3:t[s+3]=t[s+2];case 2:t[s+2]=t[s+1];case 1:t[s+1]=t[s];break;default:for(;u>0;)t[s+u]=t[s+u-1],u--}t[s]=o}}function r(t,e,i,n,r,a){var o=0,s=0,l=1;if(a(t,e[i+r])>0){for(s=n-r;s>l&&a(t,e[i+r+l])>0;)o=l,l=(l<<1)+1,0>=l&&(l=s);l>s&&(l=s),o+=r,l+=r}else{for(s=r+1;s>l&&a(t,e[i+r-l])<=0;)o=l,l=(l<<1)+1,0>=l&&(l=s);l>s&&(l=s);var u=o;o=r-l,l=r-u}for(o++;l>o;){var c=o+(l-o>>>1);a(t,e[i+c])>0?o=c+1:l=c}return l}function a(t,e,i,n,r,a){var o=0,s=0,l=1;if(a(t,e[i+r])<0){for(s=r+1;s>l&&a(t,e[i+r-l])<0;)o=l,l=(l<<1)+1,0>=l&&(l=s);l>s&&(l=s);var u=o;o=r-l,l=r-u}else{for(s=n-r;s>l&&a(t,e[i+r+l])>=0;)o=l,l=(l<<1)+1,0>=l&&(l=s);l>s&&(l=s),o+=r,l+=r}for(o++;l>o;){var c=o+(l-o>>>1);a(t,e[i+c])<0?l=c:o=c+1}return l}function o(t,e){function i(t,e){f[y]=t,d[y]=e,y+=1}function n(){for(;y>1;){var t=y-2;if(t>=1&&d[t-1]<=d[t]+d[t+1]||t>=2&&d[t-2]<=d[t]+d[t-1])d[t-1]<d[t+1]&&t--;else if(d[t]>d[t+1])break;s(t)}}function o(){for(;y>1;){var t=y-2;t>0&&d[t-1]<d[t+1]&&t--,s(t)}}function s(i){var n=f[i],o=d[i],s=f[i+1],u=d[i+1];d[i]=o+u,i===y-3&&(f[i+1]=f[i+2],d[i+1]=d[i+2]),y--;var c=a(t[s],t,n,o,0,e);n+=c,o-=c,0!==o&&(u=r(t[n+o-1],t,s,u,u-1,e),0!==u&&(u>=o?l(n,o,s,u):h(n,o,s,u)))}function l(i,n,o,s){var l=0;for(l=0;n>l;l++)_[l]=t[i+l];var c=0,h=o,f=i;if(t[f++]=t[h++],0!==--s){if(1===n){for(l=0;s>l;l++)t[f+l]=t[h+l];return void(t[f+s]=_[c])}for(var d,v,m,g=p;;){d=0,v=0,m=!1;do if(e(t[h],_[c])<0){if(t[f++]=t[h++],v++,d=0,0===--s){m=!0;break}}else if(t[f++]=_[c++],d++,v=0,1===--n){m=!0;break}while(g>(d|v));if(m)break;do{if(d=a(t[h],_,c,n,0,e),0!==d){for(l=0;d>l;l++)t[f+l]=_[c+l];if(f+=d,c+=d,n-=d,1>=n){m=!0;break}}if(t[f++]=t[h++],0===--s){m=!0;break}if(v=r(_[c],t,h,s,0,e),0!==v){for(l=0;v>l;l++)t[f+l]=t[h+l];if(f+=v,h+=v,s-=v,0===s){m=!0;break}}if(t[f++]=_[c++],1===--n){m=!0;break}g--}while(d>=u||v>=u);if(m)break;0>g&&(g=0),g+=2}if(p=g,1>p&&(p=1),1===n){for(l=0;s>l;l++)t[f+l]=t[h+l];t[f+s]=_[c]}else{if(0===n)throw new Error;for(l=0;n>l;l++)t[f+l]=_[c+l]}}else for(l=0;n>l;l++)t[f+l]=_[c+l]}function h(i,n,o,s){var l=0;for(l=0;s>l;l++)_[l]=t[o+l];var c=i+n-1,h=s-1,f=o+s-1,d=0,v=0;if(t[f--]=t[c--],0!==--n){if(1===s){for(f-=n,c-=n,v=f+1,d=c+1,l=n-1;l>=0;l--)t[v+l]=t[d+l];return void(t[f]=_[h])}for(var m=p;;){var g=0,y=0,x=!1;do if(e(_[h],t[c])<0){if(t[f--]=t[c--],g++,y=0,0===--n){x=!0;break}}else if(t[f--]=_[h--],y++,g=0,1===--s){x=!0;break}while(m>(g|y));if(x)break;do{if(g=n-a(_[h],t,i,n,n-1,e),0!==g){for(f-=g,c-=g,n-=g,v=f+1,d=c+1,l=g-1;l>=0;l--)t[v+l]=t[d+l];if(0===n){x=!0;break}}if(t[f--]=_[h--],1===--s){x=!0;break}if(y=s-r(t[c],_,0,s,s-1,e),0!==y){for(f-=y,h-=y,s-=y,v=f+1,d=h+1,l=0;y>l;l++)t[v+l]=_[d+l];if(1>=s){x=!0;break}}if(t[f--]=t[c--],0===--n){x=!0;break}m--}while(g>=u||y>=u);if(x)break;0>m&&(m=0),m+=2}if(p=m,1>p&&(p=1),1===s){for(f-=n,c-=n,v=f+1,d=c+1,l=n-1;l>=0;l--)t[v+l]=t[d+l];t[f]=_[h]}else{if(0===s)throw new Error;for(d=f-(s-1),l=0;s>l;l++)t[d+l]=_[l]}}else for(d=f-(s-1),l=0;s>l;l++)t[d+l]=_[l]}var f,d,p=u,v=0,m=c,g=0,y=0;v=t[G],2*c>v&&(m=v>>>1);var _=[];g=120>v?5:1542>v?10:119151>v?19:40,f=[],d=[],this.mergeRuns=n,this.forceMergeRuns=o,this.pushRun=i}function s(i,r,a,s){a||(a=0),s||(s=i[G]);var u=s-a;if(!(2>u)){var c=0;if(l>u)return c=e(i,a,s,r),void n(i,a,s,a+c,r);var h=new o(i,r),f=t(u);do{if(c=e(i,a,s,r),f>c){var d=u;d>f&&(d=f),n(i,a,a+d,a+c,r),c=d}h.pushRun(a,c),h.mergeRuns(),u-=c,a+=c}while(0!==u);h.forceMergeRuns()}}var l=32,u=7,c=256;return s}),e("echarts/visual/seriesColor",[ne,"zrender/graphic/Gradient"],function(t){var e=t("zrender/graphic/Gradient");return function(t){function i(i){var n=(i.visualColorAccessPath||"itemStyle.normal.color").split("."),r=i[N](),a=i.get(n)||i.getColorFromPalette(i.get("name"));r.setVisual("color",a),t.isSeriesFiltered(i)||(typeof a!==C||a instanceof e||r.each(function(t){r.setItemVisual(t,"color",a(i.getDataParams(t)))}),r.each(function(t){var e=r[m](t),i=e.get(n,!0);null!=i&&r.setItemVisual(t,"color",i)}))}t.eachRawSeries(i)}}),e("echarts/preprocessor/backwardCompat",[ne,ie,"./helper/compatStyle"],function(t){function e(t,e){e=e.split(",");for(var i=t,n=0;n<e[G]&&(i=i&&i[e[n]],null!=i);n++);return i}function i(t,e,i,n){e=e.split(",");for(var r,a=t,o=0;o<e[G]-1;o++)r=e[o],null==a[r]&&(a[r]={}),a=a[r];(n||null==a[e[o]])&&(a[e[o]]=i)}function n(t){u(o,function(e){e[0]in t&&!(e[1]in t)&&(t[e[1]]=t[e[0]])})}var r=t(ie),a=t("./helper/compatStyle"),o=[["x","left"],["y","top"],["x2","right"],["y2",V]],s=["grid","geo","parallel","legend","toolbox","title","visualMap","dataZoom","timeline"],l=["bar","boxplot","candlestick","chord","effectScatter","funnel","gauge","lines","graph","heatmap","line","map","parallel","pie","radar","sankey","scatter","treemap"],u=r.each;return function(t){u(t.series,function(t){if(r[D](t)){var o=t.type;if(a(t),("pie"===o||"gauge"===o)&&null!=t.clockWise&&(t.clockwise=t.clockWise),"gauge"===o){var s=e(t,"pointer.color");null!=s&&i(t,"itemStyle.normal.color",s)}for(var u=0;u<l[G];u++)if(l[u]===t.type){n(t);break}}}),t.dataRange&&(t.visualMap=t.dataRange),u(s,function(e){var i=t[e];i&&(r[P](i)||(i=[i]),u(i,function(t){n(t)}))})}}),e("echarts/loading/default",[ne,"../util/graphic",ie],function(t){var e=t("../util/graphic"),i=t(ie),n=Math.PI;return function(t,r){r=r||{},i.defaults(r,{text:"loading",color:"#c23531",textColor:"#000",maskColor:"rgba(255, 255, 255, 0.8)",zlevel:0});var a=new e.Rect({style:{fill:r.maskColor},zlevel:r[L],z:1e4}),o=new e.Arc({shape:{startAngle:-n/2,endAngle:-n/2+.1,r:10},style:{stroke:r.color,lineCap:"round",lineWidth:5},zlevel:r[L],z:10001}),s=new e.Rect({style:{fill:"none",text:r.text,textPosition:"right",textDistance:10,textFill:r.textColor},zlevel:r[L],z:10001});o.animateShape(!0).when(1e3,{endAngle:3*n/2}).start("circularInOut"),o.animateShape(!0).when(1e3,{startAngle:3*n/2}).delay(300).start("circularInOut");var l=new e.Group;return l.add(o),l.add(s),l.add(a),l[Y]=function(){var e=t[X]()/2,i=t[Z]()/2;o.setShape({cx:e,cy:i});var n=o.shape.r;s.setShape({x:e-n,y:i-n,width:2*n,height:2*n}),a.setShape({x:0,y:0,width:t[X](),height:t[Z]()})},l[Y](),l}}),e("echarts/model/Model",[ne,ie,"../util/clazz","./mixin/lineStyle","./mixin/areaStyle","./mixin/textStyle","./mixin/itemStyle"],function(t){function e(t,e,i){this.parentModel=e,this[s]=i,this.option=t}var i=t(ie),n=t("../util/clazz");e[K]={constructor:e,init:null,mergeOption:function(t){i.merge(this.option,t,!0)},get:function(t,e){if(!t)return this.option;typeof t===Q&&(t=t.split("."));for(var i=this.option,n=this.parentModel,r=0;r<t[G]&&(!t[r]||(i=i&&"object"==typeof i?i[t[r]]:null,null!=i));r++);return null==i&&n&&!e&&(i=n.get(t)),i},getShallow:function(t,e){var i=this.option,n=null==i?i:i[t],r=this.parentModel;return null==n&&r&&!e&&(n=r[v](t)),n},getModel:function(t,i){var n=this.get(t,!0),r=this.parentModel,a=new e(n,i||r&&r[U](t),this[s]);return a},isEmpty:function(){return null==this.option},restoreData:function(){},clone:function(){var t=this.constructor;return new t(i.clone(this.option))},setReadOnly:function(t){n.setReadOnly(this,t)}},n.enableClassExtend(e);var r=i.mixin;return r(e,t("./mixin/lineStyle")),r(e,t("./mixin/areaStyle")),r(e,t("./mixin/textStyle")),r(e,t("./mixin/itemStyle")),e}),e("echarts/data/List",[ne,"../model/Model","./DataDiffer",ie,"../util/model"],function(t){function e(t){return f[P](t)||(t=[t]),t}function i(t,e){var i=t[_],n=new x(f.map(i,t.getDimensionInfo,t),t.hostModel);y(n,t);for(var r=n._storage={},a=t._storage,o=0;o<i[G];o++){var s=i[o],l=a[s];r[s]=f[F](e,s)>=0?new l.constructor(a[s][G]):a[s]}return n}var n=r,a=typeof window===r?global:window,o=typeof a.Float64Array===n?Array:a.Float64Array,l=typeof a.Int32Array===n?Array:a.Int32Array,u={"float":o,"int":l,ordinal:Array,number:Array,time:Array},c=t("../model/Model"),h=t("./DataDiffer"),f=t(ie),d=t("../util/model"),p=f[D],v=["stackedOn","hasItemOption","_nameList","_idList","_rawData"],y=function(t,e){f.each(v[b](e.__wrappedMethods||[]),function(i){e.hasOwnProperty(i)&&(t[i]=e[i])}),t.__wrappedMethods=e.__wrappedMethods},x=function(t,e){t=t||["x","y"];for(var i={},n=[],r=0;r<t[G];r++){var a,o={};typeof t[r]===Q?(a=t[r],o={name:a,stackable:!1,type:"number"}):(o=t[r],a=o.name,o.type=o.type||"number"),n.push(a),i[a]=o}this[_]=n,this._dimensionInfos=i,this.hostModel=e,this.dataType,this.indices=[],this._storage={},this._nameList=[],this._idList=[],this._optionModels=[],this.stackedOn=null,this._visual={},this._layout={},this._itemVisuals=[],this._itemLayouts=[],this._graphicEls=[],this._rawData,this._extent},w=x[K];w.type="list",w.hasItemOption=!0,w.getDimension=function(t){return isNaN(t)||(t=this[_][t]||t),t},w.getDimensionInfo=function(t){return f.clone(this._dimensionInfos[this.getDimension(t)])},w.initData=function(t,e,i){t=t||[],this._rawData=t;var n=this._storage={},r=this.indices=[],a=this[_],o=t[G],s=this._dimensionInfos,l=[],c={};e=e||[];for(var h=0;h<a[G];h++){var f=s[a[h]],p=u[f.type];n[a[h]]=new p(o)}var v=this;i||(v.hasItemOption=!1),i=i||function(t,e,i,n){var r=d.getDataItemValue(t);return d.isDataItemOption(t)&&(v.hasItemOption=!0),d.converDataValue(r instanceof Array?r[n]:r,s[e])};for(var m=0;m<t[G];m++){for(var g=t[m],y=0;y<a[G];y++){var x=a[y],b=n[x];b[m]=i(g,x,m,y)}r.push(m)}for(var h=0;h<t[G];h++){e[h]||t[h]&&null!=t[h].name&&(e[h]=t[h].name);var w=e[h]||"",M=t[h]&&t[h].id;!M&&w&&(c[w]=c[w]||0,M=w,c[w]>0&&(M+="__ec__"+c[w]),c[w]++),M&&(l[h]=M)}this._nameList=e,this._idList=l},w.count=function(){return this.indices[G]},w.get=function(t,e,i){var n=this._storage,r=this.indices[e];if(null==r)return 0/0;var a=n[t]&&n[t][r];if(i){var o=this._dimensionInfos[t];if(o&&o.stackable)for(var s=this.stackedOn;s;){var l=s.get(t,e);(a>=0&&l>0||0>=a&&0>l)&&(a+=l),s=s.stackedOn}}return a},w.getValues=function(t,e,i){var n=[];f[P](t)||(i=e,e=t,t=this[_]);for(var r=0,a=t[G];a>r;r++)n.push(this.get(t[r],e,i));return n},w.hasValue=function(t){for(var e=this[_],i=this._dimensionInfos,n=0,r=e[G];r>n;n++)if(i[e[n]].type!==g&&isNaN(this.get(e[n],t)))return!1;return!0},w.getDataExtent=function(t,e){t=this.getDimension(t);var i=this._storage[t],n=this.getDimensionInfo(t);e=n&&n.stackable&&e;var r,a=(this._extent||(this._extent={}))[t+!!e];if(a)return a;if(i){for(var o=1/0,s=-1/0,l=0,u=this.count();u>l;l++)r=this.get(t,l,e),o>r&&(o=r),r>s&&(s=r);return this._extent[t+!!e]=[o,s]}return[1/0,-1/0]},w.getSum=function(t,e){var i=this._storage[t],n=0;if(i)for(var r=0,a=this.count();a>r;r++){var o=this.get(t,r,e);isNaN(o)||(n+=o)}return n},w[F]=function(t,e){var i=this._storage,n=i[t],r=this.indices;if(n)for(var a=0,o=r[G];o>a;a++){var s=r[a];if(n[s]===e)return a}return-1},w.indexOfName=function(t){for(var e=this.indices,i=this._nameList,n=0,r=e[G];r>n;n++){var a=e[n];if(i[a]===t)return n}return-1},w.indexOfRawIndex=function(t){var e=this.indices,i=e[t];if(null!=i&&i===t)return t;for(var n=0,r=e[G]-1;r>=n;){var a=(n+r)/2|0;if(e[a]<t)n=a+1;else{if(!(e[a]>t))return a;r=a-1}}return-1},w.indexOfNearest=function(t,e,i,n){var r=this._storage,a=r[t];null==n&&(n=1/0);var o=-1;if(a)for(var s=Number.MAX_VALUE,l=0,u=this.count();u>l;l++){var c=e-this.get(t,l,i),h=Math.abs(c);n>=c&&(s>h||h===s&&c>0)&&(s=h,o=l)}return o},w.getRawIndex=function(t){var e=this.indices[t];return null==e?-1:e},w.getRawDataItem=function(t){return this._rawData[this.getRawIndex(t)]},w.getName=function(t){return this._nameList[this.indices[t]]||""},w.getId=function(t){return this._idList[this.indices[t]]||this.getRawIndex(t)+""},w.each=function(t,i,n,r){typeof t===C&&(r=n,n=i,i=t,t=[]),t=f.map(e(t),this.getDimension,this);var a=[],o=t[G],s=this.indices;r=r||this;for(var l=0;l<s[G];l++)switch(o){case 0:i.call(r,l);break;case 1:i.call(r,this.get(t[0],l,n),l);break;case 2:i.call(r,this.get(t[0],l,n),this.get(t[1],l,n),l);break;default:for(var u=0;o>u;u++)a[u]=this.get(t[u],l,n);a[u]=l,i.apply(r,a)}},w.filterSelf=function(t,i,n,r){typeof t===C&&(r=n,n=i,i=t,t=[]),t=f.map(e(t),this.getDimension,this);var a=[],o=[],s=t[G],l=this.indices;r=r||this;for(var u=0;u<l[G];u++){var c;if(1===s)c=i.call(r,this.get(t[0],u,n),u);else{for(var h=0;s>h;h++)o[h]=this.get(t[h],u,n);o[h]=u,c=i.apply(r,o)}c&&a.push(l[u])}return this.indices=a,this._extent={},this},w.mapArray=function(t,e,i,n){typeof t===C&&(n=i,i=e,e=t,t=[]);var r=[];return this.each(t,function(){r.push(e&&e.apply(this,arguments))},i,n),r},w.map=function(t,n,r,a){t=f.map(e(t),this.getDimension,this);var o=i(this,t),s=o.indices=this.indices,l=o._storage,u=[];return this.each(t,function(){var e=arguments[arguments[G]-1],i=n&&n.apply(this,arguments);if(null!=i){"number"==typeof i&&(u[0]=i,i=u);for(var r=0;r<i[G];r++){var a=t[r],o=l[a],c=s[e];o&&(o[c]=i[r])}}},r,a),o},w.downSample=function(t,e,n,r){for(var a=i(this,[t]),o=this._storage,s=a._storage,l=this.indices,u=a.indices=[],c=[],h=[],f=Math.floor(1/e),d=s[t],p=this.count(),v=0;v<o[t][G];v++)s[t][v]=o[t][v];for(var v=0;p>v;v+=f){f>p-v&&(f=p-v,c[G]=f);for(var m=0;f>m;m++){var g=l[v+m];c[m]=d[g],h[m]=g}var y=n(c),g=h[r(c,y)||0];d[g]=y,u.push(g)}return a},w[m]=function(t){var e=this.hostModel;return t=this.indices[t],new c(this._rawData[t],e,e&&e[s])},w.diff=function(t){var e=this._idList,i=t&&t._idList;return new h(t?t.indices:[],this.indices,function(t){return i[t]||t+""},function(t){return e[t]||t+""})},w.getVisual=function(t){var e=this._visual;return e&&e[t]},w.setVisual=function(t,e){if(p(t))for(var i in t)t.hasOwnProperty(i)&&this.setVisual(i,t[i]);else this._visual=this._visual||{},this._visual[t]=e},w.setLayout=function(t,e){if(p(t))for(var i in t)t.hasOwnProperty(i)&&this.setLayout(i,t[i]);else this._layout[t]=e},w.getLayout=function(t){return this._layout[t]},w.getItemLayout=function(t){return this._itemLayouts[t]},w.setItemLayout=function(t,e,i){this._itemLayouts[t]=i?f[I](this._itemLayouts[t]||{},e):e},w.clearItemLayouts=function(){this._itemLayouts[G]=0},w[R]=function(t,e,i){var n=this._itemVisuals[t],r=n&&n[e];return null!=r||i?r:this.getVisual(e)},w.setItemVisual=function(t,e,i){var n=this._itemVisuals[t]||{};if(this._itemVisuals[t]=n,p(e))for(var r in e)e.hasOwnProperty(r)&&(n[r]=e[r]);else n[e]=i},w.clearAllVisual=function(){this._visual={},this._itemVisuals=[]};var M=function(t){t.seriesIndex=this.seriesIndex,t[B]=this[B],t.dataType=this.dataType};return w.setItemGraphicEl=function(t,e){var i=this.hostModel;e&&(e[B]=t,e.dataType=this.dataType,e.seriesIndex=i&&i.seriesIndex,"group"===e.type&&e.traverse(M,e)),this._graphicEls[t]=e},w.getItemGraphicEl=function(t){return this._graphicEls[t]},w.eachItemGraphicEl=function(t,e){f.each(this._graphicEls,function(i,n){i&&t&&t.call(e,i,n)})},w.cloneShallow=function(){var t=f.map(this[_],this.getDimensionInfo,this),e=new x(t,this.hostModel);return e._storage=this._storage,y(e,this),e.indices=this.indices.slice(),this._extent&&(e._extent=f[I]({},this._extent)),e},w.wrapMethod=function(t,e){var i=this[t];typeof i===C&&(this.__wrappedMethods=this.__wrappedMethods||[],this.__wrappedMethods.push(t),this[t]=function(){var t=i.apply(this,arguments);return e.apply(this,[t][b](f.slice(arguments)))})},w.TRANSFERABLE_METHODS=["cloneShallow","downSample","map"],w.CHANGABLE_METHODS=["filterSelf"],x}),e("echarts/util/number",[ne],function(){function t(t){return t[A](/^\s+/,"")[A](/\s+$/,"")}var e={},i=1e-4;return e.linearMap=function(t,e,i,n){var r=e[1]-e[0],a=i[1]-i[0];if(0===r)return 0===a?i[0]:(i[0]+i[1])/2;if(n)if(r>0){if(t<=e[0])return i[0];if(t>=e[1])return i[1]}else{if(t>=e[0])return i[0];if(t<=e[1])return i[1]}else{if(t===e[0])return i[0];if(t===e[1])return i[1]}return(t-e[0])/r*a+i[0]},e[p]=function(e,i){switch(e){case d:case x:e="50%";break;case"left":case"top":e="0%";break;case"right":case V:e="100%"}return typeof e===Q?t(e).match(/%$/)?parseFloat(e)/100*i:parseFloat(e):null==e?0/0:+e},e.round=function(t,e){return null==e&&(e=10),e=Math.min(Math.max(0,e),20),+(+t).toFixed(e)},e.asc=function(t){return t.sort(function(t,e){return t-e}),t},e.getPrecision=function(t){if(t=+t,isNaN(t))return 0;for(var e=1,i=0;Math.round(t*e)/e!==t;)e*=10,i++;return i},e.getPrecisionSafe=function(t){var e=t.toString(),i=e[F](".");return 0>i?0:e[G]-1-i},e.getPixelPrecision=function(t,e){var i=Math.log,n=Math.LN10,r=Math.floor(i(t[1]-t[0])/n),a=Math.round(i(Math.abs(e[1]-e[0]))/n);return Math.max(-r+a,0)},e.MAX_SAFE_INTEGER=9007199254740991,e.remRadian=function(t){var e=2*Math.PI;return(t%e+e)%e},e.isRadianAroundZero=function(t){return t>-i&&i>t},e.parseDate=function(t){if(t instanceof Date)return t;if(typeof t===Q){var e=new Date(t);return isNaN(+e)&&(e=new Date(new Date(t[A](/-/g,"/"))-new Date("1970/01/01"))),e}return new Date(Math.round(t))},e.quantity=function(t){return Math.pow(10,Math.floor(Math.log(t)/Math.LN10))},e.nice=function(t,i){var n,r=e.quantity(t),a=t/r;return n=i?1.5>a?1:2.5>a?2:4>a?3:7>a?5:10:1>a?1:2>a?2:3>a?3:5>a?5:10,n*r},e}),e("echarts/util/format",[ne,ie,"./number","zrender/contain/text"],function(t){var e=t(ie),i=t("./number"),n=t("zrender/contain/text"),r={};r.addCommas=function(t){return isNaN(t)?"-":(t=(t+"").split("."),t[0][A](/(\d{1,3})(?=(?:\d{3})+(?!\d))/g,"$1,")+(t[G]>1?"."+t[1]:""))},r.toCamelCase=function(t){return t[J]()[A](/-(.)/g,function(t,e){return e.toUpperCase()})},r.normalizeCssArray=function(t){var e=t[G];return"number"==typeof t?[t,t,t,t]:2===e?[t[0],t[1],t[0],t[1]]:3===e?[t[0],t[1],t[2],t[1]]:t},r.encodeHTML=function(t){return String(t)[A](/&/g,"&amp;")[A](/</g,"&lt;")[A](/>/g,"&gt;")[A](/"/g,"&quot;")[A](/'/g,"&#39;")};var a=["a","b","c","d","e","f","g"],o=function(t,e){return"{"+t+(null==e?"":e)+"}"};r.formatTpl=function(t,i){e[P](i)||(i=[i]);var n=i[G];if(!n)return"";for(var r=i[0].$vars||[],s=0;s<r[G];s++){var l=a[s];t=t[A](o(l),o(l,0))}for(var u=0;n>u;u++)for(var c=0;c<r[G];c++)t=t[A](o(a[c],u),i[u][r[c]]);return t};var s=function(t){return 10>t?"0"+t:t};return r.formatTime=function(t,e){("week"===t||"month"===t||"quarter"===t||"half-year"===t||"year"===t)&&(t="MM-dd\nyyyy");var n=i.parseDate(e),r=n.getFullYear(),a=n.getMonth()+1,o=n.getDate(),l=n.getHours(),u=n.getMinutes(),c=n.getSeconds();return t=t[A]("MM",s(a))[J]()[A]("yyyy",r)[A]("yy",r%100)[A]("dd",s(o))[A]("d",o)[A]("hh",s(l))[A]("h",l)[A]("mm",s(u))[A]("m",u)[A]("ss",s(c))[A]("s",c)},r.capitalFirst=function(t){return t?t.charAt(0).toUpperCase()+t.substr(1):t},r.truncateText=n.truncateText,r}),e("zrender/core/matrix",[],function(){var t=typeof Float32Array===r?Array:Float32Array,e={create:function(){var i=new t(6);return e.identity(i),i},identity:function(t){return t[0]=1,t[1]=0,t[2]=0,t[3]=1,t[4]=0,t[5]=0,t},copy:function(t,e){return t[0]=e[0],t[1]=e[1],t[2]=e[2],t[3]=e[3],t[4]=e[4],t[5]=e[5],t},mul:function(t,e,i){var n=e[0]*i[0]+e[2]*i[1],r=e[1]*i[0]+e[3]*i[1],a=e[0]*i[2]+e[2]*i[3],o=e[1]*i[2]+e[3]*i[3],s=e[0]*i[4]+e[2]*i[5]+e[4],l=e[1]*i[4]+e[3]*i[5]+e[5];return t[0]=n,t[1]=r,t[2]=a,t[3]=o,t[4]=s,t[5]=l,t},translate:function(t,e,i){return t[0]=e[0],t[1]=e[1],t[2]=e[2],t[3]=e[3],t[4]=e[4]+i[0],t[5]=e[5]+i[1],t},rotate:function(t,e,i){var n=e[0],r=e[2],a=e[4],o=e[1],s=e[3],l=e[5],u=Math.sin(i),c=Math.cos(i);return t[0]=n*c+o*u,t[1]=-n*u+o*c,t[2]=r*c+s*u,t[3]=-r*u+c*s,t[4]=c*a+u*l,t[5]=c*l-u*a,t},scale:function(t,e,i){var n=i[0],r=i[1];return t[0]=e[0]*n,t[1]=e[1]*r,t[2]=e[2]*n,t[3]=e[3]*r,t[4]=e[4]*n,t[5]=e[5]*r,t},invert:function(t,e){var i=e[0],n=e[2],r=e[4],a=e[1],o=e[3],s=e[5],l=i*o-a*n;return l?(l=1/l,t[0]=o*l,t[1]=-a*l,t[2]=-n*l,t[3]=i*l,t[4]=(n*s-o*r)*l,t[5]=(a*r-i*s)*l,t):null}};return e}),e("zrender/core/vector",[],function(){var t=typeof Float32Array===r?Array:Float32Array,e={create:function(e,i){var n=new t(2);return null==e&&(e=0),null==i&&(i=0),n[0]=e,n[1]=i,n},copy:function(t,e){return t[0]=e[0],t[1]=e[1],t},clone:function(e){var i=new t(2);return i[0]=e[0],i[1]=e[1],i},set:function(t,e,i){return t[0]=e,t[1]=i,t},add:function(t,e,i){return t[0]=e[0]+i[0],t[1]=e[1]+i[1],t},scaleAndAdd:function(t,e,i,n){return t[0]=e[0]+i[0]*n,t[1]=e[1]+i[1]*n,t},sub:function(t,e,i){return t[0]=e[0]-i[0],t[1]=e[1]-i[1],t},len:function(t){return Math.sqrt(this.lenSquare(t))},lenSquare:function(t){return t[0]*t[0]+t[1]*t[1]},mul:function(t,e,i){return t[0]=e[0]*i[0],t[1]=e[1]*i[1],t},div:function(t,e,i){return t[0]=e[0]/i[0],t[1]=e[1]/i[1],t},dot:function(t,e){return t[0]*e[0]+t[1]*e[1]},scale:function(t,e,i){return t[0]=e[0]*i,t[1]=e[1]*i,t},normalize:function(t,i){var n=e.len(i);return 0===n?(t[0]=0,t[1]=0):(t[0]=i[0]/n,t[1]=i[1]/n),t},distance:function(t,e){return Math.sqrt((t[0]-e[0])*(t[0]-e[0])+(t[1]-e[1])*(t[1]-e[1]))},distanceSquare:function(t,e){return(t[0]-e[0])*(t[0]-e[0])+(t[1]-e[1])*(t[1]-e[1])},negate:function(t,e){return t[0]=-e[0],t[1]=-e[1],t},lerp:function(t,e,i,n){return t[0]=e[0]+n*(i[0]-e[0]),t[1]=e[1]+n*(i[1]-e[1]),t},applyTransform:function(t,e,i){var n=e[0],r=e[1];return t[0]=i[0]*n+i[2]*r+i[4],t[1]=i[1]*n+i[3]*r+i[5],t},min:function(t,e,i){return t[0]=Math.min(e[0],i[0]),t[1]=Math.min(e[1],i[1]),t},max:function(t,e,i){return t[0]=Math.max(e[0],i[0]),t[1]=Math.max(e[1],i[1]),t}};return e[G]=e.len,e.lengthSquare=e.lenSquare,e.dist=e.distance,e.distSquare=e.distanceSquare,e}),e("zrender/vml/Painter",[ne,"../core/log","./core"],function(t){function e(t){return parseInt(t,10)}function i(t,e){o.initVML(),this.root=t,this[q]=e;var i=document[w]("div"),n=document[w]("div");i.style.cssText="display:inline-block;overflow:hidden;position:relative;width:300px;height:150px;",n.style.cssText="position:absolute;left:0;top:0;",t.appendChild(i),this._vmlRoot=n,this._vmlViewport=i,this[Y]();var r=e.delFromMap,a=e.addToMap;e.delFromMap=function(t){var i=e.get(t);r.call(e,t),i&&i.onRemove&&i.onRemove(n)},e.addToMap=function(t){t.onAdd&&t.onAdd(n),a.call(e,t)},this._firstPaint=!0}function r(t){return function(){a('In IE8.0 VML mode painter not support method "'+t+'"')}}var a=t("../core/log"),o=t("./core");i[K]={constructor:i,getViewportRoot:function(){return this._vmlViewport},refresh:function(){var t=this[q].getDisplayList(!0,!0);this._paintList(t)},_paintList:function(t){for(var e=this._vmlRoot,i=0;i<t[G];i++){var r=t[i];r.invisible||r[H]?(r.__alreadyNotVisible||r.onRemove(e),r.__alreadyNotVisible=!0):(r.__alreadyNotVisible&&r.onAdd(e),r.__alreadyNotVisible=!1,r[n]&&(r.beforeBrush&&r.beforeBrush(),(r.brushVML||r.brush).call(r,e),r.afterBrush&&r.afterBrush())),r[n]=!1}this._firstPaint&&(this._vmlViewport.appendChild(e),this._firstPaint=!1)},resize:function(t,e){var t=null==t?this._getWidth():t,e=null==e?this._getHeight():e;if(this._width!=t||this._height!=e){this._width=t,this._height=e;var i=this._vmlViewport.style;i.width=t+"px",i[$]=e+"px"}},dispose:function(){this.root.innerHTML="",this._vmlRoot=this._vmlViewport=this[q]=null},getWidth:function(){return this._width},getHeight:function(){return this._height},clear:function(){this._vmlViewport&&this.root.removeChild(this._vmlViewport)},_getWidth:function(){var t=this.root,i=t.currentStyle;return(t.clientWidth||e(i.width))-e(i.paddingLeft)-e(i.paddingRight)|0},_getHeight:function(){var t=this.root,i=t.currentStyle;return(t.clientHeight||e(i[$]))-e(i.paddingTop)-e(i.paddingBottom)|0}};for(var s=["getLayer","insertLayer","eachLayer","eachBuildinLayer","eachOtherLayer","getLayers","modLayer","delLayer","clearLayer","toDataURL","pathToImage"],l=0;l<s[G];l++){var u=s[l];i[K][u]=r(u)}return i}),e("zrender/vml/graphic",[ne,"../core/env","../core/vector","../core/BoundingRect","../core/PathProxy","../tool/color","../contain/text","../graphic/mixin/RectText","../graphic/Displayable","../graphic/Image","../graphic/Text","../graphic/Path","../graphic/Gradient","./core"],function(t){if(!t("../core/env")[W]){var e=t("../core/vector"),i=t("../core/BoundingRect"),n=t("../core/PathProxy").CMD,r=t("../tool/color"),a=t("../contain/text"),s=t("../graphic/mixin/RectText"),l=t("../graphic/Displayable"),f=t("../graphic/Image"),p=t("../graphic/Text"),v=t("../graphic/Path"),m=t("../graphic/Gradient"),g=t("./core"),y=Math.round,_=Math.sqrt,b=Math.abs,M=Math.cos,T=Math.sin,S=Math.max,C=e[u],P=",",z="progid:DXImageTransform.Microsoft",k=21600,I=k/2,D=1e5,O=1e3,E=function(t){t.style.cssText="position:absolute;left:0;top:0;width:1px;height:1px;",t.coordsize=k+","+k,t.coordorigin="0,0"},R=function(t){return String(t)[A](/&/g,"&amp;")[A](/"/g,"&quot;")},B=function(t,e,i){return"rgb("+[t,e,i].join(",")+")"},N=function(t,e){e&&t&&e.parentNode!==t&&t.appendChild(e)},F=function(t,e){e&&t&&e.parentNode===t&&t.removeChild(e)},H=function(t,e,i){return(parseFloat(t)||0)*D+(parseFloat(e)||0)*O+i},q=function(t,e){return typeof t===Q?t.lastIndexOf("%")>=0?parseFloat(t)/100*e:parseFloat(t):t},Z=function(t,e,i){var n=r.parse(e);i=+i,isNaN(i)&&(i=1),n&&(t.color=B(n[0],n[1],n[2]),t.opacity=i*n[3])},X=function(t){var e=r.parse(t);return[B(e[0],e[1],e[2]),e[3]]},U=function(t,e,i){var n=e.fill;if(null!=n)if(n instanceof m){var r,a=0,s=[0,0],l=0,u=1,h=i[c](),f=h.width,d=h[$];if("linear"===n.type){r="gradient";var p=i[o],v=[n.x*f,n.y*d],g=[n.x2*f,n.y2*d];p&&(C(v,v,p),C(g,g,p));var y=g[0]-v[0],_=g[1]-v[1];a=180*Math.atan2(y,_)/Math.PI,0>a&&(a+=360),1e-6>a&&(a=0)}else{r="gradientradial";var v=[n.x*f,n.y*d],p=i[o],x=i.scale,b=f,w=d;s=[(v[0]-h.x)/b,(v[1]-h.y)/w],p&&C(v,v,p),b/=x[0]*k,w/=x[1]*k;var M=S(b,w);l=0/M,u=2*n.r/M-l}var T=n.colorStops.slice();T.sort(function(t,e){return t.offset-e.offset});for(var P=T[G],A=[],L=[],z=0;P>z;z++){var I=T[z],D=X(I.color);L.push(I.offset*u+l+" "+D[0]),(0===z||z===P-1)&&A.push(D)}if(P>=2){var O=A[0][0],E=A[1][0],R=A[0][1]*e.opacity,B=A[1][1]*e.opacity;t.type=r,t.method="none",t.focus="100%",t.angle=a,t.color=O,t.color2=E,t.colors=L.join(","),t.opacity=B,t.opacity2=R}"radial"===r&&(t.focusposition=s.join(","))}else Z(t,n,e.opacity)},j=function(t,e){null!=e.lineDash&&(t.dashstyle=e.lineDash.join(" ")),null==e[h]||e[h]instanceof m||Z(t,e[h],e.opacity)},Y=function(t,e,i,n){var r="fill"==e,a=t.getElementsByTagName(e)[0];null!=i[e]&&"none"!==i[e]&&(r||!r&&i.lineWidth)?(t[r?"filled":"stroked"]="true",i[e]instanceof m&&F(t,a),a||(a=g.createNode(e)),r?U(a,i,n):j(a,i),N(t,a)):(t[r?"filled":"stroked"]="false",F(t,a))},J=[[],[],[]],te=function(t,e){var i,r,a,o,s,l,u=n.M,c=n.C,h=n.L,f=n.A,d=n.Q,p=[];for(o=0;o<t[G];){switch(a=t[o++],r="",i=0,a){case u:r=" m ",i=1,s=t[o++],l=t[o++],J[0][0]=s,J[0][1]=l;break;case h:r=" l ",i=1,s=t[o++],l=t[o++],J[0][0]=s,J[0][1]=l;break;case d:case c:r=" c ",i=3;var v,m,g=t[o++],x=t[o++],b=t[o++],w=t[o++];a===d?(v=b,m=w,b=(b+2*g)/3,w=(w+2*x)/3,g=(s+2*g)/3,x=(l+2*x)/3):(v=t[o++],m=t[o++]),J[0][0]=g,J[0][1]=x,J[1][0]=b,J[1][1]=w,J[2][0]=v,J[2][1]=m,s=v,l=m;break;case f:var S=0,A=0,L=1,z=1,D=0;e&&(S=e[4],A=e[5],L=_(e[0]*e[0]+e[1]*e[1]),z=_(e[2]*e[2]+e[3]*e[3]),D=Math.atan2(-e[1]/z,e[0]/L));var O=t[o++],E=t[o++],R=t[o++],B=t[o++],N=t[o++]+D,F=t[o++]+N+D;o++;var V=t[o++],H=O+M(N)*R,q=E+T(N)*B,g=O+M(F)*R,x=E+T(F)*B,W=V?" wa ":" at ";Math.abs(H-g)<1e-10&&(Math.abs(F-N)>.01?V&&(H+=270/k):Math.abs(q-E)<1e-10?V&&O>H||!V&&H>O?x-=270/k:x+=270/k:V&&E>q||!V&&q>E?g+=270/k:g-=270/k),p.push(W,y(((O-R)*L+S)*k-I),P,y(((E-B)*z+A)*k-I),P,y(((O+R)*L+S)*k-I),P,y(((E+B)*z+A)*k-I),P,y((H*L+S)*k-I),P,y((q*z+A)*k-I),P,y((g*L+S)*k-I),P,y((x*z+A)*k-I)),s=g,l=x;break;case n.R:var Z=J[0],X=J[1];Z[0]=t[o++],Z[1]=t[o++],X[0]=Z[0]+t[o++],X[1]=Z[1]+t[o++],e&&(C(Z,Z,e),C(X,X,e)),Z[0]=y(Z[0]*k-I),X[0]=y(X[0]*k-I),Z[1]=y(Z[1]*k-I),X[1]=y(X[1]*k-I),p.push(" m ",Z[0],P,Z[1]," l ",X[0],P,Z[1]," l ",X[0],P,X[1]," l ",Z[0],P,X[1]);break;case n.Z:p.push(" x ")}if(i>0){p.push(r);for(var U=0;i>U;U++){var j=J[U];e&&C(j,j,e),p.push(y(j[0]*k-I),P,y(j[1]*k-I),i-1>U?P:"")}}}return p.join("")};v[K].brushVML=function(t){var e=this.style,i=this._vmlEl;i||(i=g.createNode("shape"),E(i),this._vmlEl=i),Y(i,"fill",e,this),Y(i,h,e,this);var n=this[o],r=null!=n,a=i.getElementsByTagName(h)[0];if(a){var s=e.lineWidth;if(r&&!e.strokeNoScale){var l=n[0]*n[3]-n[1]*n[2];s*=_(b(l))}a.weight=s+"px"}var u=this.path;this.__dirtyPath&&(u.beginPath(),this.buildPath(u,this.shape),u.toStatic(),this.__dirtyPath=!1),i.path=te(u.data,this[o]),i.style.zIndex=H(this[L],this.z,this.z2),N(t,i),e.text?this.drawRectText(t,this[c]()):this.removeRectText(t)},v[K].onRemove=function(t){F(t,this._vmlEl),this.removeRectText(t)},v[K].onAdd=function(t){N(t,this._vmlEl),this.appendRectText(t)};var ee=function(t){return"object"==typeof t&&t.tagName&&"IMG"===t.tagName.toUpperCase()};f[K].brushVML=function(t){var e,i,n=this.style,r=n.image;if(ee(r)){var a=r.src;if(a===this._imageSrc)e=this._imageWidth,i=this._imageHeight;else{var s=r.runtimeStyle,l=s.width,u=s[$];s.width="auto",s[$]="auto",e=r.width,i=r[$],s.width=l,s[$]=u,this._imageSrc=a,this._imageWidth=e,this._imageHeight=i}r=a}else r===this._imageSrc&&(e=this._imageWidth,i=this._imageHeight);if(r){var h=n.x||0,f=n.y||0,d=n.width,p=n[$],v=n.sWidth,m=n.sHeight,x=n.sx||0,b=n.sy||0,M=v&&m,T=this._vmlEl;T||(T=g.doc[w]("div"),E(T),this._vmlEl=T);var A,k=T.style,I=!1,D=1,O=1;if(this[o]&&(A=this[o],D=_(A[0]*A[0]+A[1]*A[1]),O=_(A[2]*A[2]+A[3]*A[3]),I=A[1]||A[2]),I){var R=[h,f],B=[h+d,f],F=[h,f+p],G=[h+d,f+p];C(R,R,A),C(B,B,A),C(F,F,A),C(G,G,A);var V=S(R[0],B[0],F[0],G[0]),q=S(R[1],B[1],F[1],G[1]),W=[];W.push("M11=",A[0]/D,P,"M12=",A[2]/O,P,"M21=",A[1]/D,P,"M22=",A[3]/O,P,"Dx=",y(h*D+A[4]),P,"Dy=",y(f*O+A[5])),k.padding="0 "+y(V)+"px "+y(q)+"px 0",k.filter=z+".Matrix("+W.join("")+", SizingMethod=clip)"}else A&&(h=h*D+A[4],f=f*O+A[5]),k.filter="",k.left=y(h)+"px",k.top=y(f)+"px";var Z=this._imageEl,X=this._cropEl;Z||(Z=g.doc[w]("div"),this._imageEl=Z);var U=Z.style;if(M){if(e&&i)U.width=y(D*e*d/v)+"px",U[$]=y(O*i*p/m)+"px";else{var j=new Image,Y=this;j.onload=function(){j.onload=null,e=j.width,i=j[$],U.width=y(D*e*d/v)+"px",U[$]=y(O*i*p/m)+"px",Y._imageWidth=e,Y._imageHeight=i,Y._imageSrc=r},j.src=r}X||(X=g.doc[w]("div"),X.style.overflow="hidden",this._cropEl=X);var Q=X.style;Q.width=y((d+x*d/v)*D),Q[$]=y((p+b*p/m)*O),Q.filter=z+".Matrix(Dx="+-x*d/v*D+",Dy="+-b*p/m*O+")",X.parentNode||T.appendChild(X),Z.parentNode!=X&&X.appendChild(Z)}else U.width=y(D*d)+"px",U[$]=y(O*p)+"px",T.appendChild(Z),X&&X.parentNode&&(T.removeChild(X),this._cropEl=null);var K="",J=n.opacity;1>J&&(K+=".Alpha(opacity="+y(100*J)+") "),K+=z+".AlphaImageLoader(src="+r+", SizingMethod=scale)",U.filter=K,T.style.zIndex=H(this[L],this.z,this.z2),N(t,T),n.text&&this.drawRectText(t,this[c]())}},f[K].onRemove=function(t){F(t,this._vmlEl),this._vmlEl=null,this._cropEl=null,this._imageEl=null,this.removeRectText(t)},f[K].onAdd=function(t){N(t,this._vmlEl),this.appendRectText(t)};var ie,ne="normal",re={},ae=0,oe=100,se=document[w]("div"),le=function(t){var e=re[t];if(!e){ae>oe&&(ae=0,re={});var i,n=se.style;try{n.font=t,i=n.fontFamily.split(",")[0]}catch(r){}e={style:n.fontStyle||ne,variant:n.fontVariant||ne,weight:n.fontWeight||ne,size:0|parseFloat(n.fontSize||12),family:i||"Microsoft YaHei"},re[t]=e,ae++}return e};a.measureText=function(t,e){var i=g.doc;ie||(ie=i[w]("div"),ie.style.cssText="position:absolute;top:-20000px;left:0;padding:0;margin:0;border:none;white-space:pre;",g.doc.body.appendChild(ie));try{ie.style.font=e}catch(n){}return ie.innerHTML="",ie.appendChild(i.createTextNode(t)),{width:ie.offsetWidth}};for(var ue=new i,ce=function(t,e,i,n){var r=this.style,s=r.text;if(s){var l,f,p=r.textAlign,v=le(r.textFont),m=v.style+" "+v.variant+" "+v.weight+" "+v.size+'px "'+v.family+'"',_=r.textBaseline,b=r.textVerticalAlign;i=i||a[c](s,m,p,_);var w=this[o];if(w&&!n&&(ue.copy(e),ue[u](w),e=ue),n)l=e.x,f=e.y;else{var M=r.textPosition,T=r.textDistance;
if(M instanceof Array)l=e.x+q(M[0],e.width),f=e.y+q(M[1],e[$]),p=p||"left",_=_||"top";else{var S=a.adjustTextPositionOnRect(M,e,i,T);l=S.x,f=S.y,p=p||S.textAlign,_=_||S.textBaseline}}if(b){switch(b){case x:f-=i[$]/2;break;case V:f-=i[$]}_="top"}var A=v.size;switch(_){case"hanging":case"top":f+=A/1.75;break;case x:break;default:f-=A/2.25}switch(p){case"left":break;case d:l-=i.width/2;break;case"right":l-=i.width}var z,k,I,D=g.createNode,O=this._textVmlEl;O?(I=O.firstChild,z=I.nextSibling,k=z.nextSibling):(O=D("line"),z=D("path"),k=D("textpath"),I=D("skew"),k.style["v-text-align"]="left",E(O),z.textpathok=!0,k.on=!0,O.from="0 0",O.to="1000 0.05",N(O,I),N(O,z),N(O,k),this._textVmlEl=O);var B=[l,f],F=O.style;w&&n?(C(B,B,w),I.on=!0,I.matrix=w[0].toFixed(3)+P+w[2].toFixed(3)+P+w[1].toFixed(3)+P+w[3].toFixed(3)+",0,0",I.offset=(y(B[0])||0)+","+(y(B[1])||0),I.origin="0 0",F.left="0px",F.top="0px"):(I.on=!1,F.left=y(l)+"px",F.top=y(f)+"px"),k[Q]=R(s);try{k.style.font=m}catch(G){}Y(O,"fill",{fill:n?r.fill:r.textFill,opacity:r.opacity},this),Y(O,h,{stroke:n?r[h]:r.textStroke,opacity:r.opacity,lineDash:r.lineDash},this),O.style.zIndex=H(this[L],this.z,this.z2),N(t,O)}},he=function(t){F(t,this._textVmlEl),this._textVmlEl=null},fe=function(t){N(t,this._textVmlEl)},de=[s,l,f,v,p],pe=0;pe<de[G];pe++){var ve=de[pe][K];ve.drawRectText=ce,ve.removeRectText=he,ve.appendRectText=fe}p[K].brushVML=function(t){var e=this.style;e.text?this.drawRectText(t,{x:e.x||0,y:e.y||0,width:0,height:0},this[c](),!0):this.removeRectText(t)},p[K].onRemove=function(t){this.removeRectText(t)},p[K].onAdd=function(t){this.appendRectText(t)}}}),e("echarts/scale/Interval",[ne,"../util/number","../util/format","./Scale"],function(t){var e=t("../util/number"),i=t("../util/format"),n=t("./Scale"),r=Math.floor,a=Math.ceil,o=e.getPrecisionSafe,s=e.round,l=n[I]({type:"interval",_interval:0,setExtent:function(t,e){var i=this._extent;isNaN(t)||(i[0]=parseFloat(t)),isNaN(e)||(i[1]=parseFloat(e))},unionExtent:function(t){var e=this._extent;t[0]<e[0]&&(e[0]=t[0]),t[1]>e[1]&&(e[1]=t[1]),l[K].setExtent.call(this,e[0],e[1])},getInterval:function(){return this._interval||this.niceTicks(),this._interval},setInterval:function(t){this._interval=t,this._niceExtent=this._extent.slice()},getTicks:function(){this._interval||this.niceTicks();var t=this._interval,e=this._extent,i=[],n=1e4;if(t){var r=this._niceExtent,a=o(t)+2;e[0]<r[0]&&i.push(e[0]);for(var l=r[0];l<=r[1];)if(i.push(l),l=s(l+t,a),i[G]>n)return[];e[1]>(i[G]?i[i[G]-1]:r[1])&&i.push(e[1])}return i},getTicksLabels:function(){for(var t=[],e=this.getTicks(),i=0;i<e[G];i++)t.push(this.getLabel(e[i]));return t},getLabel:function(t){return i.addCommas(t)},niceTicks:function(t){t=t||5;var i=this._extent,n=i[1]-i[0];if(isFinite(n)){0>n&&(n=-n,i.reverse());var l=s(e.nice(n/t,!0),Math.max(o(i[0]),o(i[1]))+2),u=o(l)+2,c=[s(a(i[0]/l)*l,u),s(r(i[1]/l)*l,u)];this._interval=l,this._niceExtent=c}},niceExtent:function(t,e,i){var n=this._extent;if(n[0]===n[1])if(0!==n[0]){var o=n[0];i?n[0]-=o/2:(n[1]+=o/2,n[0]-=o/2)}else n[1]=1;var l=n[1]-n[0];isFinite(l)||(n[0]=0,n[1]=1),this.niceTicks(t);var u=this._interval;e||(n[0]=s(r(n[0]/u)*u)),i||(n[1]=s(a(n[1]/u)*u))}});return l[E]=function(){return new l},l}),e("echarts/scale/Scale",[ne,"../util/clazz"],function(t){function e(){this._extent=[1/0,-1/0],this._interval=0,this.init&&this.init.apply(this,arguments)}var i=t("../util/clazz"),n=e[K];return n.parse=function(t){return t},n[T]=function(t){var e=this._extent;return t>=e[0]&&t<=e[1]},n.normalize=function(t){var e=this._extent;return e[1]===e[0]?.5:(t-e[0])/(e[1]-e[0])},n.scale=function(t){var e=this._extent;return t*(e[1]-e[0])+e[0]},n.unionExtent=function(t){var e=this._extent;t[0]<e[0]&&(e[0]=t[0]),t[1]>e[1]&&(e[1]=t[1])},n[M]=function(){return this._extent.slice()},n.setExtent=function(t,e){var i=this._extent;isNaN(t)||(i[0]=t),isNaN(e)||(i[1]=e)},n.getTicksLabels=function(){for(var t=[],e=this.getTicks(),i=0;i<e[G];i++)t.push(this.getLabel(e[i]));return t},i.enableClassExtend(e),i.enableClassManagement(e,{registerWhenExtend:!0}),e}),e("zrender/tool/path",[ne,"../graphic/Path","../core/PathProxy","./transformPath","../core/matrix"],function(t){function e(t,e,i,n,r,a,o,s,l,u,c){var v=l*(p/180),y=d(v)*(t-i)/2+f(v)*(e-n)/2,_=-1*f(v)*(t-i)/2+d(v)*(e-n)/2,x=y*y/(o*o)+_*_/(s*s);x>1&&(o*=h(x),s*=h(x));var b=(r===a?-1:1)*h((o*o*s*s-o*o*_*_-s*s*y*y)/(o*o*_*_+s*s*y*y))||0,w=b*o*_/s,M=b*-s*y/o,T=(t+i)/2+d(v)*w-f(v)*M,S=(e+n)/2+f(v)*w+d(v)*M,C=g([1,0],[(y-w)/o,(_-M)/s]),P=[(y-w)/o,(_-M)/s],A=[(-1*y-w)/o,(-1*_-M)/s],L=g(P,A);m(P,A)<=-1&&(L=p),m(P,A)>=1&&(L=0),0===a&&L>0&&(L-=2*p),1===a&&0>L&&(L+=2*p),c.addData(u,T,S,o,s,C,L,v,a)}function i(t){if(!t)return[];var i,n=t[A](/-/g," -")[A](/  /g," ")[A](/ /g,",")[A](/,,/g,",");for(i=0;i<c[G];i++)n=n[A](new RegExp(c[i],"g"),"|"+c[i]);var r,a=n.split("|"),s=0,l=0,u=new o,h=o.CMD;for(i=1;i<a[G];i++){var f,d=a[i],p=d.charAt(0),v=0,m=d.slice(1)[A](/e,-/g,"e-").split(",");m[G]>0&&""===m[0]&&m.shift();for(var g=0;g<m[G];g++)m[g]=parseFloat(m[g]);for(;v<m[G]&&!isNaN(m[v])&&!isNaN(m[0]);){var y,_,x,b,w,M,T,S=s,C=l;switch(p){case"l":s+=m[v++],l+=m[v++],f=h.L,u.addData(f,s,l);break;case"L":s=m[v++],l=m[v++],f=h.L,u.addData(f,s,l);break;case"m":s+=m[v++],l+=m[v++],f=h.M,u.addData(f,s,l),p="l";break;case"M":s=m[v++],l=m[v++],f=h.M,u.addData(f,s,l),p="L";break;case"h":s+=m[v++],f=h.L,u.addData(f,s,l);break;case"H":s=m[v++],f=h.L,u.addData(f,s,l);break;case"v":l+=m[v++],f=h.L,u.addData(f,s,l);break;case"V":l=m[v++],f=h.L,u.addData(f,s,l);break;case"C":f=h.C,u.addData(f,m[v++],m[v++],m[v++],m[v++],m[v++],m[v++]),s=m[v-2],l=m[v-1];break;case"c":f=h.C,u.addData(f,m[v++]+s,m[v++]+l,m[v++]+s,m[v++]+l,m[v++]+s,m[v++]+l),s+=m[v-2],l+=m[v-1];break;case"S":y=s,_=l;var P=u.len(),L=u.data;r===h.C&&(y+=s-L[P-4],_+=l-L[P-3]),f=h.C,S=m[v++],C=m[v++],s=m[v++],l=m[v++],u.addData(f,y,_,S,C,s,l);break;case"s":y=s,_=l;var P=u.len(),L=u.data;r===h.C&&(y+=s-L[P-4],_+=l-L[P-3]),f=h.C,S=s+m[v++],C=l+m[v++],s+=m[v++],l+=m[v++],u.addData(f,y,_,S,C,s,l);break;case"Q":S=m[v++],C=m[v++],s=m[v++],l=m[v++],f=h.Q,u.addData(f,S,C,s,l);break;case"q":S=m[v++]+s,C=m[v++]+l,s+=m[v++],l+=m[v++],f=h.Q,u.addData(f,S,C,s,l);break;case"T":y=s,_=l;var P=u.len(),L=u.data;r===h.Q&&(y+=s-L[P-4],_+=l-L[P-3]),s=m[v++],l=m[v++],f=h.Q,u.addData(f,y,_,s,l);break;case"t":y=s,_=l;var P=u.len(),L=u.data;r===h.Q&&(y+=s-L[P-4],_+=l-L[P-3]),s+=m[v++],l+=m[v++],f=h.Q,u.addData(f,y,_,s,l);break;case"A":x=m[v++],b=m[v++],w=m[v++],M=m[v++],T=m[v++],S=s,C=l,s=m[v++],l=m[v++],f=h.A,e(S,C,s,l,M,T,x,b,w,f,u);break;case"a":x=m[v++],b=m[v++],w=m[v++],M=m[v++],T=m[v++],S=s,C=l,s+=m[v++],l+=m[v++],f=h.A,e(S,C,s,l,M,T,x,b,w,f,u)}}("z"===p||"Z"===p)&&(f=h.Z,u.addData(f)),r=f}return u.toStatic(),u}function r(t,e){var n,r=i(t);return e=e||{},e.buildPath=function(t){t.setData(r.data),n&&s(t,n);var e=t.getContext();e&&t.rebuildPath(e)},e[u]=function(t){n||(n=l[E]()),l.mul(n,t,n),this.dirty(!0)},e}var a=t("../graphic/Path"),o=t("../core/PathProxy"),s=t("./transformPath"),l=t("../core/matrix"),c=["m","M","l","L","v","V","h","H","z","Z","c","C","q","Q","t","T","s","S","a","A"],h=Math.sqrt,f=Math.sin,d=Math.cos,p=Math.PI,v=function(t){return Math.sqrt(t[0]*t[0]+t[1]*t[1])},m=function(t,e){return(t[0]*e[0]+t[1]*e[1])/(v(t)*v(e))},g=function(t,e){return(t[0]*e[1]<t[1]*e[0]?-1:1)*Math.acos(m(t,e))};return{createFromString:function(t,e){return new a(r(t,e))},extendFromString:function(t,e){return a[I](r(t,e))},mergePath:function(t,e){for(var i=[],r=t[G],o=0;r>o;o++){var s=t[o];s[n]&&s.buildPath(s.path,s.shape,!0),i.push(s.path)}var l=new a(e);return l.buildPath=function(t){t.appendPath(i);var e=t.getContext();e&&t.rebuildPath(e)},l}}}),e("zrender/graphic/Path",[ne,"./Displayable","../core/util","../core/PathProxy","../contain/path","./Pattern"],function(t){function e(t){i.call(this,t),this.path=new a}var i=t("./Displayable"),r=t("../core/util"),a=t("../core/PathProxy"),s=t("../contain/path"),l=t("./Pattern"),u=l[K].getCanvasPattern,f=Math.abs;return e[K]={constructor:e,type:"path",__dirtyPath:!0,strokeContainThreshold:5,brush:function(t,e){var i=this.style,r=this.path,a=i.hasStroke(),o=i.hasFill(),s=i.fill,l=i[h],f=o&&!!s.colorStops,d=a&&!!l.colorStops,p=o&&!!s.image,v=a&&!!l.image;if(i.bind(t,this,e),this.setTransform(t),this[n]){var m=this[c]();f&&(this._fillGradient=i.getGradient(t,s,m)),d&&(this._strokeGradient=i.getGradient(t,l,m))}f?t.fillStyle=this._fillGradient:p&&(t.fillStyle=u.call(s,t)),d?t.strokeStyle=this._strokeGradient:v&&(t.strokeStyle=u.call(l,t));var g=i.lineDash,y=i.lineDashOffset,_=!!t.setLineDash,x=this.getGlobalScale();r.setScale(x[0],x[1]),this.__dirtyPath||g&&!_&&a?(r=this.path.beginPath(t),g&&!_&&(r.setLineDash(g),r.setLineDashOffset(y)),this.buildPath(r,this.shape,!1),this.__dirtyPath=!1):(t.beginPath(),this.path.rebuildPath(t)),o&&r.fill(t),g&&_&&(t.setLineDash(g),t.lineDashOffset=y),a&&r[h](t),g&&_&&t.setLineDash([]),this.restoreTransform(t),(i.text||0===i.text)&&this.drawRectText(t,this[c]())},buildPath:function(){},getBoundingRect:function(){var t=this._rect,e=this.style,i=!t;if(i){var r=this.path;this.__dirtyPath&&(r.beginPath(),this.buildPath(r,this.shape,!1)),t=r[c]()}if(this._rect=t,e.hasStroke()){var a=this._rectWithStroke||(this._rectWithStroke=t.clone());if(this[n]||i){a.copy(t);var o=e.lineWidth,s=e.strokeNoScale?this.getLineScale():1;e.hasFill()||(o=Math.max(o,this.strokeContainThreshold||4)),s>1e-10&&(a.width+=o/s,a[$]+=o/s,a.x-=o/s/2,a.y-=o/s/2)}return a}return t},contain:function(t,e){var i=this.transformCoordToLocal(t,e),n=this[c](),r=this.style;if(t=i[0],e=i[1],n[T](t,e)){var a=this.path.data;if(r.hasStroke()){var o=r.lineWidth,l=r.strokeNoScale?this.getLineScale():1;if(l>1e-10&&(r.hasFill()||(o=Math.max(o,this.strokeContainThreshold)),s.containStroke(a,o/l,t,e)))return!0}if(r.hasFill())return s[T](a,t,e)}return!1},dirty:function(t){null==t&&(t=!0),t&&(this.__dirtyPath=t,this._rect=null),this[n]=!0,this.__zr&&this.__zr.refresh(),this.__clipTarget&&this.__clipTarget.dirty()},animateShape:function(t){return this.animate("shape",t)},attrKV:function(t,e){"shape"===t?(this.setShape(e),this.__dirtyPath=!0,this._rect=null):i[K].attrKV.call(this,t,e)},setShape:function(t,e){var i=this.shape;if(i){if(r[D](t))for(var n in t)t.hasOwnProperty(n)&&(i[n]=t[n]);else i[t]=e;this.dirty(!0)}return this},getLineScale:function(){var t=this[o];return t&&f(t[0]-1)>1e-10&&f(t[3]-1)>1e-10?Math.sqrt(f(t[0]*t[3]-t[2]*t[1])):1}},e[I]=function(t){var i=function(i){e.call(this,i),t.style&&this.style.extendFrom(t.style,!1);var n=t.shape;if(n){this.shape=this.shape||{};var r=this.shape;for(var a in n)!r.hasOwnProperty(a)&&n.hasOwnProperty(a)&&(r[a]=n[a])}t.init&&t.init.call(this,i)};r[S](i,e);for(var n in t)"style"!==n&&"shape"!==n&&(i[K][n]=t[n]);return i},r[S](e,i),e}),e("zrender/graphic/Gradient",[ne],function(){var t=function(t){this.colorStops=t||[]};return t[K]={constructor:t,addColorStop:function(t,e){this.colorStops.push({offset:t,color:e})}},t}),e("zrender/container/Group",[ne,"../core/util","../Element","../core/BoundingRect"],function(t){var e=t("../core/util"),i=t("../Element"),r=t("../core/BoundingRect"),a=function(t){t=t||{},i.call(this,t);for(var e in t)t.hasOwnProperty(e)&&(this[e]=t[e]);this._children=[],this.__storage=null,this[n]=!0};return a[K]={constructor:a,isGroup:!0,type:"group",silent:!1,children:function(){return this._children.slice()},childAt:function(t){return this._children[t]},childOfName:function(t){for(var e=this._children,i=0;i<e[G];i++)if(e[i].name===t)return e[i]},childCount:function(){return this._children[G]},add:function(t){return t&&t!==this&&t.parent!==this&&(this._children.push(t),this._doAdd(t)),this},addBefore:function(t,e){if(t&&t!==this&&t.parent!==this&&e&&e.parent===this){var i=this._children,n=i[F](e);n>=0&&(i[k](n,0,t),this._doAdd(t))}return this},_doAdd:function(t){t.parent&&t.parent.remove(t),t.parent=this;var e=this.__storage,i=this.__zr;e&&e!==t.__storage&&(e.addToMap(t),t instanceof a&&t.addChildrenToStorage(e)),i&&i.refresh()},remove:function(t){var i=this.__zr,n=this.__storage,r=this._children,o=e[F](r,t);return 0>o?this:(r[k](o,1),t.parent=null,n&&(n.delFromMap(t.id),t instanceof a&&t.delChildrenFromStorage(n)),i&&i.refresh(),this)},removeAll:function(){var t,e,i=this._children,n=this.__storage;for(e=0;e<i[G];e++)t=i[e],n&&(n.delFromMap(t.id),t instanceof a&&t.delChildrenFromStorage(n)),t.parent=null;return i[G]=0,this},eachChild:function(t,e){for(var i=this._children,n=0;n<i[G];n++){var r=i[n];t.call(e,r,n)}return this},traverse:function(t,e){for(var i=0;i<this._children[G];i++){var n=this._children[i];t.call(e,n),"group"===n.type&&n.traverse(t,e)}return this},addChildrenToStorage:function(t){for(var e=0;e<this._children[G];e++){var i=this._children[e];t.addToMap(i),i instanceof a&&i.addChildrenToStorage(t)}},delChildrenFromStorage:function(t){for(var e=0;e<this._children[G];e++){var i=this._children[e];t.delFromMap(i.id),i instanceof a&&i.delChildrenFromStorage(t)}},dirty:function(){return this[n]=!0,this.__zr&&this.__zr.refresh(),this},getBoundingRect:function(t){for(var e=null,i=new r(0,0,0,0),n=t||this._children,a=[],o=0;o<n[G];o++){var s=n[o];if(!s[H]&&!s.invisible){var l=s[c](),h=s.getLocalTransform(a);h?(i.copy(l),i[u](h),e=e||i.clone(),e.union(i)):(e=e||l.clone(),e.union(l))}}return e||i}},e[S](a,i),a}),e("zrender/graphic/Image",[ne,"./Displayable","../core/BoundingRect","../core/util","../core/LRU"],function(t){function e(t){i.call(this,t)}var i=t("./Displayable"),n=t("../core/BoundingRect"),r=t("../core/util"),a=t("../core/LRU"),o=new a(50);return e[K]={constructor:e,type:"image",brush:function(t,e){var i,n=this.style,r=n.image;if(n.bind(t,this,e),i=typeof r===Q?this._image:r,!i&&r){var a=o.get(r);if(!a)return i=new Image,i.onload=function(){i.onload=null;for(var t=0;t<a.pending[G];t++)a.pending[t].dirty()},a={image:i,pending:[this]},i.src=r,o.put(r,a),void(this._image=i);if(i=a.image,this._image=i,!i.width||!i[$])return void a.pending.push(this)}if(i){var s=n.width||i.width,l=n[$]||i[$],u=n.x||0,h=n.y||0;if(!i.width||!i[$])return;if(this.setTransform(t),n.sWidth&&n.sHeight){var f=n.sx||0,d=n.sy||0;t.drawImage(i,f,d,n.sWidth,n.sHeight,u,h,s,l)}else if(n.sx&&n.sy){var f=n.sx,d=n.sy,p=s-f,v=l-d;t.drawImage(i,f,d,p,v,u,h,s,l)}else t.drawImage(i,u,h,s,l);null==n.width&&(n.width=s),null==n[$]&&(n[$]=l),this.restoreTransform(t),null!=n.text&&this.drawRectText(t,this[c]())}},getBoundingRect:function(){var t=this.style;return this._rect||(this._rect=new n(t.x||0,t.y||0,t.width||0,t[$]||0)),this._rect}},r[S](e,i),e}),e("zrender/graphic/Text",[ne,"./Displayable","../core/util","../contain/text"],function(t){var e=t("./Displayable"),i=t("../core/util"),n=t("../contain/text"),r=function(t){e.call(this,t)};return r[K]={constructor:r,type:"text",brush:function(t,e){var i=this.style,r=i.x||0,a=i.y||0,o=i.text;if(null!=o&&(o+=""),i.bind(t,this,e),o){this.setTransform(t);var s,l=i.textAlign,u=i.textFont||i.font;if(i.textVerticalAlign){var h=n[c](o,u,i.textAlign,"top");switch(s=x,i.textVerticalAlign){case x:a-=h[$]/2-h.lineHeight/2;break;case V:a-=h[$]-h.lineHeight/2;break;default:a+=h.lineHeight/2}}else s=i.textBaseline;t.font=u||"12px sans-serif",t.textAlign=l||"left",t.textAlign!==l&&(t.textAlign="left"),t.textBaseline=s||"alphabetic",t.textBaseline!==s&&(t.textBaseline="alphabetic");for(var f=n.measureText("国",t.font).width,d=o.split("\n"),p=0;p<d[G];p++)i.hasFill()&&t.fillText(d[p],r,a),i.hasStroke()&&t.strokeText(d[p],r,a),a+=f;this.restoreTransform(t)}},getBoundingRect:function(){if(!this._rect){var t=this.style,e=t.textVerticalAlign,i=n[c](t.text+"",t.textFont||t.font,t.textAlign,e?"top":t.textBaseline);switch(e){case x:i.y-=i[$]/2;break;case V:i.y-=i[$]}i.x+=t.x||0,i.y+=t.y||0,this._rect=i}return this._rect}},i[S](r,e),r}),e("zrender/graphic/shape/Circle",[ne,"../Path"],function(t){return t("../Path")[I]({type:"circle",shape:{cx:0,cy:0,r:0},buildPath:function(t,e,i){i&&t[a](e.cx+e.r,e.cy),t.arc(e.cx,e.cy,e.r,0,2*Math.PI,!0)}})}),e("zrender/graphic/shape/Sector",[ne,"../Path"],function(t){return t("../Path")[I]({type:"sector",shape:{cx:0,cy:0,r0:0,r:0,startAngle:0,endAngle:2*Math.PI,clockwise:!0},buildPath:function(t,e){var n=e.cx,r=e.cy,o=Math.max(e.r0||0,0),s=Math.max(e.r,0),l=e.startAngle,u=e.endAngle,c=e.clockwise,h=Math.cos(l),f=Math.sin(l);t[a](h*o+n,f*o+r),t[i](h*s+n,f*s+r),t.arc(n,r,s,l,u,!c),t[i](Math.cos(u)*o+n,Math.sin(u)*o+r),0!==o&&t.arc(n,r,o,u,l,c),t.closePath()}})}),e("zrender/graphic/shape/Ring",[ne,"../Path"],function(t){return t("../Path")[I]({type:"ring",shape:{cx:0,cy:0,r:0,r0:0},buildPath:function(t,e){var i=e.cx,n=e.cy,r=2*Math.PI;t[a](i+e.r,n),t.arc(i,n,e.r,0,r,!1),t[a](i+e.r0,n),t.arc(i,n,e.r0,0,r,!0)}})}),e("zrender/graphic/shape/Polygon",[ne,"../helper/poly","../Path"],function(t){var e=t("../helper/poly");return t("../Path")[I]({type:"polygon",shape:{points:null,smooth:!1,smoothConstraint:null},buildPath:function(t,i){e.buildPath(t,i,!0)}})}),e("zrender/graphic/shape/Polyline",[ne,"../helper/poly","../Path"],function(t){var e=t("../helper/poly");return t("../Path")[I]({type:"polyline",shape:{points:null,smooth:!1,smoothConstraint:null},style:{stroke:"#000",fill:null},buildPath:function(t,i){e.buildPath(t,i,!1)}})}),e("zrender/graphic/shape/Rect",[ne,"../helper/roundRect","../Path"],function(t){var e=t("../helper/roundRect");return t("../Path")[I]({type:"rect",shape:{r:0,x:0,y:0,width:0,height:0},buildPath:function(t,i){var n=i.x,r=i.y,a=i.width,o=i[$];i.r?e.buildPath(t,i):t.rect(n,r,a,o),t.closePath()}})}),e("zrender/graphic/shape/Line",[ne,"../Path"],function(t){return t("../Path")[I]({type:"line",shape:{x1:0,y1:0,x2:0,y2:0,percent:1},style:{stroke:"#000",fill:null},buildPath:function(t,e){var n=e.x1,r=e.y1,o=e.x2,s=e.y2,l=e.percent;0!==l&&(t[a](n,r),1>l&&(o=n*(1-l)+o*l,s=r*(1-l)+s*l),t[i](o,s))},pointAt:function(t){var e=this.shape;return[e.x1*(1-t)+e.x2*t,e.y1*(1-t)+e.y2*t]}})}),e("zrender/graphic/shape/Arc",[ne,"../Path"],function(t){return t("../Path")[I]({type:"arc",shape:{cx:0,cy:0,r:0,startAngle:0,endAngle:2*Math.PI,clockwise:!0},style:{stroke:"#000",fill:null},buildPath:function(t,e){var i=e.cx,n=e.cy,r=Math.max(e.r,0),o=e.startAngle,s=e.endAngle,l=e.clockwise,u=Math.cos(o),c=Math.sin(o);t[a](u*r+i,c*r+n),t.arc(i,n,r,o,s,!l)}})}),e("zrender/graphic/shape/BezierCurve",[ne,"../../core/curve","../../core/vector","../Path"],function(t){function e(t,e,i){var n=t.cpx2,r=t.cpy2;return null===n||null===r?[(i?c:l)(t.x1,t.cpx1,t.cpx2,t.x2,e),(i?c:l)(t.y1,t.cpy1,t.cpy2,t.y2,e)]:[(i?u:s)(t.x1,t.cpx1,t.x2,e),(i?u:s)(t.y1,t.cpy1,t.y2,e)]}var i=t("../../core/curve"),n=t("../../core/vector"),r=i.quadraticSubdivide,o=i.cubicSubdivide,s=i.quadraticAt,l=i.cubicAt,u=i.quadraticDerivativeAt,c=i.cubicDerivativeAt,h=[];return t("../Path")[I]({type:"bezier-curve",shape:{x1:0,y1:0,x2:0,y2:0,cpx1:0,cpy1:0,percent:1},style:{stroke:"#000",fill:null},buildPath:function(t,e){var i=e.x1,n=e.y1,s=e.x2,l=e.y2,u=e.cpx1,c=e.cpy1,f=e.cpx2,d=e.cpy2,p=e.percent;0!==p&&(t[a](i,n),null==f||null==d?(1>p&&(r(i,u,s,p,h),u=h[1],s=h[2],r(n,c,l,p,h),c=h[1],l=h[2]),t.quadraticCurveTo(u,c,s,l)):(1>p&&(o(i,u,f,s,p,h),u=h[1],f=h[2],s=h[3],o(n,c,d,l,p,h),c=h[1],d=h[2],l=h[3]),t.bezierCurveTo(u,c,f,d,s,l)))},pointAt:function(t){return e(this.shape,t,!1)},tangentAt:function(t){var i=e(this.shape,t,!0);return n.normalize(i,i)}})}),e("zrender/graphic/CompoundPath",[ne,"./Path"],function(t){var e=t("./Path");return e[I]({type:"compound",shape:{paths:null},_updatePathDirty:function(){for(var t=this.__dirtyPath,e=this.shape.paths,i=0;i<e[G];i++)t=t||e[i].__dirtyPath;this.__dirtyPath=t,this[n]=this[n]||t},beforeBrush:function(){this._updatePathDirty();for(var t=this.shape.paths||[],e=this.getGlobalScale(),i=0;i<t[G];i++)t[i].path.setScale(e[0],e[1])},buildPath:function(t,e){for(var i=e.paths||[],n=0;n<i[G];n++)i[n].buildPath(t,i[n].shape,!0)},afterBrush:function(){for(var t=this.shape.paths,e=0;e<t[G];e++)t[e].__dirtyPath=!1},getBoundingRect:function(){return this._updatePathDirty(),e[K][c].call(this)}})}),e("zrender/graphic/LinearGradient",[ne,"../core/util","./Gradient"],function(t){var e=t("../core/util"),i=t("./Gradient"),n=function(t,e,n,r,a,o){this.x=null==t?0:t,this.y=null==e?0:e,this.x2=null==n?1:n,this.y2=null==r?0:r,this.type="linear",this.global=o||!1,i.call(this,a)};return n[K]={constructor:n},e[S](n,i),n}),e("zrender/graphic/RadialGradient",[ne,"../core/util","./Gradient"],function(t){var e=t("../core/util"),i=t("./Gradient"),n=function(t,e,n,r,a){this.x=null==t?.5:t,this.y=null==e?.5:e,this.r=null==n?.5:n,this.type="radial",this.global=a||!1,i.call(this,r)};return n[K]={constructor:n},e[S](n,i),n}),e("zrender/core/BoundingRect",[ne,"./vector","./matrix"],function(t){function e(t,e,i,n){0>i&&(t+=i,i=-i),0>n&&(e+=n,n=-n),this.x=t,this.y=e,this.width=i,this[$]=n}var i=t("./vector"),n=t("./matrix"),r=i[u],a=Math.min,o=Math.abs,s=Math.max;return e[K]={constructor:e,union:function(t){var e=a(t.x,this.x),i=a(t.y,this.y);this.width=s(t.x+t.width,this.x+this.width)-e,this[$]=s(t.y+t[$],this.y+this[$])-i,this.x=e,this.y=i},applyTransform:function(){var t=[],e=[];return function(i){i&&(t[0]=this.x,t[1]=this.y,e[0]=this.x+this.width,e[1]=this.y+this[$],r(t,t,i),r(e,e,i),this.x=a(t[0],e[0]),this.y=a(t[1],e[1]),this.width=o(e[0]-t[0]),this[$]=o(e[1]-t[1]))}}(),calculateTransform:function(t){var e=this,i=t.width/e.width,r=t[$]/e[$],a=n[E]();return n.translate(a,a,[-e.x,-e.y]),n.scale(a,a,[i,r]),n.translate(a,a,[t.x,t.y]),a},intersect:function(t){t instanceof e||(t=e[E](t));var i=this,n=i.x,r=i.x+i.width,a=i.y,o=i.y+i[$],s=t.x,l=t.x+t.width,u=t.y,c=t.y+t[$];return!(s>r||n>l||u>o||a>c)},contain:function(t,e){var i=this;return t>=i.x&&t<=i.x+i.width&&e>=i.y&&e<=i.y+i[$]},clone:function(){return new e(this.x,this.y,this.width,this[$])},copy:function(t){this.x=t.x,this.y=t.y,this.width=t.width,this[$]=t[$]},plain:function(){return{x:this.x,y:this.y,width:this.width,height:this[$]}}},e[E]=function(t){return new e(t.x,t.y,t.width,t[$])},e}),e("echarts/model/mixin/colorPalette",[],function(){return{clearColorPalette:function(){this._colorIdx=0,this._colorNameMap={}},getColorFromPalette:function(t,e){e=e||this;var i=e._colorIdx||0,n=e._colorNameMap||(e._colorNameMap={});if(n[t])return n[t];var r=this.get("color",!0)||[];if(r[G]){var a=r[i];return t&&(n[t]=a),e._colorIdx=(i+1)%r[G],a}}}}),e("echarts/model/globalDefault",[],function(){var t="";return typeof navigator!==r&&(t=navigator.platform||""),{color:["#c23531","#2f4554","#61a0a8","#d48265","#91c7ae","#749f83","#ca8622","#bda29a","#6e7074","#546570","#c4ccd3"],textStyle:{fontFamily:t.match(/^Win/)?"Microsoft YaHei":"sans-serif",fontSize:12,fontStyle:"normal",fontWeight:"normal"},blendMode:null,animation:!0,animationDuration:1e3,animationDurationUpdate:300,animationEasing:"exponentialOut",animationEasingUpdate:"cubicOut",animationThreshold:2e3,progressiveThreshold:3e3,progressive:400,hoverLayerThreshold:3e3}}),e("echarts/util/clazz",[ne,ie],function(t){function e(t,e){var i=n.slice(arguments,2);return this.superClass[K][e].apply(t,i)}function i(t,e,i){return this.superClass[K][e].apply(t,i)}var n=t(ie),r={},a=".",o="___EC__COMPONENT__CONTAINER___",s=r.parseClassType=function(t){var e={main:"",sub:""};return t&&(t=t.split(a),e.main=t[0]||"",e.sub=t[1]||""),e};return r.enableClassExtend=function(t,r){t.$constructor=t,t[I]=function(t){var r=this,a=function(){t.$constructor?t.$constructor.apply(this,arguments):r.apply(this,arguments)};return n[I](a[K],t),a[I]=this[I],a.superCall=e,a.superApply=i,n[S](a,this),a.superClass=r,a}},r.enableClassManagement=function(t,e){function i(t){var e=r[t.main];return e&&e[o]||(e=r[t.main]={},e[o]=!0),e}e=e||{};var r={};if(t.registerClass=function(t,e){if(e)if(e=s(e),e.sub){if(e.sub!==o){var n=i(e);n[e.sub]=t}}else r[e.main]=t;return t},t.getClass=function(t,e,i){var n=r[t];if(n&&n[o]&&(n=e?n[e]:null),i&&!n)throw new Error("Component "+t+"."+(e||"")+" not exists. Load it first.");return n},t.getClassesByMainType=function(t){t=s(t);var e=[],i=r[t.main];return i&&i[o]?n.each(i,function(t,i){i!==o&&e.push(t)}):e.push(i),e},t.hasClass=function(t){return t=s(t),!!r[t.main]},t.getAllClassMainTypes=function(){var t=[];return n.each(r,function(e,i){t.push(i)}),t},t.hasSubTypes=function(t){t=s(t);var e=r[t.main];return e&&e[o]},t.parseClassType=s,e.registerWhenExtend){var a=t[I];a&&(t[I]=function(e){var i=a.call(this,e);return t.registerClass(i,e.type)})}return t},r.setReadOnly=function(){},r}),e("echarts/model/mixin/areaStyle",[ne,"./makeStyleMapper"],function(t){return{getAreaStyle:t("./makeStyleMapper")([["fill","color"],["shadowBlur"],["shadowOffsetX"],["shadowOffsetY"],["opacity"],["shadowColor"]])}}),e("echarts/model/mixin/lineStyle",[ne,"./makeStyleMapper"],function(t){var e=t("./makeStyleMapper")([["lineWidth","width"],[h,"color"],["opacity"],["shadowBlur"],["shadowOffsetX"],["shadowOffsetY"],["shadowColor"]]);return{getLineStyle:function(t){var i=e.call(this,t),n=this.getLineDash(i.lineWidth);return n&&(i.lineDash=n),i},getLineDash:function(t){null==t&&(t=1);var e=this.get("type"),i=Math.max(t,2),n=4*t;return"solid"===e||null==e?null:"dashed"===e?[n,n]:[i,i]}}}),e("echarts/model/mixin/textStyle",[ne,"zrender/contain/text"],function(t){function e(t,e){return t&&t[v](e)}var i=t("zrender/contain/text");return{getTextColor:function(){var t=this[s];return this[v]("color")||t&&t.get("textStyle.color")},getFont:function(){var t=this[s],i=t&&t[U](f);return[this[v]("fontStyle")||e(i,"fontStyle"),this[v]("fontWeight")||e(i,"fontWeight"),(this[v]("fontSize")||e(i,"fontSize")||12)+"px",this[v]("fontFamily")||e(i,"fontFamily")||"sans-serif"].join(" ")},getTextRect:function(t){var e=this.get(f)||{};return i[c](t,this.getFont(),e.align,e.baseline)},truncateText:function(t,e,n,r){return i.truncateText(t,e,this.getFont(),n,r)}}}),e("echarts/model/mixin/itemStyle",[ne,"./makeStyleMapper"],function(t){var e=t("./makeStyleMapper")([["fill","color"],[h,"borderColor"],["lineWidth","borderWidth"],["opacity"],["shadowBlur"],["shadowOffsetX"],["shadowOffsetY"],["shadowColor"],["textPosition"],["textAlign"]]);return{getItemStyle:function(t){var i=e.call(this,t),n=this.getBorderLineDash();return n&&(i.lineDash=n),i},getBorderLineDash:function(){var t=this.get("borderType");return"solid"===t||null==t?null:"dashed"===t?[5,5]:[1,1]}}}),e("echarts/data/DataDiffer",[ne],function(){function t(t){return t}function e(e,i,n,r){this._old=e,this._new=i,this._oldKeyGetter=n||t,this._newKeyGetter=r||t}function i(t,e,i,n){for(var r=0;r<t[G];r++){var a=n(t[r],r),o=e[a];null==o?(i.push(a),e[a]=r):(o[G]||(e[a]=o=[o]),o.push(r))}}return e[K]={constructor:e,add:function(t){return this._add=t,this},update:function(t){return this._update=t,this},remove:function(t){return this._remove=t,this},execute:function(){var t,e=this._old,n=this._new,r=this._oldKeyGetter,a=this._newKeyGetter,o={},s={},l=[],u=[];for(i(e,o,l,r),i(n,s,u,a),t=0;t<e[G];t++){var c=l[t],h=s[c];if(null!=h){var f=h[G];f?(1===f&&(s[c]=null),h=h.unshift()):s[c]=null,this._update&&this._update(h,t)}else this._remove&&this._remove(t)}for(var t=0;t<u[G];t++){var c=u[t];if(s.hasOwnProperty(c)){var h=s[c];if(null==h)continue;if(h[G])for(var d=0,f=h[G];f>d;d++)this._add&&this._add(h[d]);else this._add&&this._add(h)}}}},e}),e("zrender/contain/text",[ne,"../core/util","../core/BoundingRect"],function(t){function e(t,e){var i=t+":"+e;if(o[i])return o[i];for(var n=(t+"").split("\n"),r=0,a=0,l=n[G];l>a;a++)r=Math.max(p.measureText(n[a],e).width,r);return s>u&&(s=0,o={}),s++,o[i]=r,r}function i(t,i,n,r){var a=((t||"")+"").split("\n")[G],o=e(t,i),s=e("国",i),l=a*s,u=new h(0,0,o,l);switch(u.lineHeight=s,r){case V:case"alphabetic":u.y-=s;break;case x:u.y-=s/2}switch(n){case"end":case"right":u.x-=u.width;break;case d:u.x-=u.width/2}return u}function n(t,e,i,n){var r=e.x,a=e.y,o=e[$],s=e.width,l=i[$],u=o/2-l/2,c="left";switch(t){case"left":r-=n,a+=u,c="right";break;case"right":r+=n+s,a+=u,c="left";break;case"top":r+=s/2,a-=n+l,c=d;break;case V:r+=s/2,a+=o+n,c=d;break;case"inside":r+=s/2,a+=u,c=d;break;case"insideLeft":r+=n,a+=u,c="left";break;case"insideRight":r+=s-n,a+=u,c="right";break;case"insideTop":r+=s/2,a+=n,c=d;break;case"insideBottom":r+=s/2,a+=o-l-n,c=d;break;case"insideTopLeft":r+=n,a+=n,c="left";break;case"insideTopRight":r+=s-n,a+=n,c="right";break;case"insideBottomLeft":r+=n,a+=o-l-n;break;case"insideBottomRight":r+=s-n,a+=o-l-n,c="right"}return{x:r,y:a,textAlign:c,textBaseline:"top"}}function r(t,i,n,r,o){if(!i)return"";o=o||{},r=f(r,"...");for(var s=f(o.maxIterations,2),l=f(o.minChar,0),u=e("国",n),c=e("a",n),h=f(o.placeholder,""),d=i=Math.max(0,i-1),p=0;l>p&&d>=c;p++)d-=c;var v=e(r);v>d&&(r="",v=0),d=i-v;for(var m=(t+"").split("\n"),p=0,g=m[G];g>p;p++){var y=m[p],_=e(y,n);if(!(i>=_)){for(var x=0;;x++){if(d>=_||x>=s){y+=r;break}var b=0===x?a(y,d,c,u):_>0?Math.floor(y[G]*d/_):0;y=y.substr(0,b),_=e(y,n)}""===y&&(y=h),m[p]=y}}return m.join("\n")}function a(t,e,i,n){for(var r=0,a=0,o=t[G];o>a&&e>r;a++){var s=t.charCodeAt(a);r+=s>=0&&127>=s?i:n}return a}var o={},s=0,u=5e3,c=t("../core/util"),h=t("../core/BoundingRect"),f=c[l],p={getWidth:e,getBoundingRect:i,adjustTextPositionOnRect:n,truncateText:r,measureText:function(t,e){var i=c.getContext();return i.font=e||"12px sans-serif",i.measureText(t)}};return p}),e("zrender/core/PathProxy",[ne,"./curve","./vector","./bbox","./BoundingRect","../config"],function(t){var e=t("./curve"),n=t("./vector"),o=t("./bbox"),s=t("./BoundingRect"),l=t("../config").devicePixelRatio,u={M:1,L:2,C:3,Q:4,A:5,Z:6,R:7},c=[],f=[],d=[],p=[],v=Math.min,m=Math.max,g=Math.cos,y=Math.sin,_=Math.sqrt,x=Math.abs,b=typeof Float32Array!=r,w=function(){this.data=[],this._len=0,this._ctx=null,this._xi=0,this._yi=0,this._x0=0,this._y0=0,this._ux=0,this._uy=0};return w[K]={constructor:w,_lineDash:null,_dashOffset:0,_dashIdx:0,_dashSum:0,setScale:function(t,e){this._ux=x(1/l/t)||0,this._uy=x(1/l/e)||0},getContext:function(){return this._ctx},beginPath:function(t){return this._ctx=t,t&&t.beginPath(),t&&(this.dpr=t.dpr),this._len=0,this._lineDash&&(this._lineDash=null,this._dashOffset=0),this},moveTo:function(t,e){return this.addData(u.M,t,e),this._ctx&&this._ctx[a](t,e),this._x0=t,this._y0=e,this._xi=t,this._yi=e,this},lineTo:function(t,e){var n=x(t-this._xi)>this._ux||x(e-this._yi)>this._uy||this._len<5;return this.addData(u.L,t,e),this._ctx&&n&&(this._needsDash()?this._dashedLineTo(t,e):this._ctx[i](t,e)),n&&(this._xi=t,this._yi=e),this},bezierCurveTo:function(t,e,i,n,r,a){return this.addData(u.C,t,e,i,n,r,a),this._ctx&&(this._needsDash()?this._dashedBezierTo(t,e,i,n,r,a):this._ctx.bezierCurveTo(t,e,i,n,r,a)),this._xi=r,this._yi=a,this},quadraticCurveTo:function(t,e,i,n){return this.addData(u.Q,t,e,i,n),this._ctx&&(this._needsDash()?this._dashedQuadraticTo(t,e,i,n):this._ctx.quadraticCurveTo(t,e,i,n)),this._xi=i,this._yi=n,this},arc:function(t,e,i,n,r,a){return this.addData(u.A,t,e,i,i,n,r-n,0,a?0:1),this._ctx&&this._ctx.arc(t,e,i,n,r,a),this._xi=g(r)*i+t,this._xi=y(r)*i+t,this},arcTo:function(t,e,i,n,r){return this._ctx&&this._ctx.arcTo(t,e,i,n,r),this},rect:function(t,e,i,n){return this._ctx&&this._ctx.rect(t,e,i,n),this.addData(u.R,t,e,i,n),this},closePath:function(){this.addData(u.Z);var t=this._ctx,e=this._x0,i=this._y0;return t&&(this._needsDash()&&this._dashedLineTo(e,i),t.closePath()),this._xi=e,this._yi=i,this},fill:function(t){t&&t.fill(),this.toStatic()},stroke:function(t){t&&t[h](),this.toStatic()},setLineDash:function(t){if(t instanceof Array){this._lineDash=t,this._dashIdx=0;for(var e=0,i=0;i<t[G];i++)e+=t[i];this._dashSum=e}return this},setLineDashOffset:function(t){return this._dashOffset=t,this},len:function(){return this._len},setData:function(t){var e=t[G];this.data&&this.data[G]==e||!b||(this.data=new Float32Array(e));for(var i=0;e>i;i++)this.data[i]=t[i];this._len=e},appendPath:function(t){t instanceof Array||(t=[t]);for(var e=t[G],i=0,n=this._len,r=0;e>r;r++)i+=t[r].len();b&&this.data instanceof Float32Array&&(this.data=new Float32Array(n+i));
for(var r=0;e>r;r++)for(var a=t[r].data,o=0;o<a[G];o++)this.data[n++]=a[o];this._len=n},addData:function(t){var e=this.data;this._len+arguments[G]>e[G]&&(this._expandData(),e=this.data);for(var i=0;i<arguments[G];i++)e[this._len++]=arguments[i];this._prevCmd=t},_expandData:function(){if(!(this.data instanceof Array)){for(var t=[],e=0;e<this._len;e++)t[e]=this.data[e];this.data=t}},_needsDash:function(){return this._lineDash},_dashedLineTo:function(t,e){var n,r,o=this._dashSum,s=this._dashOffset,l=this._lineDash,u=this._ctx,c=this._xi,h=this._yi,f=t-c,d=e-h,p=_(f*f+d*d),g=c,y=h,x=l[G];for(f/=p,d/=p,0>s&&(s=o+s),s%=o,g-=s*f,y-=s*d;f>0&&t>=g||0>f&&g>=t||0==f&&(d>0&&e>=y||0>d&&y>=e);)r=this._dashIdx,n=l[r],g+=f*n,y+=d*n,this._dashIdx=(r+1)%x,f>0&&c>g||0>f&&g>c||d>0&&h>y||0>d&&y>h||u[r%2?a:i](f>=0?v(g,t):m(g,t),d>=0?v(y,e):m(y,e));f=g-t,d=y-e,this._dashOffset=-_(f*f+d*d)},_dashedBezierTo:function(t,n,r,o,s,l){var u,c,h,f,d,p=this._dashSum,v=this._dashOffset,m=this._lineDash,g=this._ctx,y=this._xi,x=this._yi,b=e.cubicAt,w=0,M=this._dashIdx,T=m[G],S=0;for(0>v&&(v=p+v),v%=p,u=0;1>u;u+=.1)c=b(y,t,r,s,u+.1)-b(y,t,r,s,u),h=b(x,n,o,l,u+.1)-b(x,n,o,l,u),w+=_(c*c+h*h);for(;T>M&&(S+=m[M],!(S>v));M++);for(u=(S-v)/w;1>=u;)f=b(y,t,r,s,u),d=b(x,n,o,l,u),M%2?g[a](f,d):g[i](f,d),u+=m[M]/w,M=(M+1)%T;M%2!==0&&g[i](s,l),c=s-f,h=l-d,this._dashOffset=-_(c*c+h*h)},_dashedQuadraticTo:function(t,e,i,n){var r=i,a=n;i=(i+2*t)/3,n=(n+2*e)/3,t=(this._xi+2*t)/3,e=(this._yi+2*e)/3,this._dashedBezierTo(t,e,i,n,r,a)},toStatic:function(){var t=this.data;t instanceof Array&&(t[G]=this._len,b&&(this.data=new Float32Array(t)))},getBoundingRect:function(){c[0]=c[1]=d[0]=d[1]=Number.MAX_VALUE,f[0]=f[1]=p[0]=p[1]=-Number.MAX_VALUE;for(var t=this.data,e=0,i=0,r=0,a=0,l=0;l<t[G];){var h=t[l++];switch(1==l&&(e=t[l],i=t[l+1],r=e,a=i),h){case u.M:r=t[l++],a=t[l++],e=r,i=a,d[0]=r,d[1]=a,p[0]=r,p[1]=a;break;case u.L:o.fromLine(e,i,t[l],t[l+1],d,p),e=t[l++],i=t[l++];break;case u.C:o.fromCubic(e,i,t[l++],t[l++],t[l++],t[l++],t[l],t[l+1],d,p),e=t[l++],i=t[l++];break;case u.Q:o.fromQuadratic(e,i,t[l++],t[l++],t[l],t[l+1],d,p),e=t[l++],i=t[l++];break;case u.A:var v=t[l++],m=t[l++],_=t[l++],x=t[l++],b=t[l++],w=t[l++]+b,M=(t[l++],1-t[l++]);1==l&&(r=g(b)*_+v,a=y(b)*x+m),o.fromArc(v,m,_,x,b,w,M,d,p),e=g(w)*_+v,i=y(w)*x+m;break;case u.R:r=e=t[l++],a=i=t[l++];var T=t[l++],S=t[l++];o.fromLine(r,a,r+T,a+S,d,p);break;case u.Z:e=r,i=a}n.min(c,c,d),n.max(f,f,p)}return 0===l&&(c[0]=c[1]=f[0]=f[1]=0),new s(c[0],c[1],f[0]-c[0],f[1]-c[1])},rebuildPath:function(t){for(var e,n,r,o,s,l,c=this.data,h=this._ux,f=this._uy,d=this._len,p=0;d>p;){var v=c[p++];switch(1==p&&(r=c[p],o=c[p+1],e=r,n=o),v){case u.M:e=r=c[p++],n=o=c[p++],t[a](r,o);break;case u.L:s=c[p++],l=c[p++],(x(s-r)>h||x(l-o)>f||p===d-1)&&(t[i](s,l),r=s,o=l);break;case u.C:t.bezierCurveTo(c[p++],c[p++],c[p++],c[p++],c[p++],c[p++]),r=c[p-2],o=c[p-1];break;case u.Q:t.quadraticCurveTo(c[p++],c[p++],c[p++],c[p++]),r=c[p-2],o=c[p-1];break;case u.A:var m=c[p++],_=c[p++],b=c[p++],w=c[p++],M=c[p++],T=c[p++],S=c[p++],C=c[p++],P=b>w?b:w,A=b>w?1:b/w,L=b>w?w/b:1,z=Math.abs(b-w)>.001,k=M+T;z?(t.translate(m,_),t.rotate(S),t.scale(A,L),t.arc(0,0,P,M,k,1-C),t.scale(1/A,1/L),t.rotate(-S),t.translate(-m,-_)):t.arc(m,_,P,M,k,1-C),1==p&&(e=g(M)*b+m,n=y(M)*w+_),r=g(k)*b+m,o=y(k)*w+_;break;case u.R:e=r=c[p],n=o=c[p+1],t.rect(c[p++],c[p++],c[p++],c[p++]);break;case u.Z:t.closePath(),r=e,o=n}}}},w.CMD=u,w}),e("zrender/graphic/mixin/RectText",[ne,"../../contain/text","../../core/BoundingRect"],function(t){function e(t,e){return typeof t===Q?t.lastIndexOf("%")>=0?parseFloat(t)/100*e:parseFloat(t):t}var i=t("../../contain/text"),n=t("../../core/BoundingRect"),r=new n,a=function(){};return a[K]={constructor:a,drawRectText:function(t,n,a){var s=this.style,l=s.text;if(null!=l&&(l+=""),l){t.save();var h,f,d=s.textPosition,p=s.textDistance,v=s.textAlign,m=s.textFont||s.font,g=s.textBaseline,y=s.textVerticalAlign;a=a||i[c](l,m,v,g);var _=this[o];if(s.textTransform?this.setTransform(t):_&&(r.copy(n),r[u](_),n=r),d instanceof Array){if(h=n.x+e(d[0],n.width),f=n.y+e(d[1],n[$]),v=v||"left",g=g||"top",y){switch(y){case x:f-=a[$]/2-a.lineHeight/2;break;case V:f-=a[$]-a.lineHeight/2;break;default:f+=a.lineHeight/2}g=x}}else{var b=i.adjustTextPositionOnRect(d,n,a,p);h=b.x,f=b.y,v=v||b.textAlign,g=g||b.textBaseline}t.textAlign=v||"left",t.textBaseline=g||"alphabetic";var w=s.textFill,M=s.textStroke;w&&(t.fillStyle=w),M&&(t.strokeStyle=M),t.font=m||"12px sans-serif",t.shadowBlur=s.textShadowBlur,t.shadowColor=s.textShadowColor||"transparent",t.shadowOffsetX=s.textShadowOffsetX,t.shadowOffsetY=s.textShadowOffsetY;var T=l.split("\n");s.textRotation&&(_&&t.translate(_[4],_[5]),t.rotate(s.textRotation),_&&t.translate(-_[4],-_[5]));for(var S=0;S<T[G];S++)w&&t.fillText(T[S],h,f),M&&t.strokeText(T[S],h,f),f+=a.lineHeight;t.restore()}}},a}),e("zrender/graphic/Displayable",[ne,"../core/util","./Style","../Element","./mixin/RectText"],function(t){function e(t){t=t||{},a.call(this,t);for(var e in t)t.hasOwnProperty(e)&&"style"!==e&&(this[e]=t[e]);this.style=new r(t.style),this._rect=null,this.__clipPaths=[]}var i=t("../core/util"),r=t("./Style"),a=t("../Element"),o=t("./mixin/RectText");return e[K]={constructor:e,type:"displayable",__dirty:!0,invisible:!1,z:0,z2:0,zlevel:0,draggable:!1,dragging:!1,silent:!1,culling:!1,cursor:"pointer",rectHover:!1,progressive:-1,beforeBrush:function(){},afterBrush:function(){},brush:function(){},getBoundingRect:function(){},contain:function(t,e){return this.rectContain(t,e)},traverse:function(t,e){t.call(e,this)},rectContain:function(t,e){var i=this.transformCoordToLocal(t,e),n=this[c]();return n[T](i[0],i[1])},dirty:function(){this[n]=!0,this._rect=null,this.__zr&&this.__zr.refresh()},animateStyle:function(t){return this.animate("style",t)},attrKV:function(t,e){"style"!==t?a[K].attrKV.call(this,t,e):this.style.set(e)},setStyle:function(t,e){return this.style.set(t,e),this.dirty(!1),this},useStyle:function(t){return this.style=new r(t),this.dirty(!1),this}},i[S](e,a),i.mixin(e,o),e}),e("zrender/vml/core",[ne,"exports","module","../core/env"],function(t,e,i){if(!t("../core/env")[W]){var n,r="urn:schemas-microsoft-com:vml",a=window,o=a.document,s=!1;try{!o.namespaces.zrvml&&o.namespaces.add("zrvml",r),n=function(t){return o[w]("<zrvml:"+t+' class="zrvml">')}}catch(l){n=function(t){return o[w]("<"+t+' xmlns="'+r+'" class="zrvml">')}}var u=function(){if(!s){s=!0;var t=o.styleSheets;t[G]<31?o.createStyleSheet().addRule(".zrvml","behavior:url(#default#VML)"):t[0].addRule(".zrvml","behavior:url(#default#VML)")}};i.exports={doc:o,initVML:u,createNode:n}}}),e("zrender/tool/transformPath",[ne,"../core/PathProxy","../core/vector"],function(t){function e(t,e){var n,l,u,c,h,f,d=t.data,p=i.M,v=i.C,m=i.L,g=i.R,y=i.A,_=i.Q;for(u=0,c=0;u<d[G];){switch(n=d[u++],c=u,l=0,n){case p:l=1;break;case m:l=1;break;case v:l=3;break;case _:l=2;break;case y:var x=e[4],b=e[5],w=o(e[0]*e[0]+e[1]*e[1]),M=o(e[2]*e[2]+e[3]*e[3]),T=s(-e[1]/M,e[0]/w);d[u++]+=x,d[u++]+=b,d[u++]*=w,d[u++]*=M,d[u++]+=T,d[u++]+=T,u+=2,c=u;break;case g:f[0]=d[u++],f[1]=d[u++],r(f,f,e),d[c++]=f[0],d[c++]=f[1],f[0]+=d[u++],f[1]+=d[u++],r(f,f,e),d[c++]=f[0],d[c++]=f[1]}for(h=0;l>h;h++){var f=a[h];f[0]=d[u++],f[1]=d[u++],r(f,f,e),d[c++]=f[0],d[c++]=f[1]}}}var i=t("../core/PathProxy").CMD,n=t("../core/vector"),r=n[u],a=[[],[],[]],o=Math.sqrt,s=Math.atan2;return e}),e("zrender/contain/path",[ne,"../core/PathProxy","./line","./cubic","./quadratic","./arc","./util","../core/curve","./windingLine"],function(t){function e(t,e){return Math.abs(t-e)<g}function i(){var t=_[0];_[0]=_[1],_[1]=t}function n(t,e,n,r,a,o,s,l,u,c){if(c>e&&c>r&&c>o&&c>l||e>c&&r>c&&o>c&&l>c)return 0;var h=d.cubicRootAt(e,r,o,l,c,y);if(0===h)return 0;for(var f,p,v=0,m=-1,g=0;h>g;g++){var x=y[g],b=0===x||1===x?.5:1,w=d.cubicAt(t,n,a,s,x);u>w||(0>m&&(m=d.cubicExtrema(e,r,o,l,_),_[1]<_[0]&&m>1&&i(),f=d.cubicAt(e,r,o,l,_[0]),m>1&&(p=d.cubicAt(e,r,o,l,_[1]))),v+=2==m?x<_[0]?e>f?b:-b:x<_[1]?f>p?b:-b:p>l?b:-b:x<_[0]?e>f?b:-b:f>l?b:-b)}return v}function r(t,e,i,n,r,a,o,s){if(s>e&&s>n&&s>a||e>s&&n>s&&a>s)return 0;var l=d.quadraticRootAt(e,n,a,s,y);if(0===l)return 0;var u=d.quadraticExtremum(e,n,a);if(u>=0&&1>=u){for(var c=0,h=d.quadraticAt(e,n,a,u),f=0;l>f;f++){var p=0===y[f]||1===y[f]?.5:1,v=d.quadraticAt(t,i,r,y[f]);o>v||(c+=y[f]<u?e>h?p:-p:h>a?p:-p)}return c}var p=0===y[0]||1===y[0]?.5:1,v=d.quadraticAt(t,i,r,y[0]);return o>v?0:e>a?p:-p}function a(t,e,i,n,r,a,o,s){if(s-=e,s>i||-i>s)return 0;var l=Math.sqrt(i*i-s*s);y[0]=-l,y[1]=l;var u=Math.abs(n-r);if(1e-4>u)return 0;if(1e-4>u%m){n=0,r=m;var c=a?1:-1;return o>=y[0]+t&&o<=y[1]+t?c:0}if(a){var l=n;n=f(r),r=f(l)}else n=f(n),r=f(r);n>r&&(r+=m);for(var h=0,d=0;2>d;d++){var p=y[d];if(p+t>o){var v=Math.atan2(s,p),c=a?1:-1;0>v&&(v=m+v),(v>=n&&r>=v||v+m>=n&&r>=v+m)&&(v>Math.PI/2&&v<1.5*Math.PI&&(c=-c),h+=c)}}return h}function o(t,i,o,l,f){for(var d=0,m=0,g=0,y=0,_=0,x=0;x<t[G];){var b=t[x++];switch(b===s.M&&x>1&&(o||(d+=p(m,g,y,_,l,f))),1==x&&(m=t[x],g=t[x+1],y=m,_=g),b){case s.M:y=t[x++],_=t[x++],m=y,g=_;break;case s.L:if(o){if(v(m,g,t[x],t[x+1],i,l,f))return!0}else d+=p(m,g,t[x],t[x+1],l,f)||0;m=t[x++],g=t[x++];break;case s.C:if(o){if(u.containStroke(m,g,t[x++],t[x++],t[x++],t[x++],t[x],t[x+1],i,l,f))return!0}else d+=n(m,g,t[x++],t[x++],t[x++],t[x++],t[x],t[x+1],l,f)||0;m=t[x++],g=t[x++];break;case s.Q:if(o){if(c.containStroke(m,g,t[x++],t[x++],t[x],t[x+1],i,l,f))return!0}else d+=r(m,g,t[x++],t[x++],t[x],t[x+1],l,f)||0;m=t[x++],g=t[x++];break;case s.A:var w=t[x++],M=t[x++],T=t[x++],S=t[x++],C=t[x++],P=t[x++],A=(t[x++],1-t[x++]),L=Math.cos(C)*T+w,z=Math.sin(C)*S+M;x>1?d+=p(m,g,L,z,l,f):(y=L,_=z);var k=(l-w)*S/T+w;if(o){if(h.containStroke(w,M,S,C,C+P,A,i,k,f))return!0}else d+=a(w,M,S,C,C+P,A,k,f);m=Math.cos(C+P)*T+w,g=Math.sin(C+P)*S+M;break;case s.R:y=m=t[x++],_=g=t[x++];var I=t[x++],D=t[x++],L=y+I,z=_+D;if(o){if(v(y,_,L,_,i,l,f)||v(L,_,L,z,i,l,f)||v(L,z,y,z,i,l,f)||v(y,z,y,_,i,l,f))return!0}else d+=p(L,_,L,z,l,f),d+=p(y,z,y,_,l,f);break;case s.Z:if(o){if(v(m,g,y,_,i,l,f))return!0}else d+=p(m,g,y,_,l,f);m=y,g=_}}return o||e(g,_)||(d+=p(m,g,y,_,l,f)||0),0!==d}var s=t("../core/PathProxy").CMD,l=t("./line"),u=t("./cubic"),c=t("./quadratic"),h=t("./arc"),f=t("./util").normalizeRadian,d=t("../core/curve"),p=t("./windingLine"),v=l.containStroke,m=2*Math.PI,g=1e-4,y=[-1,-1,-1],_=[-1,-1];return{contain:function(t,e,i){return o(t,0,!1,e,i)},containStroke:function(t,e,i,n){return o(t,e,!0,i,n)}}}),e("zrender/graphic/Pattern",[ne],function(){var t=function(t,e){this.image=t,this.repeat=e,this.type="pattern"};return t[K].getCanvasPattern=function(t){return this._canvasPattern||(this._canvasPattern=t.createPattern(this.image,this.repeat))},t}),e("echarts/model/mixin/makeStyleMapper",[ne,ie],function(t){var e=t(ie);return function(t){for(var i=0;i<t[G];i++)t[i][1]||(t[i][1]=t[i][0]);return function(i){for(var n={},r=0;r<t[G];r++){var a=t[r][1];if(!(i&&e[F](i,a)>=0)){var o=this[v](a);null!=o&&(n[t[r][0]]=o)}}return n}}}),e("zrender/core/curve",[ne,"./vector"],function(t){function e(t){return t>-x&&x>t}function i(t){return t>x||-x>t}function n(t,e,i,n,r){var a=1-r;return a*a*(a*t+3*r*e)+r*r*(r*n+3*a*i)}function r(t,e,i,n,r){var a=1-r;return 3*(((e-t)*a+2*(i-e)*r)*a+(n-i)*r*r)}function a(t,i,n,r,a,o){var s=r+3*(i-n)-t,l=3*(n-2*i+t),u=3*(i-t),c=t-a,h=l*l-3*s*u,f=l*u-9*s*c,d=u*u-3*l*c,p=0;if(e(h)&&e(f))if(e(l))o[0]=0;else{var v=-u/l;v>=0&&1>=v&&(o[p++]=v)}else{var m=f*f-4*h*d;if(e(m)){var g=f/h,v=-l/s+g,x=-g/2;v>=0&&1>=v&&(o[p++]=v),x>=0&&1>=x&&(o[p++]=x)}else if(m>0){var b=_(m),T=h*l+1.5*s*(-f+b),S=h*l+1.5*s*(-f-b);T=0>T?-y(-T,M):y(T,M),S=0>S?-y(-S,M):y(S,M);var v=(-l-(T+S))/(3*s);v>=0&&1>=v&&(o[p++]=v)}else{var C=(2*h*l-3*s*f)/(2*_(h*h*h)),P=Math.acos(C)/3,A=_(h),L=Math.cos(P),v=(-l-2*A*L)/(3*s),x=(-l+A*(L+w*Math.sin(P)))/(3*s),z=(-l+A*(L-w*Math.sin(P)))/(3*s);v>=0&&1>=v&&(o[p++]=v),x>=0&&1>=x&&(o[p++]=x),z>=0&&1>=z&&(o[p++]=z)}}return p}function o(t,n,r,a,o){var s=6*r-12*n+6*t,l=9*n+3*a-3*t-9*r,u=3*n-3*t,c=0;if(e(l)){if(i(s)){var h=-u/s;h>=0&&1>=h&&(o[c++]=h)}}else{var f=s*s-4*l*u;if(e(f))o[0]=-s/(2*l);else if(f>0){var d=_(f),h=(-s+d)/(2*l),p=(-s-d)/(2*l);h>=0&&1>=h&&(o[c++]=h),p>=0&&1>=p&&(o[c++]=p)}}return c}function s(t,e,i,n,r,a){var o=(e-t)*r+t,s=(i-e)*r+e,l=(n-i)*r+i,u=(s-o)*r+o,c=(l-s)*r+s,h=(c-u)*r+u;a[0]=t,a[1]=o,a[2]=u,a[3]=h,a[4]=h,a[5]=c,a[6]=l,a[7]=n}function l(t,e,i,r,a,o,s,l,u,c,h){var f,d,p,v,m,y=.005,x=1/0;T[0]=u,T[1]=c;for(var w=0;1>w;w+=.05)S[0]=n(t,i,a,s,w),S[1]=n(e,r,o,l,w),v=g(T,S),x>v&&(f=w,x=v);x=1/0;for(var M=0;32>M&&!(b>y);M++)d=f-y,p=f+y,S[0]=n(t,i,a,s,d),S[1]=n(e,r,o,l,d),v=g(S,T),d>=0&&x>v?(f=d,x=v):(C[0]=n(t,i,a,s,p),C[1]=n(e,r,o,l,p),m=g(C,T),1>=p&&x>m?(f=p,x=m):y*=.5);return h&&(h[0]=n(t,i,a,s,f),h[1]=n(e,r,o,l,f)),_(x)}function u(t,e,i,n){var r=1-n;return r*(r*t+2*n*e)+n*n*i}function c(t,e,i,n){return 2*((1-n)*(e-t)+n*(i-e))}function h(t,n,r,a,o){var s=t-2*n+r,l=2*(n-t),u=t-a,c=0;if(e(s)){if(i(l)){var h=-u/l;h>=0&&1>=h&&(o[c++]=h)}}else{var f=l*l-4*s*u;if(e(f)){var h=-l/(2*s);h>=0&&1>=h&&(o[c++]=h)}else if(f>0){var d=_(f),h=(-l+d)/(2*s),p=(-l-d)/(2*s);h>=0&&1>=h&&(o[c++]=h),p>=0&&1>=p&&(o[c++]=p)}}return c}function f(t,e,i){var n=t+i-2*e;return 0===n?.5:(t-e)/n}function d(t,e,i,n,r){var a=(e-t)*n+t,o=(i-e)*n+e,s=(o-a)*n+a;r[0]=t,r[1]=a,r[2]=s,r[3]=s,r[4]=o,r[5]=i}function p(t,e,i,n,r,a,o,s,l){var c,h=.005,f=1/0;T[0]=o,T[1]=s;for(var d=0;1>d;d+=.05){S[0]=u(t,i,r,d),S[1]=u(e,n,a,d);var p=g(T,S);f>p&&(c=d,f=p)}f=1/0;for(var v=0;32>v&&!(b>h);v++){var m=c-h,y=c+h;S[0]=u(t,i,r,m),S[1]=u(e,n,a,m);var p=g(S,T);if(m>=0&&f>p)c=m,f=p;else{C[0]=u(t,i,r,y),C[1]=u(e,n,a,y);var x=g(C,T);1>=y&&f>x?(c=y,f=x):h*=.5}}return l&&(l[0]=u(t,i,r,c),l[1]=u(e,n,a,c)),_(f)}var v=t("./vector"),m=v[E],g=v.distSquare,y=Math.pow,_=Math.sqrt,x=1e-8,b=1e-4,w=_(3),M=1/3,T=m(),S=m(),C=m();return{cubicAt:n,cubicDerivativeAt:r,cubicRootAt:a,cubicExtrema:o,cubicSubdivide:s,cubicProjectPoint:l,quadraticAt:u,quadraticDerivativeAt:c,quadraticRootAt:h,quadraticExtremum:f,quadraticSubdivide:d,quadraticProjectPoint:p}}),e("zrender/core/bbox",[ne,"./vector","./curve"],function(t){var e=t("./vector"),i=t("./curve"),n={},r=Math.min,a=Math.max,o=Math.sin,s=Math.cos,l=e[E](),u=e[E](),c=e[E](),h=2*Math.PI;n.fromPoints=function(t,e,i){if(0!==t[G]){var n,o=t[0],s=o[0],l=o[0],u=o[1],c=o[1];for(n=1;n<t[G];n++)o=t[n],s=r(s,o[0]),l=a(l,o[0]),u=r(u,o[1]),c=a(c,o[1]);e[0]=s,e[1]=u,i[0]=l,i[1]=c}},n.fromLine=function(t,e,i,n,o,s){o[0]=r(t,i),o[1]=r(e,n),s[0]=a(t,i),s[1]=a(e,n)};var f=[],d=[];return n.fromCubic=function(t,e,n,o,s,l,u,c,h,p){var v,m=i.cubicExtrema,g=i.cubicAt,y=m(t,n,s,u,f);for(h[0]=1/0,h[1]=1/0,p[0]=-1/0,p[1]=-1/0,v=0;y>v;v++){var _=g(t,n,s,u,f[v]);h[0]=r(_,h[0]),p[0]=a(_,p[0])}for(y=m(e,o,l,c,d),v=0;y>v;v++){var x=g(e,o,l,c,d[v]);h[1]=r(x,h[1]),p[1]=a(x,p[1])}h[0]=r(t,h[0]),p[0]=a(t,p[0]),h[0]=r(u,h[0]),p[0]=a(u,p[0]),h[1]=r(e,h[1]),p[1]=a(e,p[1]),h[1]=r(c,h[1]),p[1]=a(c,p[1])},n.fromQuadratic=function(t,e,n,o,s,l,u,c){var h=i.quadraticExtremum,f=i.quadraticAt,d=a(r(h(t,n,s),1),0),p=a(r(h(e,o,l),1),0),v=f(t,n,s,d),m=f(e,o,l,p);u[0]=r(t,s,v),u[1]=r(e,l,m),c[0]=a(t,s,v),c[1]=a(e,l,m)},n.fromArc=function(t,i,n,r,a,f,d,p,v){var m=e.min,g=e.max,y=Math.abs(a-f);if(1e-4>y%h&&y>1e-4)return p[0]=t-n,p[1]=i-r,v[0]=t+n,void(v[1]=i+r);if(l[0]=s(a)*n+t,l[1]=o(a)*r+i,u[0]=s(f)*n+t,u[1]=o(f)*r+i,m(p,l,u),g(v,l,u),a%=h,0>a&&(a+=h),f%=h,0>f&&(f+=h),a>f&&!d?f+=h:f>a&&d&&(a+=h),d){var _=f;f=a,a=_}for(var x=0;f>x;x+=Math.PI/2)x>a&&(c[0]=s(x)*n+t,c[1]=o(x)*r+i,m(p,c,p),g(v,c,v))},n}),e("zrender/config",[],function(){var t=1;typeof window!==r&&(t=Math.max(window.devicePixelRatio||1,1));var e={debugMode:0,devicePixelRatio:t};return e}),e("zrender/graphic/Style",[ne],function(){function t(t,e,i){var n=e.x,r=e.x2,a=e.y,o=e.y2;e.global||(n=n*i.width+i.x,r=r*i.width+i.x,a=a*i[$]+i.y,o=o*i[$]+i.y);var s=t.createLinearGradient(n,a,r,o);return s}function e(t,e,i){var n=i.width,r=i[$],a=Math.min(n,r),o=e.x,s=e.y,l=e.r;e.global||(o=o*n+i.x,s=s*r+i.y,l*=a);var u=t.createRadialGradient(o,s,0,o,s,l);return u}var i=[["shadowBlur",0],["shadowOffsetX",0],["shadowOffsetY",0],["shadowColor","#000"],["lineCap","butt"],["lineJoin","miter"],["miterLimit",10]],n=function(t){this.extendFrom(t)};n[K]={constructor:n,fill:"#000000",stroke:null,opacity:1,lineDash:null,lineDashOffset:0,shadowBlur:0,shadowOffsetX:0,shadowOffsetY:0,lineWidth:1,strokeNoScale:!1,text:null,textFill:"#000",textStroke:null,textPosition:"inside",textBaseline:null,textAlign:null,textVerticalAlign:null,textDistance:5,textShadowBlur:0,textShadowOffsetX:0,textShadowOffsetY:0,textTransform:!1,textRotation:0,blend:null,bind:function(t,e,n){for(var r=this,a=n&&n.style,o=!a,s=0;s<i[G];s++){var l=i[s],u=l[0];(o||r[u]!==a[u])&&(t[u]=r[u]||l[1])}if((o||r.fill!==a.fill)&&(t.fillStyle=r.fill),(o||r[h]!==a[h])&&(t.strokeStyle=r[h]),(o||r.opacity!==a.opacity)&&(t.globalAlpha=null==r.opacity?1:r.opacity),(o||r.blend!==a.blend)&&(t.globalCompositeOperation=r.blend||"source-over"),this.hasStroke()){var c=r.lineWidth;t.lineWidth=c/(this.strokeNoScale&&e&&e.getLineScale?e.getLineScale():1)}},hasFill:function(){var t=this.fill;return null!=t&&"none"!==t},hasStroke:function(){var t=this[h];return null!=t&&"none"!==t&&this.lineWidth>0},extendFrom:function(t,e){if(t){var i=this;for(var n in t)!t.hasOwnProperty(n)||!e&&i.hasOwnProperty(n)||(i[n]=t[n])}},set:function(t,e){typeof t===Q?this[t]=e:this.extendFrom(t,!0)},clone:function(){var t=new this.constructor;return t.extendFrom(this,!0),t},getGradient:function(i,n,r){for(var a="radial"===n.type?e:t,o=a(i,n,r),s=n.colorStops,l=0;l<s[G];l++)o.addColorStop(s[l].offset,s[l].color);return o}};for(var r=n[K],a=0;a<i[G];a++){var o=i[a];o[0]in r||(r[o[0]]=o[1])}return n.getGradient=r.getGradient,n}),e("zrender/Element",[ne,"./core/guid","./mixin/Eventful","./mixin/Transformable","./mixin/Animatable","./core/util"],function(t){var e=t("./core/guid"),i=t("./mixin/Eventful"),n=t("./mixin/Transformable"),r=t("./mixin/Animatable"),a=t("./core/util"),s=function(t){n.call(this,t),i.call(this,t),r.call(this,t),this.id=t.id||e()};return s[K]={type:"element",name:"",__zr:null,ignore:!1,clipPath:null,drift:function(t,e){switch(this.draggable){case"horizontal":e=0;break;case"vertical":t=0}var i=this[o];i||(i=this[o]=[1,0,0,1,0,0]),i[4]+=t,i[5]+=e,this.decomposeTransform(),this.dirty(!1)},beforeUpdate:function(){},afterUpdate:function(){},update:function(){this.updateTransform()},traverse:function(){},attrKV:function(t,e){if(t===y||"scale"===t||"origin"===t){if(e){var i=this[t];i||(i=this[t]=[]),i[0]=e[0],i[1]=e[1]}}else this[t]=e},hide:function(){this[H]=!0,this.__zr&&this.__zr.refresh()},show:function(){this[H]=!1,this.__zr&&this.__zr.refresh()},attr:function(t,e){if(typeof t===Q)this.attrKV(t,e);else if(a[D](t))for(var i in t)t.hasOwnProperty(i)&&this.attrKV(i,t[i]);return this.dirty(!1),this},setClipPath:function(t){var e=this.__zr;e&&t.addSelfToZr(e),this.clipPath&&this.clipPath!==t&&this.removeClipPath(),this.clipPath=t,t.__zr=e,t.__clipTarget=this,this.dirty(!1)},removeClipPath:function(){var t=this.clipPath;t&&(t.__zr&&t.removeSelfFromZr(t.__zr),t.__zr=null,t.__clipTarget=null,this.clipPath=null,this.dirty(!1))},addSelfToZr:function(t){this.__zr=t;var e=this.animators;if(e)for(var i=0;i<e[G];i++)t[j].addAnimator(e[i]);this.clipPath&&this.clipPath.addSelfToZr(t)},removeSelfFromZr:function(t){this.__zr=null;var e=this.animators;if(e)for(var i=0;i<e[G];i++)t[j].removeAnimator(e[i]);this.clipPath&&this.clipPath.removeSelfFromZr(t)}},a.mixin(s,r),a.mixin(s,n),a.mixin(s,i),s}),e("echarts/util/component",[ne,ie,"./clazz"],function(t){var e=t(ie),i=t("./clazz"),n=i.parseClassType,r=0,a={},o="_";return a.getUID=function(t){return[t||"",r++,Math.random()].join(o)},a.enableSubTypeDefaulter=function(t){var e={};return t.registerSubTypeDefaulter=function(t,i){t=n(t),e[t.main]=i},t.determineSubType=function(i,r){var a=r.type;if(!a){var o=n(i).main;t.hasSubTypes(i)&&e[o]&&(a=e[o](r))}return a},t},a.enableTopologicalTravel=function(t,i){function n(t){var n={},o=[];return e.each(t,function(s){var l=r(n,s),u=l.originalDeps=i(s),c=a(u,t);l.entryCount=c[G],0===l.entryCount&&o.push(s),e.each(c,function(t){e[F](l.predecessor,t)<0&&l.predecessor.push(t);var i=r(n,t);e[F](i.successor,t)<0&&i.successor.push(s)})}),{graph:n,noEntryList:o}}function r(t,e){return t[e]||(t[e]={predecessor:[],successor:[]}),t[e]}function a(t,i){var n=[];return e.each(t,function(t){e[F](i,t)>=0&&n.push(t)}),n}t.topologicalTravel=function(t,i,r,a){function o(t){u[t].entryCount--,0===u[t].entryCount&&c.push(t)}function s(t){h[t]=!0,o(t)}if(t[G]){var l=n(i),u=l.graph,c=l.noEntryList,h={};for(e.each(t,function(t){h[t]=!0});c[G];){var f=c.pop(),d=u[f],p=!!h[f];p&&(r.call(a,f,d.originalDeps.slice()),delete h[f]),e.each(d.successor,p?s:o)}e.each(h,function(){throw new Error("Circle dependency may exists")})}}},a}),e("echarts/util/layout",[ne,ie,"zrender/core/BoundingRect","./number","./format"],function(t){function e(t,e,i,n,r){var a=0,o=0;null==n&&(n=1/0),null==r&&(r=1/0);var s=0;e.eachChild(function(l,u){var h,f,d=l[y],p=l[c](),v=e.childAt(u+1),m=v&&v[c]();if("horizontal"===t){var g=p.width+(m?-m.x+p.x:0);h=a+g,h>n||l.newline?(a=0,h=g,o+=s+i,s=p[$]):s=Math.max(s,p[$])}else{var _=p[$]+(m?-m.y+p.y:0);f=o+_,f>r||l.newline?(a+=s+i,o=0,f=_,s=p.width):s=Math.max(s,p.width)}l.newline||(d[0]=a,d[1]=o,"horizontal"===t?a=h+i:o=f+i)})}var i=t(ie),n=t("zrender/core/BoundingRect"),r=t("./number"),a=t("./format"),o=r[p],s=i.each,l={},u=["left","right","top",V,"width",$];return l.box=e,l.vbox=i.curry(e,"vertical"),l.hbox=i.curry(e,"horizontal"),l.getAvailableSize=function(t,e,i){var n=e.width,r=e[$],s=o(t.x,n),l=o(t.y,r),u=o(t.x2,n),c=o(t.y2,r);return(isNaN(s)||isNaN(parseFloat(t.x)))&&(s=0),(isNaN(u)||isNaN(parseFloat(t.x2)))&&(u=n),(isNaN(l)||isNaN(parseFloat(t.y)))&&(l=0),(isNaN(c)||isNaN(parseFloat(t.y2)))&&(c=r),i=a.normalizeCssArray(i||0),{width:Math.max(u-s-i[1]-i[3],0),height:Math.max(c-l-i[0]-i[2],0)}},l.getLayoutRect=function(t,e,i){i=a.normalizeCssArray(i||0);var r=e.width,s=e[$],l=o(t.left,r),u=o(t.top,s),c=o(t.right,r),h=o(t[V],s),f=o(t.width,r),p=o(t[$],s),v=i[2]+i[0],m=i[1]+i[3],g=t.aspect;switch(isNaN(f)&&(f=r-c-m-l),isNaN(p)&&(p=s-h-v-u),isNaN(f)&&isNaN(p)&&(g>r/s?f=.8*r:p=.8*s),null!=g&&(isNaN(f)&&(f=g*p),isNaN(p)&&(p=f/g)),isNaN(l)&&(l=r-c-f-m),isNaN(u)&&(u=s-h-p-v),t.left||t.right){case d:l=r/2-f/2-i[3];break;case"right":l=r-f-m}switch(t.top||t[V]){case x:case d:u=s/2-p/2-i[0];break;case V:u=s-p-v}l=l||0,u=u||0,isNaN(f)&&(f=r-l-(c||0)),isNaN(p)&&(p=s-u-(h||0));var y=new n(l+i[3],u+i[0],f,p);return y.margin=i,y},l.positionGroup=function(t,e,n,r){var a=t[c]();e=i[I](i.clone(e),{width:a.width,height:a[$]}),e=l.getLayoutRect(e,n,r),t.attr(y,[e.x-a.x,e.y-a.y])},l.mergeLayoutParam=function(t,e,n){function r(i){var r={},l=0,u={},c=0,h=n.ignoreSize?1:2;if(s(i,function(e){u[e]=t[e]}),s(i,function(t){a(e,t)&&(r[t]=u[t]=e[t]),o(r,t)&&l++,o(u,t)&&c++}),c!==h&&l){if(l>=h)return r;for(var f=0;f<i[G];f++){var d=i[f];if(!a(r,d)&&a(t,d)){r[d]=t[d];break}}return r}return u}function a(t,e){return t.hasOwnProperty(e)}function o(t,e){return null!=t[e]&&"auto"!==t[e]}function l(t,e,i){s(t,function(t){e[t]=i[t]})}!i[D](n)&&(n={});var u=["width","left","right"],c=[$,"top",V],h=r(u),f=r(c);l(u,t,h),l(c,t,f)},l.getLayoutParams=function(t){return l.copyLayoutParams({},t)},l.copyLayoutParams=function(t,e){return e&&t&&s(u,function(i){e.hasOwnProperty(i)&&(t[i]=e[i])}),t},l}),e("echarts/model/mixin/boxLayout",[ne],function(){return{getBoxLayoutParams:function(){return{left:this.get("left"),top:this.get("top"),right:this.get("right"),bottom:this.get(V),width:this.get("width"),height:this.get($)}}}}),e("zrender/core/guid",[],function(){var t=2311;return function(){return t++}}),e("zrender/mixin/Transformable",[ne,"../core/matrix","../core/vector"],function(t){function e(t){return t>a||-a>t}var i=t("../core/matrix"),n=t("../core/vector"),r=i.identity,a=5e-5,s=function(t){t=t||{},t[y]||(this[y]=[0,0]),null==t.rotation&&(this.rotation=0),t.scale||(this.scale=[1,1]),this.origin=this.origin||null},l=s[K];l[o]=null,l.needLocalTransform=function(){return e(this.rotation)||e(this[y][0])||e(this[y][1])||e(this.scale[0]-1)||e(this.scale[1]-1)},l.updateTransform=function(){var t=this.parent,e=t&&t[o],n=this.needLocalTransform(),a=this[o];return n||e?(a=a||i[E](),n?this.getLocalTransform(a):r(a),e&&(n?i.mul(a,t[o],a):i.copy(a,t[o])),this[o]=a,this.invTransform=this.invTransform||i[E](),void i.invert(this.invTransform,a)):void(a&&r(a))},l.getLocalTransform=function(t){t=t||[],r(t);var e=this.origin,n=this.scale,a=this.rotation,o=this[y];return e&&(t[4]-=e[0],t[5]-=e[1]),i.scale(t,t,n),a&&i.rotate(t,t,a),e&&(t[4]+=e[0],t[5]+=e[1]),t[4]+=o[0],t[5]+=o[1],t},l.setTransform=function(t){var e=this[o],i=t.dpr||1;e?t.setTransform(i*e[0],i*e[1],i*e[2],i*e[3],i*e[4],i*e[5]):t.setTransform(i,0,0,i,0,0)},l.restoreTransform=function(t){var e=(this[o],t.dpr||1);t.setTransform(e,0,0,e,0,0)};var c=[];return l.decomposeTransform=function(){if(this[o]){var t=this.parent,n=this[o];t&&t[o]&&(i.mul(c,t.invTransform,n),n=c);var r=n[0]*n[0]+n[1]*n[1],a=n[2]*n[2]+n[3]*n[3],s=this[y],l=this.scale;e(r-1)&&(r=Math.sqrt(r)),e(a-1)&&(a=Math.sqrt(a)),n[0]<0&&(r=-r),n[3]<0&&(a=-a),s[0]=n[4],s[1]=n[5],l[0]=r,l[1]=a,this.rotation=Math.atan2(-n[1]/a,n[0]/r)}},l.getGlobalScale=function(){var t=this[o];if(!t)return[1,1];var e=Math.sqrt(t[0]*t[0]+t[1]*t[1]),i=Math.sqrt(t[2]*t[2]+t[3]*t[3]);return t[0]<0&&(e=-e),t[3]<0&&(i=-i),[e,i]},l.transformCoordToLocal=function(t,e){var i=[t,e],r=this.invTransform;return r&&n[u](i,i,r),i},l.transformCoordToGlobal=function(t,e){var i=[t,e],r=this[o];return r&&n[u](i,i,r),i},s}),e("zrender/mixin/Animatable",[ne,"../animation/Animator","../core/util","../core/log"],function(t){var e=t("../animation/Animator"),i=t("../core/util"),n=i.isString,r=i.isFunction,a=i[D],o=t("../core/log"),s=function(){this.animators=[]};return s[K]={constructor:s,animate:function(t,n){var r,a=!1,s=this,l=this.__zr;if(t){var u=t.split("."),c=s;a="shape"===u[0];for(var h=0,f=u[G];f>h;h++)c&&(c=c[u[h]]);c&&(r=c)}else r=s;if(!r)return void o('Property "'+t+'" is not existed in element '+s.id);var d=s.animators,p=new e(r,n);return p.during(function(){s.dirty(a)}).done(function(){d[k](i[F](d,p),1)}),d.push(p),l&&l[j].addAnimator(p),p},stopAnimation:function(t){for(var e=this.animators,i=e[G],n=0;i>n;n++)e[n].stop(t);return e[G]=0,this},animateTo:function(t,e,i,a,o){function s(){u--,u||o&&o()}n(i)?(o=a,a=i,i=0):r(a)?(o=a,a="linear",i=0):r(i)?(o=i,i=0):r(e)?(o=e,e=500):e||(e=500),this.stopAnimation(),this._animateToShallow("",this,t,e,i,a,o);var l=this.animators.slice(),u=l[G];u||o&&o();for(var c=0;c<l[G];c++)l[c].done(s).start(a)},_animateToShallow:function(t,e,n,r,o){var s={},l=0;for(var u in n)if(n.hasOwnProperty(u))if(null!=e[u])a(n[u])&&!i.isArrayLike(n[u])?this._animateToShallow(t?t+"."+u:u,e[u],n[u],r,o):(s[u]=n[u],l++);else if(null!=n[u])if(t){var c={};c[t]={},c[t][u]=n[u],this.attr(c)}else this.attr(u,n[u]);return l>0&&this.animate(t,!1).when(null==r?500:r,s).delay(o||0),this}},s}),e("echarts/chart/gauge/PointerPath",[ne,"zrender/graphic/Path"],function(t){return t("zrender/graphic/Path")[I]({type:"echartsGaugePointer",shape:{angle:0,width:10,r:10,x:0,y:0},buildPath:function(t,e){var n=Math.cos,r=Math.sin,o=e.r,s=e.width,l=e.angle,u=e.x-n(l)*s*(s>=o/3?1:2),c=e.y-r(l)*s*(s>=o/3?1:2);l=e.angle-Math.PI/2,t[a](u,c),t[i](e.x+n(l)*s,e.y+r(l)*s),t[i](e.x+n(e.angle)*o,e.y+r(e.angle)*o),t[i](e.x-n(l)*s,e.y-r(l)*s),t[i](u,c)}})}),e("zrender/animation/Animator",[ne,"./Clip","../tool/color","../core/util"],function(t){function e(t,e){return t[e]}function i(t,e,i){t[e]=i}function n(t,e,i){return(e-t)*i+t}function r(t,e,i){return i>.5?e:t}function a(t,e,i,r,a){var o=t[G];if(1==a)for(var s=0;o>s;s++)r[s]=n(t[s],e[s],i);else for(var l=t[0][G],s=0;o>s;s++)for(var u=0;l>u;u++)r[s][u]=n(t[s][u],e[s][u],i)}function o(t,e,i){var n=t[G],r=e[G];if(n!==r){var a=n>r;if(a)t[G]=r;else for(var o=n;r>o;o++)t.push(1===i?e[o]:g.call(e[o]))}for(var s=t[0]&&t[0][G],o=0;o<t[G];o++)if(1===i)isNaN(t[o])&&(t[o]=e[o]);else for(var l=0;s>l;l++)isNaN(t[o][l])&&(t[o][l]=e[o][l])}function s(t,e,i){if(t===e)return!0;var n=t[G];if(n!==e[G])return!1;if(1===i){for(var r=0;n>r;r++)if(t[r]!==e[r])return!1}else for(var a=t[0][G],r=0;n>r;r++)for(var o=0;a>o;o++)if(t[r][o]!==e[r][o])return!1;return!0}function l(t,e,i,n,r,a,o,s,l){var c=t[G];if(1==l)for(var h=0;c>h;h++)s[h]=u(t[h],e[h],i[h],n[h],r,a,o);else for(var f=t[0][G],h=0;c>h;h++)for(var d=0;f>d;d++)s[h][d]=u(t[h][d],e[h][d],i[h][d],n[h][d],r,a,o)}function u(t,e,i,n,r,a,o){var s=.5*(i-t),l=.5*(n-e);return(2*(e-i)+s+l)*o+(-3*(e-i)-2*s-l)*a+s*r+e}function c(t){if(m(t)){var e=t[G];if(m(t[0])){for(var i=[],n=0;e>n;n++)i.push(g.call(t[n]));return i}return g.call(t)}return t}function h(t){return t[0]=Math.floor(t[0]),t[1]=Math.floor(t[1]),t[2]=Math.floor(t[2]),"rgba("+t.join(",")+")"}function f(t,e,i,c,f){var v=t._getter,g=t._setter,y="spline"===e,_=c[G];if(_){var x,b=c[0].value,w=m(b),M=!1,T=!1,S=w&&m(b[0])?2:1;c.sort(function(t,e){return t.time-e.time}),x=c[_-1].time;for(var C=[],P=[],A=c[0].value,L=!0,z=0;_>z;z++){C.push(c[z].time/x);var k=c[z].value;if(w&&s(k,A,S)||!w&&k===A||(L=!1),A=k,typeof k==Q){var I=p.parse(k);I?(k=I,M=!0):T=!0}P.push(k)}if(!L){for(var D=P[_-1],z=0;_-1>z;z++)w?o(P[z],D,S):!isNaN(P[z])||isNaN(D)||T||M||(P[z]=D);w&&o(v(t._target,f),D,S);var O,E,R,B,N,F,V=0,H=0;if(M)var q=[0,0,0,0];var W=function(t,e){var i;if(0>e)i=0;else if(H>e){for(O=Math.min(V+1,_-1),i=O;i>=0&&!(C[i]<=e);i--);i=Math.min(i,_-2)}else{for(i=V;_>i&&!(C[i]>e);i++);i=Math.min(i-1,_-2)}V=i,H=e;var o=C[i+1]-C[i];if(0!==o)if(E=(e-C[i])/o,y)if(B=P[i],R=P[0===i?i:i-1],N=P[i>_-2?_-1:i+1],F=P[i>_-3?_-1:i+2],w)l(R,B,N,F,E,E*E,E*E*E,v(t,f),S);else{var s;if(M)s=l(R,B,N,F,E,E*E,E*E*E,q,1),s=h(q);else{if(T)return r(B,N,E);s=u(R,B,N,F,E,E*E,E*E*E)}g(t,f,s)}else if(w)a(P[i],P[i+1],E,v(t,f),S);else{var s;if(M)a(P[i],P[i+1],E,q,1),s=h(q);else{if(T)return r(P[i],P[i+1],E);s=n(P[i],P[i+1],E)}g(t,f,s)}},Z=new d({target:t._target,life:x,loop:t._loop,delay:t._delay,onframe:W,ondestroy:i});return e&&"spline"!==e&&(Z.easing=e),Z}}}var d=t("./Clip"),p=t("../tool/color"),v=t("../core/util"),m=v.isArrayLike,g=Array[K].slice,y=function(t,n,r,a){this._tracks={},this._target=t,this._loop=n||!1,this._getter=r||e,this._setter=a||i,this._clipCount=0,this._delay=0,this._doneList=[],this._onframeList=[],this._clipList=[]};return y[K]={when:function(t,e){var i=this._tracks;for(var n in e)if(e.hasOwnProperty(n)){if(!i[n]){i[n]=[];var r=this._getter(this._target,n);if(null==r)continue;0!==t&&i[n].push({time:0,value:c(r)})}i[n].push({time:t,value:e[n]})}return this},during:function(t){return this._onframeList.push(t),this},_doneCallback:function(){this._tracks={},this._clipList[G]=0;for(var t=this._doneList,e=t[G],i=0;e>i;i++)t[i].call(this)},start:function(t){var e,i=this,n=0,r=function(){n--,n||i._doneCallback()};for(var a in this._tracks)if(this._tracks.hasOwnProperty(a)){var o=f(this,t,r,this._tracks[a],a);o&&(this._clipList.push(o),n++,this[j]&&this[j].addClip(o),e=o)}if(e){var s=e.onframe;e.onframe=function(t,e){s(t,e);for(var n=0;n<i._onframeList[G];n++)i._onframeList[n](t,e)}}return n||this._doneCallback(),this},stop:function(t){for(var e=this._clipList,i=this[j],n=0;n<e[G];n++){var r=e[n];t&&r.onframe(this._target,1),i&&i.removeClip(r)}e[G]=0},delay:function(t){return this._delay=t,this},done:function(t){return t&&this._doneList.push(t),this},getClips:function(){return this._clipList}},y}),e("zrender/core/log",[ne,"../config"],function(t){var e=t("../config");return function(){if(0!==e.debugMode)if(1==e.debugMode)for(var t in arguments)throw new Error(arguments[t]);else if(e.debugMode>1)for(var t in arguments)console.log(arguments[t])}}),e("zrender/animation/Clip",[ne,"./easing"],function(t){function e(t){this._target=t[z],this._life=t.life||1e3,this._delay=t.delay||0,this._initialized=!1,this.loop=null==t.loop?!1:t.loop,this.gap=t.gap||0,this.easing=t.easing||"Linear",this.onframe=t.onframe,this.ondestroy=t.ondestroy,this.onrestart=t.onrestart
}var i=t("./easing");return e[K]={constructor:e,step:function(t){this._initialized||(this._startTime=t+this._delay,this._initialized=!0);var e=(t-this._startTime)/this._life;if(!(0>e)){e=Math.min(e,1);var n=this.easing,r=typeof n==Q?i[n]:n,a=typeof r===C?r(e):e;return this.fire("frame",a),1==e?this.loop?(this.restart(t),"restart"):(this._needsRemove=!0,"destroy"):null}},restart:function(t){var e=(t-this._startTime)%this._life;this._startTime=t-e+this.gap,this._needsRemove=!1},fire:function(t,e){t="on"+t,this[t]&&this[t](this._target,e)}},e}),e("zrender/animation/easing",[],function(){var t={linear:function(t){return t},quadraticIn:function(t){return t*t},quadraticOut:function(t){return t*(2-t)},quadraticInOut:function(t){return(t*=2)<1?.5*t*t:-.5*(--t*(t-2)-1)},cubicIn:function(t){return t*t*t},cubicOut:function(t){return--t*t*t+1},cubicInOut:function(t){return(t*=2)<1?.5*t*t*t:.5*((t-=2)*t*t+2)},quarticIn:function(t){return t*t*t*t},quarticOut:function(t){return 1- --t*t*t*t},quarticInOut:function(t){return(t*=2)<1?.5*t*t*t*t:-.5*((t-=2)*t*t*t-2)},quinticIn:function(t){return t*t*t*t*t},quinticOut:function(t){return--t*t*t*t*t+1},quinticInOut:function(t){return(t*=2)<1?.5*t*t*t*t*t:.5*((t-=2)*t*t*t*t+2)},sinusoidalIn:function(t){return 1-Math.cos(t*Math.PI/2)},sinusoidalOut:function(t){return Math.sin(t*Math.PI/2)},sinusoidalInOut:function(t){return.5*(1-Math.cos(Math.PI*t))},exponentialIn:function(t){return 0===t?0:Math.pow(1024,t-1)},exponentialOut:function(t){return 1===t?1:1-Math.pow(2,-10*t)},exponentialInOut:function(t){return 0===t?0:1===t?1:(t*=2)<1?.5*Math.pow(1024,t-1):.5*(-Math.pow(2,-10*(t-1))+2)},circularIn:function(t){return 1-Math.sqrt(1-t*t)},circularOut:function(t){return Math.sqrt(1- --t*t)},circularInOut:function(t){return(t*=2)<1?-.5*(Math.sqrt(1-t*t)-1):.5*(Math.sqrt(1-(t-=2)*t)+1)},elasticIn:function(t){var e,i=.1,n=.4;return 0===t?0:1===t?1:(!i||1>i?(i=1,e=n/4):e=n*Math.asin(1/i)/(2*Math.PI),-(i*Math.pow(2,10*(t-=1))*Math.sin(2*(t-e)*Math.PI/n)))},elasticOut:function(t){var e,i=.1,n=.4;return 0===t?0:1===t?1:(!i||1>i?(i=1,e=n/4):e=n*Math.asin(1/i)/(2*Math.PI),i*Math.pow(2,-10*t)*Math.sin(2*(t-e)*Math.PI/n)+1)},elasticInOut:function(t){var e,i=.1,n=.4;return 0===t?0:1===t?1:(!i||1>i?(i=1,e=n/4):e=n*Math.asin(1/i)/(2*Math.PI),(t*=2)<1?-.5*i*Math.pow(2,10*(t-=1))*Math.sin(2*(t-e)*Math.PI/n):i*Math.pow(2,-10*(t-=1))*Math.sin(2*(t-e)*Math.PI/n)*.5+1)},backIn:function(t){var e=1.70158;return t*t*((e+1)*t-e)},backOut:function(t){var e=1.70158;return--t*t*((e+1)*t+e)+1},backInOut:function(t){var e=2.5949095;return(t*=2)<1?.5*t*t*((e+1)*t-e):.5*((t-=2)*t*((e+1)*t+e)+2)},bounceIn:function(e){return 1-t.bounceOut(1-e)},bounceOut:function(t){return 1/2.75>t?7.5625*t*t:2/2.75>t?7.5625*(t-=1.5/2.75)*t+.75:2.5/2.75>t?7.5625*(t-=2.25/2.75)*t+.9375:7.5625*(t-=2.625/2.75)*t+.984375},bounceInOut:function(e){return.5>e?.5*t.bounceIn(2*e):.5*t.bounceOut(2*e-1)+.5}};return t}),e("zrender/contain/windingLine",[],function(){return function(t,e,i,n,r,a){if(a>e&&a>n||e>a&&n>a)return 0;if(n===e)return 0;var o=e>n?1:-1,s=(a-e)/(n-e);(1===s||0===s)&&(o=e>n?.5:-.5);var l=s*(i-t)+t;return l>r?o:0}}),e("zrender/contain/line",[],function(){return{containStroke:function(t,e,i,n,r,a,o){if(0===r)return!1;var s=r,l=0,u=t;if(o>e+s&&o>n+s||e-s>o&&n-s>o||a>t+s&&a>i+s||t-s>a&&i-s>a)return!1;if(t===i)return Math.abs(a-t)<=s/2;l=(e-n)/(t-i),u=(t*n-i*e)/(t-i);var c=l*a-o+u,h=c*c/(l*l+1);return s/2*s/2>=h}}}),e("zrender/contain/cubic",[ne,"../core/curve"],function(t){var e=t("../core/curve");return{containStroke:function(t,i,n,r,a,o,s,l,u,c,h){if(0===u)return!1;var f=u;if(h>i+f&&h>r+f&&h>o+f&&h>l+f||i-f>h&&r-f>h&&o-f>h&&l-f>h||c>t+f&&c>n+f&&c>a+f&&c>s+f||t-f>c&&n-f>c&&a-f>c&&s-f>c)return!1;var d=e.cubicProjectPoint(t,i,n,r,a,o,s,l,c,h,null);return f/2>=d}}}),e("zrender/contain/quadratic",[ne,"../core/curve"],function(t){var e=t("../core/curve");return{containStroke:function(t,i,n,r,a,o,s,l,u){if(0===s)return!1;var c=s;if(u>i+c&&u>r+c&&u>o+c||i-c>u&&r-c>u&&o-c>u||l>t+c&&l>n+c&&l>a+c||t-c>l&&n-c>l&&a-c>l)return!1;var h=e.quadraticProjectPoint(t,i,n,r,a,o,l,u,null);return c/2>=h}}}),e("zrender/contain/arc",[ne,"./util"],function(t){var e=t("./util").normalizeRadian,i=2*Math.PI;return{containStroke:function(t,n,r,a,o,s,l,u,c){if(0===l)return!1;var h=l;u-=t,c-=n;var f=Math.sqrt(u*u+c*c);if(f-h>r||r>f+h)return!1;if(Math.abs(a-o)%i<1e-4)return!0;if(s){var d=a;a=e(o),o=e(d)}else a=e(a),o=e(o);a>o&&(o+=i);var p=Math.atan2(c,u);return 0>p&&(p+=i),p>=a&&o>=p||p+i>=a&&o>=p+i}}}),e("zrender/contain/util",[ne],function(){var t=2*Math.PI;return{normalizeRadian:function(e){return e%=t,0>e&&(e+=t),e}}}),e("zrender/core/LRU",[ne],function(){var t=function(){this.head=null,this.tail=null,this._len=0},e=t[K];e.insert=function(t){var e=new i(t);return this.insertEntry(e),e},e.insertEntry=function(t){this.head?(this.tail.next=t,t.prev=this.tail,this.tail=t):this.head=this.tail=t,this._len++},e.remove=function(t){var e=t.prev,i=t.next;e?e.next=i:this.head=i,i?i.prev=e:this.tail=e,t.next=t.prev=null,this._len--},e.len=function(){return this._len};var i=function(t){this.value=t,this.next,this.prev},n=function(e){this._list=new t,this._map={},this._maxSize=e||10},r=n[K];return r.put=function(t,e){var i=this._list,n=this._map;if(null==n[t]){var r=i.len();if(r>=this._maxSize&&r>0){var a=i.head;i.remove(a),delete n[a.key]}var o=i.insert(e);o.key=t,n[t]=o}},r.get=function(t){var e=this._map[t],i=this._list;return null!=e?(e!==i.tail&&(i.remove(e),i.insertEntry(e)),e.value):void 0},r.clear=function(){this._list.clear(),this._map={}},n}),e("zrender/graphic/helper/poly",[ne,"./smoothSpline","./smoothBezier"],function(t){var e=t("./smoothSpline"),n=t("./smoothBezier");return{buildPath:function(t,r,o){var s=r.points,l=r.smooth;if(s&&s[G]>=2){if(l&&"spline"!==l){var u=n(s,l,o,r.smoothConstraint);t[a](s[0][0],s[0][1]);for(var c=s[G],h=0;(o?c:c-1)>h;h++){var f=u[2*h],d=u[2*h+1],p=s[(h+1)%c];t.bezierCurveTo(f[0],f[1],d[0],d[1],p[0],p[1])}}else{"spline"===l&&(s=e(s,o)),t[a](s[0][0],s[0][1]);for(var h=1,v=s[G];v>h;h++)t[i](s[h][0],s[h][1])}o&&t.closePath()}}}}),e("zrender/Handler",[ne,"./core/util","./mixin/Draggable","./mixin/Eventful"],function(t){function e(t,e,i){return{type:t,event:i,target:e,cancelBubble:!1,offsetX:i.zrX,offsetY:i.zrY,gestureEvent:i.gestureEvent,pinchX:i.pinchX,pinchY:i.pinchY,pinchScale:i.pinchScale,wheelDelta:i.zrDelta}}function i(){}function n(t,e,i){if(t[t.rectHover?"rectContain":T](e,i)){for(var n=t;n;){if(n.silent||n.clipPath&&!n.clipPath[T](e,i))return!1;n=n.parent}return!0}return!1}var r=t("./core/util"),a=t("./mixin/Draggable"),o=t("./mixin/Eventful");i[K].dispose=function(){};var s=["click","dblclick","mousewheel","mouseout","mouseup","mousedown","mousemove","contextmenu"],l=function(t,e,n,l){o.call(this),this[q]=t,this.painter=e,this.painterRoot=l,n=n||new i,this.proxy=n,n.handler=this,this._hovered,this._lastTouchMoment,this._lastX,this._lastY,a.call(this),r.each(s,function(t){n.on&&n.on(t,this[t],this)},this)};return l[K]={constructor:l,mousemove:function(t){var e=t.zrX,i=t.zrY,n=this.findHover(e,i,null),r=this._hovered,a=this.proxy;this._hovered=n,a.setCursor&&a.setCursor(n?n.cursor:"default"),r&&n!==r&&r.__zr&&this.dispatchToElement(r,"mouseout",t),this.dispatchToElement(n,"mousemove",t),n&&n!==r&&this.dispatchToElement(n,"mouseover",t)},mouseout:function(t){this.dispatchToElement(this._hovered,"mouseout",t);var e,i=t.toElement||t.relatedTarget;do i=i&&i.parentNode;while(i&&9!=i.nodeType&&!(e=i===this.painterRoot));!e&&this.trigger("globalout",{event:t})},resize:function(){this._hovered=null},dispatch:function(t,e){var i=this[t];i&&i.call(this,e)},dispose:function(){this.proxy.dispose(),this[q]=this.proxy=this.painter=null},setCursorStyle:function(t){var e=this.proxy;e.setCursor&&e.setCursor(t)},dispatchToElement:function(t,i,n){for(var r="on"+i,a=e(i,t,n),o=t;o&&(o[r]&&(a.cancelBubble=o[r].call(o,a)),o.trigger(i,a),o=o.parent,!a.cancelBubble););a.cancelBubble||(this.trigger(i,a),this.painter&&this.painter.eachOtherLayer(function(t){typeof t[r]==C&&t[r].call(t,a),t.trigger&&t.trigger(i,a)}))},findHover:function(t,e,i){for(var r=this[q].getDisplayList(),a=r[G]-1;a>=0;a--)if(!r[a].silent&&r[a]!==i&&!r[a][H]&&n(r[a],t,e))return r[a]}},r.each(["click","mousedown","mouseup","mousewheel","dblclick","contextmenu"],function(t){l[K][t]=function(e){var i=this.findHover(e.zrX,e.zrY,null);if("mousedown"===t)this._downel=i,this._upel=i;else if("mosueup"===t)this._upel=i;else if("click"===t&&this._downel!==this._upel)return;this.dispatchToElement(i,t,e)}}),r.mixin(l,o),r.mixin(l,a),l}),e("zrender/Storage",[ne,"./core/util","./core/env","./container/Group","./core/timsort"],function(t){function e(t,e){return t[L]===e[L]?t.z===e.z?t.z2-e.z2:t.z-e.z:t[L]-e[L]}var i=t("./core/util"),r=t("./core/env"),a=t("./container/Group"),o=t("./core/timsort"),s=function(){this._elements={},this._roots=[],this._displayList=[],this._displayListLen=0};return s[K]={constructor:s,traverse:function(t,e){for(var i=0;i<this._roots[G];i++)this._roots[i].traverse(t,e)},getDisplayList:function(t,e){return e=e||!1,t&&this.updateDisplayList(e),this._displayList},updateDisplayList:function(t){this._displayListLen=0;for(var i=this._roots,n=this._displayList,a=0,s=i[G];s>a;a++)this._updateAndAddDisplayable(i[a],null,t);n[G]=this._displayListLen,r[W]&&o(n,e)},_updateAndAddDisplayable:function(t,e,i){if(!t[H]||i){t.beforeUpdate(),t[n]&&t[O](),t.afterUpdate();var r=t.clipPath;if(r&&(r.parent=t,r.updateTransform(),e?(e=e.slice(),e.push(r)):e=[r]),t.isGroup){for(var a=t._children,o=0;o<a[G];o++){var s=a[o];t[n]&&(s[n]=!0),this._updateAndAddDisplayable(s,e,i)}t[n]=!1}else t.__clipPaths=e,this._displayList[this._displayListLen++]=t}},addRoot:function(t){this._elements[t.id]||(t instanceof a&&t.addChildrenToStorage(this),this.addToMap(t),this._roots.push(t))},delRoot:function(t){if(null==t){for(var e=0;e<this._roots[G];e++){var n=this._roots[e];n instanceof a&&n.delChildrenFromStorage(this)}return this._elements={},this._roots=[],this._displayList=[],void(this._displayListLen=0)}if(t instanceof Array)for(var e=0,r=t[G];r>e;e++)this.delRoot(t[e]);else{var o;o=typeof t==Q?this._elements[t]:t;var s=i[F](this._roots,o);s>=0&&(this.delFromMap(o.id),this._roots[k](s,1),o instanceof a&&o.delChildrenFromStorage(this))}},addToMap:function(t){return t instanceof a&&(t.__storage=this),t.dirty(!1),this._elements[t.id]=t,this},get:function(t){return this._elements[t]},delFromMap:function(t){var e=this._elements,i=e[t];return i&&(delete e[t],i instanceof a&&(i.__storage=null)),this},dispose:function(){this._elements=this._renderList=this._roots=null},displayableSortFunc:e},s}),e("zrender/animation/Animation",[ne,"../core/util","../core/event","./requestAnimationFrame","./Animator"],function(t){var e=t("../core/util"),i=t("../core/event").Dispatcher,n=t("./requestAnimationFrame"),r=t("./Animator"),a=function(t){t=t||{},this.stage=t.stage||{},this.onframe=t.onframe||function(){},this._clips=[],this._running=!1,this._time,this._pausedTime,this._pauseStart,this._paused=!1,i.call(this)};return a[K]={constructor:a,addClip:function(t){this._clips.push(t)},addAnimator:function(t){t[j]=this;for(var e=t.getClips(),i=0;i<e[G];i++)this.addClip(e[i])},removeClip:function(t){var i=e[F](this._clips,t);i>=0&&this._clips[k](i,1)},removeAnimator:function(t){for(var e=t.getClips(),i=0;i<e[G];i++)this.removeClip(e[i]);t[j]=null},_update:function(){for(var t=(new Date).getTime()-this._pausedTime,e=t-this._time,i=this._clips,n=i[G],r=[],a=[],o=0;n>o;o++){var s=i[o],l=s.step(t);l&&(r.push(l),a.push(s))}for(var o=0;n>o;)i[o]._needsRemove?(i[o]=i[n-1],i.pop(),n--):o++;n=r[G];for(var o=0;n>o;o++)a[o].fire(r[o]);this._time=t,this.onframe(e),this.trigger("frame",e),this.stage[O]&&this.stage[O]()},_startLoop:function(){function t(){e._running&&(n(t),!e._paused&&e._update())}var e=this;this._running=!0,n(t)},start:function(){this._time=(new Date).getTime(),this._pausedTime=0,this._startLoop()},stop:function(){this._running=!1},pause:function(){this._paused||(this._pauseStart=(new Date).getTime(),this._paused=!0)},resume:function(){this._paused&&(this._pausedTime+=(new Date).getTime()-this._pauseStart,this._paused=!1)},clear:function(){this._clips=[]},animate:function(t,e){e=e||{};var i=new r(t,e.loop,e.getter,e.setter);return i}},e.mixin(a,i),a}),e("zrender/dom/HandlerProxy",[ne,"../core/event","../core/util","../mixin/Eventful","../core/env","../core/GestureMgr"],function(t){function e(t){return"mousewheel"===t&&c.browser.firefox?"DOMMouseScroll":t}function i(t,e,i){var n=t._gestureMgr;"start"===i&&n.clear();var r=n.recognize(e,t.handler.findHover(e.zrX,e.zrY,null),t.dom);if("end"===i&&n.clear(),r){var a=r.type;e.gestureEvent=a,t.handler.dispatchToElement(r[z],a,r.event)}}function n(t){t._touching=!0,clearTimeout(t._touchTimer),t._touchTimer=setTimeout(function(){t._touching=!1},700)}function r(){return c.touchEventsSupported}function a(t){function e(t,e){return function(){return e._touching?void 0:t.apply(e,arguments)}}for(var i=0;i<g[G];i++){var n=g[i];t._handlers[n]=l.bind(y[n],t)}for(var i=0;i<m[G];i++){var n=m[i];t._handlers[n]=e(y[n],t)}}function o(t){function i(i,n){l.each(i,function(i){f(t,e(i),n._handlers[i])},n)}u.call(this),this.dom=t,this._touching=!1,this._touchTimer,this._gestureMgr=new h,this._handlers={},a(this),r()&&i(g,this),i(m,this)}var s=t("../core/event"),l=t("../core/util"),u=t("../mixin/Eventful"),c=t("../core/env"),h=t("../core/GestureMgr"),f=s.addEventListener,d=s.removeEventListener,p=s.normalizeEvent,v=300,m=["click","dblclick","mousewheel","mouseout","mouseup","mousedown","mousemove","contextmenu"],g=["touchstart","touchend","touchmove"],y={mousemove:function(t){t=p(this.dom,t),this.trigger("mousemove",t)},mouseout:function(t){t=p(this.dom,t);var e=t.toElement||t.relatedTarget;if(e!=this.dom)for(;e&&9!=e.nodeType;){if(e===this.dom)return;e=e.parentNode}this.trigger("mouseout",t)},touchstart:function(t){t=p(this.dom,t),this._lastTouchMoment=new Date,i(this,t,"start"),y.mousemove.call(this,t),y.mousedown.call(this,t),n(this)},touchmove:function(t){t=p(this.dom,t),i(this,t,"change"),y.mousemove.call(this,t),n(this)},touchend:function(t){t=p(this.dom,t),i(this,t,"end"),y.mouseup.call(this,t),+new Date-this._lastTouchMoment<v&&y.click.call(this,t),n(this)}};l.each(["click","mousedown","mouseup","mousewheel","dblclick","contextmenu"],function(t){y[t]=function(e){e=p(this.dom,e),this.trigger(t,e)}});var _=o[K];return _.dispose=function(){for(var t=m[b](g),i=0;i<t[G];i++){var n=t[i];d(this.dom,e(n),this._handlers[n])}},_.setCursor=function(t){this.dom.style.cursor=t||"default"},l.mixin(o,u),o}),e("zrender/Painter",[ne,"./config","./core/util","./core/log","./core/BoundingRect","./core/timsort","./Layer","./animation/requestAnimationFrame","./graphic/Image"],function(t){function e(t){return parseInt(t,10)}function i(t){return t?t.isBuildin?!0:typeof t[Y]!==C||typeof t.refresh!==C?!1:!0:!1}function r(t){t.__unusedCount++}function a(t){1==t.__unusedCount&&t.clear()}function s(t,e,i){return M.copy(t[c]()),t[o]&&M[u](t[o]),T.width=e,T[$]=i,!M.intersect(T)}function l(t,e){if(t==e)return!1;if(!t||!e||t[G]!==e[G])return!0;for(var i=0;i<t[G];i++)if(t[i]!==e[i])return!0}function h(t,e){for(var i=0;i<t[G];i++){var n=t[i],r=n.path;n.setTransform(e),r.beginPath(e),n.buildPath(r,n.shape),e.clip(),n.restoreTransform(e)}}function f(t,e){var i=document[w]("div");return i.style.cssText=["position:relative","overflow:hidden","width:"+t+"px","height:"+e+"px","padding:0","margin:0","border-width:0"].join(";")+";",i}var d=t("./config"),p=t("./core/util"),v=t("./core/log"),m=t("./core/BoundingRect"),g=t("./core/timsort"),_=t("./Layer"),x=t("./animation/requestAnimationFrame"),b=5,M=new m(0,0,0,0),T=new m(0,0,0,0),S=function(t,e,i){var n=!t.nodeName||"CANVAS"===t.nodeName.toUpperCase();this._opts=i=p[I]({},i||{}),this.dpr=i.devicePixelRatio||d.devicePixelRatio,this._singleCanvas=n,this.root=t;var r=t.style;r&&(r["-webkit-tap-highlight-color"]="transparent",r["-webkit-user-select"]=r["user-select"]=r["-webkit-touch-callout"]="none",t.innerHTML=""),this[q]=e;var a=this._zlevelList=[],o=this._layers={};if(this._layerConfig={},n){var s=t.width,l=t[$];this._width=s,this._height=l;var u=new _(t,this,1);u.initContext(),o[0]=u,a.push(0)}else{this._width=this._getSize(0),this._height=this._getSize(1);var c=this._domRoot=f(this._width,this._height);t.appendChild(c)}this.pathToImage=this._createPathToImage(),this._progressiveLayers=[],this._hoverlayer,this._hoverElements=[]};return S[K]={constructor:S,isSingleCanvas:function(){return this._singleCanvas},getViewportRoot:function(){return this._singleCanvas?this._layers[0].dom:this._domRoot},refresh:function(t){var e=this[q].getDisplayList(!0),i=this._zlevelList;this._paintList(e,t);for(var n=0;n<i[G];n++){var r=i[n],a=this._layers[r];!a.isBuildin&&a.refresh&&a.refresh()}return this.refreshHover(),this._progressiveLayers[G]&&this._startProgessive(),this},addHover:function(t,e){if(!t.__hoverMir){var i=new t.constructor({style:t.style,shape:t.shape});i.__from=t,t.__hoverMir=i,i.setStyle(e),this._hoverElements.push(i)}},removeHover:function(t){var e=t.__hoverMir,i=this._hoverElements,n=p[F](i,e);n>=0&&i[k](n,1),t.__hoverMir=null},clearHover:function(){for(var t=this._hoverElements,e=0;e<t[G];e++){var i=t[e].__from;i&&(i.__hoverMir=null)}t[G]=0},refreshHover:function(){var t=this._hoverElements,e=t[G],i=this._hoverlayer;if(i&&i.clear(),e){g(t,this[q].displayableSortFunc),i||(i=this._hoverlayer=this.getLayer(1e5));var n={};i.ctx.save();for(var r=0;e>r;){var a=t[r],s=a.__from;s&&s.__zr?(r++,s.invisible||(a[o]=s[o],a.invTransform=s.invTransform,a.__clipPaths=s.__clipPaths,this._doPaintEl(a,i,!0,n))):(t[k](r,1),s.__hoverMir=null,e--)}i.ctx.restore()}},_startProgessive:function(){function t(){i===e._progressiveToken&&e[q]&&(e._doPaintList(e[q].getDisplayList()),e._furtherProgressive?(e._progress++,x(t)):e._progressiveToken=-1)}var e=this;if(e._furtherProgressive){var i=e._progressiveToken=+new Date;e._progress++,x(t)}},_clearProgressive:function(){this._progressiveToken=-1,this._progress=0,p.each(this._progressiveLayers,function(t){t[n]&&t.clear()})},_paintList:function(t,e){null==e&&(e=!1),this._updateLayerStatus(t),this._clearProgressive(),this.eachBuildinLayer(r),this._doPaintList(t,e),this.eachBuildinLayer(a)},_doPaintList:function(t,e){function i(t){var e=o.dpr||1;o.save(),o.globalAlpha=1,o.shadowBlur=0,r[n]=!0,o.setTransform(1,0,0,1,0,0),o.drawImage(t.dom,0,0,h*e,f*e),o.restore()}for(var r,a,o,s,l,u,c=0,h=this._width,f=this._height,d=this._progress,m=0,g=t[G];g>m;m++){var y=t[m],_=this._singleCanvas?0:y[L],x=y.__frame;if(0>x&&l&&(i(l),l=null),a!==_&&(o&&o.restore(),s={},a=_,r=this.getLayer(a),r.isBuildin||v("ZLevel "+a+" has been used by unkown layer "+r.id),o=r.ctx,o.save(),r.__unusedCount=0,(r[n]||e)&&r.clear()),r[n]||e){if(x>=0){if(!l){if(l=this._progressiveLayers[Math.min(c++,b-1)],l.ctx.save(),l.renderScope={},l&&l.__progress>l.__maxProgress){m=l.__nextIdxNotProg-1;continue}u=l.__progress,l[n]||(d=u),l.__progress=d+1}x===d&&this._doPaintEl(y,l,!0,l.renderScope)}else this._doPaintEl(y,r,e,s);y[n]=!1}}l&&i(l),o&&o.restore(),this._furtherProgressive=!1,p.each(this._progressiveLayers,function(t){t.__maxProgress>=t.__progress&&(this._furtherProgressive=!0)},this)},_doPaintEl:function(t,e,i,r){var a=e.ctx,u=t[o];if(!(!e[n]&&!i||t.invisible||0===t.style.opacity||u&&!u[0]&&!u[3]||t.culling&&s(t,this._width,this._height))){var c=t.__clipPaths;(r.prevClipLayer!==e||l(c,r.prevElClipPaths))&&(r.prevElClipPaths&&(r.prevClipLayer.ctx.restore(),r.prevClipLayer=r.prevElClipPaths=null,r.prevEl=null),c&&(a.save(),h(c,a),r.prevClipLayer=e,r.prevElClipPaths=c)),t.beforeBrush&&t.beforeBrush(a),t.brush(a,r.prevEl||null),r.prevEl=t,t.afterBrush&&t.afterBrush(a)}},getLayer:function(t){if(this._singleCanvas)return this._layers[0];var e=this._layers[t];return e||(e=new _("zr_"+t,this,this.dpr),e.isBuildin=!0,this._layerConfig[t]&&p.merge(e,this._layerConfig[t],!0),this.insertLayer(t,e),e.initContext()),e},insertLayer:function(t,e){var n=this._layers,r=this._zlevelList,a=r[G],o=null,s=-1,l=this._domRoot;if(n[t])return void v("ZLevel "+t+" has been used already");if(!i(e))return void v("Layer of zlevel "+t+" is not valid");if(a>0&&t>r[0]){for(s=0;a-1>s&&!(r[s]<t&&r[s+1]>t);s++);o=n[r[s]]}if(r[k](s+1,0,t),o){var u=o.dom;u.nextSibling?l.insertBefore(e.dom,u.nextSibling):l.appendChild(e.dom)}else l.firstChild?l.insertBefore(e.dom,l.firstChild):l.appendChild(e.dom);n[t]=e},eachLayer:function(t,e){var i,n,r=this._zlevelList;for(n=0;n<r[G];n++)i=r[n],t.call(e,this._layers[i],i)},eachBuildinLayer:function(t,e){var i,n,r,a=this._zlevelList;for(r=0;r<a[G];r++)n=a[r],i=this._layers[n],i.isBuildin&&t.call(e,i,n)},eachOtherLayer:function(t,e){var i,n,r,a=this._zlevelList;for(r=0;r<a[G];r++)n=a[r],i=this._layers[n],i.isBuildin||t.call(e,i,n)},getLayers:function(){return this._layers},_updateLayerStatus:function(t){var e=this._layers,i=this._progressiveLayers,r={},a={};this.eachBuildinLayer(function(t,e){r[e]=t.elCount,t.elCount=0,t[n]=!1}),p.each(i,function(t,e){a[e]=t.elCount,t.elCount=0,t[n]=!1});for(var o,s,l=0,u=0,c=0,h=t[G];h>c;c++){var f=t[c],d=this._singleCanvas?0:f[L],v=e[d],m=f.progressive;if(v&&(v.elCount++,v[n]=v[n]||f[n]),m>=0){s!==m&&(s=m,u++);var g=f.__frame=u-1;if(!o){var y=Math.min(l,b-1);o=i[y],o||(o=i[y]=new _("progressive",this,this.dpr),o.initContext()),o.__maxProgress=0}o[n]=o[n]||f[n],o.elCount++,o.__maxProgress=Math.max(o.__maxProgress,g),o.__maxProgress>=o.__progress&&(v[n]=!0)}else f.__frame=-1,o&&(o.__nextIdxNotProg=c,l++,o=null)}o&&(l++,o.__nextIdxNotProg=c),this.eachBuildinLayer(function(t,e){r[e]!==t.elCount&&(t[n]=!0)}),i[G]=Math.min(l,b),p.each(i,function(t,e){a[e]!==t.elCount&&(f[n]=!0),t[n]&&(t.__progress=0)})},clear:function(){return this.eachBuildinLayer(this._clearLayer),this},_clearLayer:function(t){t.clear()},configLayer:function(t,e){if(e){var i=this._layerConfig;i[t]?p.merge(i[t],e,!0):i[t]=e;var n=this._layers[t];n&&p.merge(n,i[t],!0)}},delLayer:function(t){var e=this._layers,i=this._zlevelList,n=e[t];n&&(n.dom.parentNode.removeChild(n.dom),delete e[t],i[k](p[F](i,t),1))},resize:function(t,e){var i=this._domRoot;i.style.display="none";var n=this._opts;if(null!=t&&(n.width=t),null!=e&&(n[$]=e),t=this._getSize(0),e=this._getSize(1),i.style.display="",this._width!=t||e!=this._height){i.style.width=t+"px",i.style[$]=e+"px";for(var r in this._layers)this._layers.hasOwnProperty(r)&&this._layers[r][Y](t,e);p.each(this._progressiveLayers,function(i){i[Y](t,e)}),this.refresh(!0)}return this._width=t,this._height=e,this},clearLayer:function(t){var e=this._layers[t];e&&e.clear()},dispose:function(){this.root.innerHTML="",this.root=this[q]=this._domRoot=this._layers=null},getRenderedCanvas:function(t){if(t=t||{},this._singleCanvas)return this._layers[0].dom;var e=new _("image",this,t.pixelRatio||this.dpr);e.initContext(),e.clearColor=t.backgroundColor,e.clear();for(var i=this[q].getDisplayList(!0),n={},r=0;r<i[G];r++){var a=i[r];this._doPaintEl(a,e,!0,n)}return e.dom},getWidth:function(){return this._width},getHeight:function(){return this._height},_getSize:function(t){var i=this._opts,n=["width",$][t],r=["clientWidth","clientHeight"][t],a=["paddingLeft","paddingTop"][t],o=["paddingRight","paddingBottom"][t];if(null!=i[n]&&"auto"!==i[n])return parseFloat(i[n]);var s=this.root,l=document.defaultView.getComputedStyle(s);return(s[r]||e(l[n])||e(s.style[n]))-(e(l[a])||0)-(e(l[o])||0)|0},_pathToImage:function(e,i,n,r,a){var o=document[w]("canvas"),s=o.getContext("2d");o.width=n*a,o[$]=r*a,s.clearRect(0,0,n*a,r*a);var l={position:i[y],rotation:i.rotation,scale:i.scale};i[y]=[0,0,0],i.rotation=0,i.scale=[1,1],i&&i.brush(s);var u=t("./graphic/Image"),c=new u({id:e,style:{x:0,y:0,image:o}});return null!=l[y]&&(c[y]=i[y]=l[y]),null!=l.rotation&&(c.rotation=i.rotation=l.rotation),null!=l.scale&&(c.scale=i.scale=l.scale),c},_createPathToImage:function(){var t=this;return function(e,i,n,r){return t._pathToImage(e,i,n,r,t.dpr)}}},S}),e("zrender/graphic/helper/smoothSpline",[ne,"../../core/vector"],function(t){function e(t,e,i,n,r,a,o){var s=.5*(i-t),l=.5*(n-e);return(2*(e-i)+s+l)*o+(-3*(e-i)-2*s-l)*a+s*r+e}var i=t("../../core/vector");return function(t,n){for(var r=t[G],a=[],o=0,s=1;r>s;s++)o+=i.distance(t[s-1],t[s]);var l=o/2;l=r>l?r:l;for(var s=0;l>s;s++){var u,c,h,f=s/(l-1)*(n?r:r-1),d=Math.floor(f),p=f-d,v=t[d%r];n?(u=t[(d-1+r)%r],c=t[(d+1)%r],h=t[(d+2)%r]):(u=t[0===d?d:d-1],c=t[d>r-2?r-1:d+1],h=t[d>r-3?r-1:d+2]);var m=p*p,g=p*m;a.push([e(u[0],v[0],c[0],h[0],p,m,g),e(u[1],v[1],c[1],h[1],p,m,g)])}return a}}),e("zrender/graphic/helper/smoothBezier",[ne,"../../core/vector"],function(t){var e=t("../../core/vector"),i=e.min,n=e.max,r=e.scale,a=e.distance,o=e.add;return function(t,s,l,u){var c,h,f,d,p=[],v=[],m=[],g=[];if(u){f=[1/0,1/0],d=[-1/0,-1/0];for(var y=0,_=t[G];_>y;y++)i(f,f,t[y]),n(d,d,t[y]);i(f,f,u[0]),n(d,d,u[1])}for(var y=0,_=t[G];_>y;y++){var x=t[y];if(l)c=t[y?y-1:_-1],h=t[(y+1)%_];else{if(0===y||y===_-1){p.push(e.clone(t[y]));continue}c=t[y-1],h=t[y+1]}e.sub(v,h,c),r(v,v,s);var b=a(x,c),w=a(x,h),M=b+w;0!==M&&(b/=M,w/=M),r(m,v,-b),r(g,v,w);var T=o([],x,m),S=o([],x,g);u&&(n(T,T,f),i(T,T,d),n(S,S,f),i(S,S,d)),p.push(T),p.push(S)}return l&&p.push(p.shift()),p}}),e("zrender/mixin/Draggable",[ne],function(){function t(){this.on("mousedown",this._dragStart,this),this.on("mousemove",this._drag,this),this.on("mouseup",this._dragEnd,this),this.on("globalout",this._dragEnd,this)}return t[K]={constructor:t,_dragStart:function(t){var e=t[z];e&&e.draggable&&(this._draggingTarget=e,e.dragging=!0,this._x=t.offsetX,this._y=t.offsetY,this.dispatchToElement(e,"dragstart",t.event))},_drag:function(t){var e=this._draggingTarget;if(e){var i=t.offsetX,n=t.offsetY,r=i-this._x,a=n-this._y;this._x=i,this._y=n,e.drift(r,a,t),this.dispatchToElement(e,"drag",t.event);var o=this.findHover(i,n,e),s=this._dropTarget;this._dropTarget=o,e!==o&&(s&&o!==s&&this.dispatchToElement(s,"dragleave",t.event),o&&o!==s&&this.dispatchToElement(o,"dragenter",t.event))}},_dragEnd:function(t){var e=this._draggingTarget;e&&(e.dragging=!1),this.dispatchToElement(e,"dragend",t.event),this._dropTarget&&this.dispatchToElement(this._dropTarget,"drop",t.event),this._draggingTarget=null,this._dropTarget=null}},t}),e("zrender/animation/requestAnimationFrame",[ne],function(){return typeof window!==r&&(window.requestAnimationFrame||window.msRequestAnimationFrame||window.mozRequestAnimationFrame||window.webkitRequestAnimationFrame)||function(t){setTimeout(t,16)}}),e("zrender/graphic/helper/roundRect",[ne],function(){return{buildPath:function(t,e){var n,r,o,s,l=e.x,u=e.y,c=e.width,h=e[$],f=e.r;0>c&&(l+=c,c=-c),0>h&&(u+=h,h=-h),"number"==typeof f?n=r=o=s=f:f instanceof Array?1===f[G]?n=r=o=s=f[0]:2===f[G]?(n=o=f[0],r=s=f[1]):3===f[G]?(n=f[0],r=s=f[1],o=f[2]):(n=f[0],r=f[1],o=f[2],s=f[3]):n=r=o=s=0;var d;n+r>c&&(d=n+r,n*=c/d,r*=c/d),o+s>c&&(d=o+s,o*=c/d,s*=c/d),r+o>h&&(d=r+o,r*=h/d,o*=h/d),n+s>h&&(d=n+s,n*=h/d,s*=h/d),t[a](l+n,u),t[i](l+c-r,u),0!==r&&t.quadraticCurveTo(l+c,u,l+c,u+r),t[i](l+c,u+h-o),0!==o&&t.quadraticCurveTo(l+c,u+h,l+c-o,u+h),t[i](l+s,u+h),0!==s&&t.quadraticCurveTo(l,u+h,l,u+h-s),t[i](l,u+n),0!==n&&t.quadraticCurveTo(l,u,l+n,u)}}}),e("zrender/core/event",[ne,"../mixin/Eventful","./env"],function(t){function e(t){return t.getBoundingClientRect?t.getBoundingClientRect():{left:0,top:0}}function i(t,e,i,r){return i=i||{},r?n(t,e,i):u.browser.firefox&&null!=e.layerX&&e.layerX!==e.offsetX?(i.zrX=e.layerX,i.zrY=e.layerY):null!=e.offsetX?(i.zrX=e.offsetX,i.zrY=e.offsetY):n(t,e,i),i}function n(t,i,n){var r=e(t);n.zrX=i.clientX-r.left,n.zrY=i.clientY-r.top}function a(t,e,n){if(e=e||window.event,null!=e.zrX)return e;var r=e.type,a=r&&r[F]("touch")>=0;if(a){var o="touchend"!=r?e.targetTouches[0]:e.changedTouches[0];o&&i(t,o,e,n)}else i(t,e,e,n),e.zrDelta=e.wheelDelta?e.wheelDelta/120:-(e.detail||0)/3;return e}function o(t,e,i){c?t.addEventListener(e,i):t.attachEvent("on"+e,i)}function s(t,e,i){c?t.removeEventListener(e,i):t.detachEvent("on"+e,i)}var l=t("../mixin/Eventful"),u=t("./env"),c=typeof window!==r&&!!window.addEventListener,h=c?function(t){t.preventDefault(),t.stopPropagation(),t.cancelBubble=!0}:function(t){t.returnValue=!1,t.cancelBubble=!0};return{clientToLocal:i,normalizeEvent:a,addEventListener:o,removeEventListener:s,stop:h,Dispatcher:l}}),e("zrender/core/GestureMgr",[ne,"./event"],function(t){function e(t){var e=t[1][0]-t[0][0],i=t[1][1]-t[0][1];return Math.sqrt(e*e+i*i)}function i(t){return[(t[0][0]+t[1][0])/2,(t[0][1]+t[1][1])/2]}var n=t("./event"),r=function(){this._track=[]};r[K]={constructor:r,recognize:function(t,e,i){return this._doTrack(t,e,i),this._recognize(t)},clear:function(){return this._track[G]=0,this},_doTrack:function(t,e,i){var r=t.touches;if(r){for(var a={points:[],touches:[],target:e,event:t},o=0,s=r[G];s>o;o++){var l=r[o],u=n.clientToLocal(i,l,{});a.points.push([u.zrX,u.zrY]),a.touches.push(l)}this._track.push(a)}},_recognize:function(t){for(var e in a)if(a.hasOwnProperty(e)){var i=a[e](this._track,t);if(i)return i}}};var a={pinch:function(t,n){var r=t[G];if(r){var a=(t[r-1]||{}).points,o=(t[r-2]||{}).points||a;if(o&&o[G]>1&&a&&a[G]>1){var s=e(a)/e(o);!isFinite(s)&&(s=1),n.pinchScale=s;var l=i(a);return n.pinchX=l[0],n.pinchY=l[1],{type:"pinch",target:t[0][z],event:n}}}}};return r}),e("zrender/Layer",[ne,"./core/util","./config","./graphic/Style","./graphic/Pattern"],function(t){function e(){return!1}function i(t,e,i,n){var r=document[w](e),a=i[X](),o=i[Z](),s=r.style;return s[y]="absolute",s.left=0,s.top=0,s.width=a+"px",s[$]=o+"px",r.width=a*n,r[$]=o*n,r.setAttribute("data-zr-dom-id",t),r}var n=t("./core/util"),r=t("./config"),a=t("./graphic/Style"),o=t("./graphic/Pattern"),s=function(t,a,o){var s;o=o||r.devicePixelRatio,typeof t===Q?s=i(t,"canvas",a,o):n[D](t)&&(s=t,t=s.id),this.id=t,this.dom=s;var l=s.style;l&&(s.onselectstart=e,l["-webkit-user-select"]="none",l["user-select"]="none",l["-webkit-touch-callout"]="none",l["-webkit-tap-highlight-color"]="rgba(0,0,0,0)",l.padding=0,l.margin=0,l["border-width"]=0),this.domBack=null,this.ctxBack=null,this.painter=a,this.config=null,this.clearColor=0,this.motionBlur=!1,this.lastFrameAlpha=.7,this.dpr=o};return s[K]={constructor:s,elCount:0,__dirty:!0,initContext:function(){this.ctx=this.dom.getContext("2d"),this.ctx.dpr=this.dpr},createBackBuffer:function(){var t=this.dpr;this.domBack=i("back-"+this.id,"canvas",this.painter,t),this.ctxBack=this.domBack.getContext("2d"),1!=t&&this.ctxBack.scale(t,t)},resize:function(t,e){var i=this.dpr,n=this.dom,r=n.style,a=this.domBack;r.width=t+"px",r[$]=e+"px",n.width=t*i,n[$]=e*i,a&&(a.width=t*i,a[$]=e*i,1!=i&&this.ctxBack.scale(i,i))},clear:function(t){var e=this.dom,i=this.ctx,n=e.width,r=e[$],s=this.clearColor,l=this.motionBlur&&!t,u=this.lastFrameAlpha,c=this.dpr;if(l&&(this.domBack||this.createBackBuffer(),this.ctxBack.globalCompositeOperation="copy",this.ctxBack.drawImage(e,0,0,n/c,r/c)),i.clearRect(0,0,n,r),s){var h;s.colorStops?(h=s.__canvasGradient||a.getGradient(i,s,{x:0,y:0,width:n,height:r}),s.__canvasGradient=h):s.image&&(h=o[K].getCanvasPattern.call(s,i)),i.save(),i.fillStyle=h||s,i.fillRect(0,0,n,r),i.restore()}if(l){var f=this.domBack;i.save(),i.globalAlpha=u,i.drawImage(f,0,0,n,r),i.restore()}}},s}),e("echarts/preprocessor/helper/compatStyle",[ne,ie],function(t){function e(t){var e=t&&t.itemStyle;e&&i.each(n,function(n){var r=e.normal,a=e.emphasis;r&&r[n]&&(t[n]=t[n]||{},t[n].normal?i.merge(t[n].normal,r[n]):t[n].normal=r[n],r[n]=null),a&&a[n]&&(t[n]=t[n]||{},t[n].emphasis?i.merge(t[n].emphasis,a[n]):t[n].emphasis=a[n],a[n]=null)})}var i=t(ie),n=["areaStyle","lineStyle","nodeStyle","linkStyle","chordStyle","label","labelLine"];return function(t){if(t){e(t),e(t.markPoint),e(t.markLine);var n=t.data;if(n){for(var r=0;r<n[G];r++)e(n[r]);var a=t.markPoint;if(a&&a.data)for(var o=a.data,r=0;r<o[G];r++)e(o[r]);
var s=t.markLine;if(s&&s.data)for(var l=s.data,r=0;r<l[G];r++)i[P](l[r])?(e(l[r][0]),e(l[r][1])):e(l[r])}}}}),e("echarts/chart/helper/createListFromArray",[ne,"../../data/List","../../data/helper/completeDimensions",ie,"../../util/model","../../CoordinateSystem"],function(t){function e(t){for(var e=0;e<t[G]&&null==t[e];)e++;return t[e]}function i(t){var i=e(t);return null!=i&&!u[P](f(i))}function n(t,e,n){t=t||[];var r=e.get(te),a=p[r],v=h.get(r),m=a&&a(t,e,n),g=m&&m[_];g||(g=v&&v[_]||["x","y"],g=l(g,t,g[b](["value"])));var y=m?m.categoryIndex:-1,x=new s(g,e),w=o(m,t),M={},T=y>=0&&i(t)?function(t,e,i,n){return c.isDataItemOption(t)&&(x.hasItemOption=!0),n===y?i:d(f(t),g[n])}:function(t,e,i,n){var r=f(t),a=d(r&&r[n],g[n]);c.isDataItemOption(t)&&(x.hasItemOption=!0);var o=m&&m.categoryAxesModels;return o&&o[e]&&typeof a===Q&&(M[e]=M[e]||o[e].getCategories(),a=u[F](M[e],a),0>a&&!isNaN(a)&&(a=+a)),a};return x.hasItemOption=!1,x.initData(t,w,T),x}function r(t){return"category"!==t&&"time"!==t}function a(t){return"category"===t?g:"time"===t?"time":"float"}function o(t,e){var i,n=[],r=t&&t[_][t.categoryIndex];if(r&&(i=t.categoryAxesModels[r.name]),i){var a=i.getCategories();if(a){var o=e[G];if(u[P](e[0])&&e[0][G]>1){n=[];for(var s=0;o>s;s++)n[s]=a[e[s][t.categoryIndex||0]]}else n=a.slice(0)}}return n}var s=t("../../data/List"),l=t("../../data/helper/completeDimensions"),u=t(ie),c=t("../../util/model"),h=t("../../CoordinateSystem"),f=c.getDataItemValue,d=c.converDataValue,p={cartesian2d:function(t,e,i){var n=u.map(["xAxis","yAxis"],function(t){return i.queryComponents({mainType:t,index:e.get(t+"Index"),id:e.get(t+"Id")})[0]}),o=n[0],s=n[1],c=o.get("type"),h=s.get("type"),f=[{name:"x",type:a(c),stackable:r(c)},{name:"y",type:a(h),stackable:r(h)}],d="category"===c,p="category"===h;l(f,t,["x","y","z"]);var v={};return d&&(v.x=o),p&&(v.y=s),{dimensions:f,categoryIndex:d?0:p?1:-1,categoryAxesModels:v}},polar:function(t,e,i){var n=i.queryComponents({mainType:"polar",index:e.get("polarIndex"),id:e.get("polarId")})[0],o=n.findAxisModel("angleAxis"),s=n.findAxisModel("radiusAxis"),u=s.get("type"),c=o.get("type"),h=[{name:"radius",type:a(u),stackable:r(u)},{name:"angle",type:a(c),stackable:r(c)}],f="category"===c,d="category"===u;l(h,t,["radius","angle","value"]);var p={};return d&&(p.radius=s),f&&(p.angle=o),{dimensions:h,categoryIndex:f?1:d?0:-1,categoryAxesModels:p}},geo:function(t){return{dimensions:l([{name:"lng"},{name:"lat"}],t,["lng","lat","value"])}}};return n}),e("echarts/coord/axisHelper",[ne,"../scale/Ordinal","../scale/Interval","../scale/Time","../scale/Log","../scale/Scale","../util/number",ie,"zrender/contain/text"],function(t){var e=t("../scale/Ordinal"),i=t("../scale/Interval");t("../scale/Time"),t("../scale/Log");var n=t("../scale/Scale"),r=t("../util/number"),a=t(ie),o=t("zrender/contain/text"),s={};return s.getScaleExtent=function(t,e){var i=t.scale,n=i[M](),o=n[1]-n[0];if(i.type===g)return isFinite(o)?n:[0,0];var s=e.getMin?e.getMin():e.get("min"),l=e.getMax?e.getMax():e.get("max"),u=e.getNeedCrossZero?e.getNeedCrossZero():!e.get("scale"),c=e.get("boundaryGap");a[P](c)||(c=[c||0,c||0]),c[0]=r[p](c[0],1),c[1]=r[p](c[1],1);var h=!0,f=!0;return null==s&&(s=n[0]-c[0]*o,h=!1),null==l&&(l=n[1]+c[1]*o,f=!1),"dataMin"===s&&(s=n[0]),"dataMax"===l&&(l=n[1]),u&&(s>0&&l>0&&!h&&(s=0),0>s&&0>l&&!f&&(l=0)),[s,l]},s.niceScaleExtent=function(t,e){var i=t.scale,n=s.getScaleExtent(t,e),r=null!=(e.getMin?e.getMin():e.get("min")),a=null!=(e.getMax?e.getMax():e.get("max")),o=e.get("splitNumber");"log"===i.type&&(i.base=e.get("logBase")),i.setExtent(n[0],n[1]),i.niceExtent(o,r,a);var l=e.get("minInterval");if(isFinite(l)&&!r&&!a&&"interval"===i.type){var u=i.getInterval(),c=Math.max(Math.abs(u),l)/u;n=i[M]();var h=(n[1]+n[0])/2;i.setExtent(c*(n[0]-h)+h,c*(n[1]-h)+h),i.niceExtent(o)}var u=e.get("interval");null!=u&&i.setInterval&&i.setInterval(u)},s.createScaleByModel=function(t,r){if(r=r||t.get("type"))switch(r){case"category":return new e(t.getCategories(),[1/0,-1/0]);case"value":return new i;default:return(n.getClass(r)||i)[E](t)}},s.ifAxisCrossZero=function(t){var e=t.scale[M](),i=e[0],n=e[1];return!(i>0&&n>0||0>i&&0>n)},s.getAxisLabelInterval=function(t,e,i,n){var r,a=0,s=0,l=1;e[G]>40&&(l=Math.floor(e[G]/40));for(var u=0;u<t[G];u+=l){var h=t[u],f=o[c](e[u],i,d,"top");f[n?"x":"y"]+=h,f[n?"width":$]*=1.3,r?r.intersect(f)?(s++,a=Math.max(a,s)):(r.union(f),s=0):r=f.clone()}return 0===a&&l>1?l:(a+1)*l-1},s.getFormattedLabels=function(t,e){var i=t.scale,n=i.getTicksLabels(),r=i.getTicks();return typeof e===Q?(e=function(t){return function(e){return t[A]("{value}",e)}}(e),a.map(n,e)):typeof e===C?a.map(r,function(n,r){return e("category"===t.type?i.getLabel(n):n,r)},this):n},s}),e("echarts/coord/cartesian/Cartesian2D",[ne,ie,"./Cartesian"],function(t){function e(t){n.call(this,t)}var i=t(ie),n=t("./Cartesian");return e[K]={constructor:e,type:"cartesian2d",dimensions:["x","y"],getBaseAxis:function(){return this.getAxesByScale(g)[0]||this.getAxesByScale("time")[0]||this.getAxis("x")},containPoint:function(t){var e=this.getAxis("x"),i=this.getAxis("y");return e[T](e.toLocalCoord(t[0]))&&i[T](i.toLocalCoord(t[1]))},containData:function(t){return this.getAxis("x").containData(t[0])&&this.getAxis("y").containData(t[1])},dataToPoints:function(t,e){return t.mapArray(["x","y"],function(t,e){return this.dataToPoint([t,e])},e,this)},dataToPoint:function(t,e){var i=this.getAxis("x"),n=this.getAxis("y");return[i.toGlobalCoord(i.dataToCoord(t[0],e)),n.toGlobalCoord(n.dataToCoord(t[1],e))]},pointToData:function(t,e){var i=this.getAxis("x"),n=this.getAxis("y");return[i.coordToData(i.toLocalCoord(t[0]),e),n.coordToData(n.toLocalCoord(t[1]),e)]},getOtherAxis:function(t){return this.getAxis("x"===t.dim?"y":"x")}},i[S](e,n),e}),e("echarts/coord/cartesian/Axis2D",[ne,ie,"../Axis","./axisLabelInterval"],function(t){var e=t(ie),i=t("../Axis"),n=t("./axisLabelInterval"),r=function(t,e,n,r,a){i.call(this,t,e,n),this.type=r||"value",this[y]=a||V};return r[K]={constructor:r,index:0,onZero:!1,model:null,isHorizontal:function(){var t=this[y];return"top"===t||t===V},getGlobalExtent:function(){var t=this[M]();return t[0]=this.toGlobalCoord(t[0]),t[1]=this.toGlobalCoord(t[1]),t},getLabelInterval:function(){var t=this._labelInterval;return t||(t=this._labelInterval=n(this)),t},isLabelIgnored:function(t){if("category"===this.type){var e=this.getLabelInterval();return typeof e===C&&!e(t,this.scale.getLabel(t))||t%(e+1)}},toLocalCoord:null,toGlobalCoord:null},e[S](r,i),r}),e("echarts/coord/cartesian/GridModel",[ne,"./AxisModel","../../model/Component"],function(t){t("./AxisModel");var e=t("../../model/Component");return e[I]({type:"grid",dependencies:["xAxis","yAxis"],layoutMode:"box",coordinateSystem:null,defaultOption:{show:!1,zlevel:0,z:0,left:"10%",top:60,right:"10%",bottom:60,containLabel:!1,backgroundColor:"rgba(0,0,0,0)",borderWidth:1,borderColor:"#ccc"}})}),e("echarts/util/symbol",[ne,"./graphic","zrender/core/BoundingRect"],function(t){var e=t("./graphic"),n=t("zrender/core/BoundingRect"),r=e.extendShape({type:"triangle",shape:{cx:0,cy:0,width:0,height:0},buildPath:function(t,e){var n=e.cx,r=e.cy,o=e.width/2,s=e[$]/2;t[a](n,r-s),t[i](n+o,r+s),t[i](n-o,r+s),t.closePath()}}),o=e.extendShape({type:"diamond",shape:{cx:0,cy:0,width:0,height:0},buildPath:function(t,e){var n=e.cx,r=e.cy,o=e.width/2,s=e[$]/2;t[a](n,r-s),t[i](n+o,r),t[i](n,r+s),t[i](n-o,r),t.closePath()}}),s=e.extendShape({type:"pin",shape:{x:0,y:0,width:0,height:0},buildPath:function(t,e){var i=e.x,n=e.y,r=e.width/5*3,a=Math.max(r,e[$]),o=r/2,s=o*o/(a-o),l=n-a+o+s,u=Math.asin(s/o),c=Math.cos(u)*o,h=Math.sin(u),f=Math.cos(u);t.arc(i,l,o,Math.PI-u,2*Math.PI+u);var d=.6*o,p=.7*o;t.bezierCurveTo(i+c-h*d,l+s+f*d,i,n-p,i,n),t.bezierCurveTo(i,n-p,i-c+h*d,l+s+f*d,i-c,l+s),t.closePath()}}),l=e.extendShape({type:"arrow",shape:{x:0,y:0,width:0,height:0},buildPath:function(t,e){var n=e[$],r=e.width,o=e.x,s=e.y,l=r/3*2;t[a](o,s),t[i](o+l,s+n),t[i](o,s+n/4*3),t[i](o-l,s+n),t[i](o,s),t.closePath()}}),u={line:e.Line,rect:e.Rect,roundRect:e.Rect,square:e.Rect,circle:e.Circle,diamond:o,pin:s,arrow:l,triangle:r},c={line:function(t,e,i,n,r){r.x1=t,r.y1=e+n/2,r.x2=t+i,r.y2=e+n/2},rect:function(t,e,i,n,r){r.x=t,r.y=e,r.width=i,r[$]=n},roundRect:function(t,e,i,n,r){r.x=t,r.y=e,r.width=i,r[$]=n,r.r=Math.min(i,n)/4},square:function(t,e,i,n,r){var a=Math.min(i,n);r.x=t,r.y=e,r.width=a,r[$]=a},circle:function(t,e,i,n,r){r.cx=t+i/2,r.cy=e+n/2,r.r=Math.min(i,n)/2},diamond:function(t,e,i,n,r){r.cx=t+i/2,r.cy=e+n/2,r.width=i,r[$]=n},pin:function(t,e,i,n,r){r.x=t+i/2,r.y=e+n/2,r.width=i,r[$]=n},arrow:function(t,e,i,n,r){r.x=t+i/2,r.y=e+n/2,r.width=i,r[$]=n},triangle:function(t,e,i,n,r){r.cx=t+i/2,r.cy=e+n/2,r.width=i,r[$]=n}},f={};for(var p in u)u.hasOwnProperty(p)&&(f[p]=new u[p]);var v=e.extendShape({type:"symbol",shape:{symbolType:"",x:0,y:0,width:0,height:0},beforeBrush:function(){var t=this.style,e=this.shape;"pin"===e.symbolType&&"inside"===t.textPosition&&(t.textPosition=["50%","40%"],t.textAlign=d,t.textVerticalAlign=x)},buildPath:function(t,e,i){var n=e.symbolType,r=f[n];"none"!==e.symbolType&&(r||(n="rect",r=f[n]),c[n](e.x,e.y,e.width,e[$],r.shape),r.buildPath(t,r.shape,i))}}),m=function(t){if("image"!==this.type){var e=this.style,i=this.shape;i&&"line"===i.symbolType?e[h]=t:this.__isEmptyBrush?(e[h]=t,e.fill="#fff"):(e.fill&&(e.fill=t),e[h]&&(e[h]=t)),this.dirty(!1)}},g={createSymbol:function(t,i,r,a,o,s){var l=0===t[F]("empty");l&&(t=t.substr(5,1)[J]()+t.substr(6));var u;return u=0===t[F]("image://")?new e.Image({style:{image:t.slice(8),x:i,y:r,width:a,height:o}}):0===t[F]("path://")?e.makePath(t.slice(7),{},new n(i,r,a,o)):new v({shape:{symbolType:t,x:i,y:r,width:a,height:o}}),u.__isEmptyBrush=l,u.setColor=m,u.setColor(s),u}};return g}),e("echarts/component/helper/listComponent",[ne,"../../util/layout","../../util/format","../../util/graphic"],function(t){function e(t,e,n){i.positionGroup(t,e.getBoxLayoutParams(),{width:n[X](),height:n[Z]()},e.get("padding"))}var i=t("../../util/layout"),n=t("../../util/format"),r=t("../../util/graphic");return{layout:function(t,n,r){var a=i.getLayoutRect(n.getBoxLayoutParams(),{width:r[X](),height:r[Z]()},n.get("padding"));i.box(n.get("orient"),t,n.get("itemGap"),a.width,a[$]),e(t,n,r)},addBackground:function(t,e){var i=n.normalizeCssArray(e.get("padding")),a=t[c](),o=e.getItemStyle(["color","opacity"]);o.fill=e.get("backgroundColor");var s=new r.Rect({shape:{x:a.x-i[3],y:a.y-i[0],width:a.width+i[1]+i[3],height:a[$]+i[0]+i[2]},style:o,silent:!0,z2:-1});r.subPixelOptimizeRect(s),t.add(s)}}}),e("echarts/component/tooltip/TooltipContent",[ne,ie,"zrender/tool/color","zrender/core/event","../../util/format","zrender/core/env"],function(t){function e(t){var e="cubic-bezier(0.23, 1, 0.32, 1)",i="left "+t+"s "+e+",top "+t+"s "+e;return o.map(p,function(t){return t+"transition:"+i}).join(";")}function i(t){var e=[],i=t.get("fontSize"),n=t.getTextColor();return n&&e.push("color:"+n),e.push("font:"+t.getFont()),i&&e.push("line-height:"+Math.round(3*i/2)+"px"),c(["decoration","align"],function(i){var n=t.get(i);n&&e.push("text-"+i+":"+n)}),e.join(";")}function n(t){t=t;var n=[],r=t.get("transitionDuration"),a=t.get("backgroundColor"),o=t[U](f),l=t.get("padding");return r&&n.push(e(r)),a&&(d[W]?n.push("background-Color:"+a):(n.push("background-Color:#"+s.toHex(a)),n.push("filter:alpha(opacity=70)"))),c(["width","color","radius"],function(e){var i="border-"+e,r=h(i),a=t.get(r);null!=a&&n.push(i+":"+a+("color"===e?"":"px"))}),n.push(i(o)),null!=l&&n.push("padding:"+u.normalizeCssArray(l).join("px ")+"px"),n.join(";")+";"}function r(t,e){var i=document[w]("div"),n=e.getZr();this.el=i,this._x=e[X]()/2,this._y=e[Z]()/2,t.appendChild(i),this._container=t,this._show=!1,this._hideTimeout;var r=this;i.onmouseenter=function(){r.enterable&&(clearTimeout(r._hideTimeout),r._show=!0),r._inContent=!0},i.onmousemove=function(e){if(e=e||window.event,!r.enterable){var i=n.handler;l.normalizeEvent(t,e,!0),i.dispatch("mousemove",e)}},i.onmouseleave=function(){r.enterable&&r._show&&r.hideLater(r._hideDelay),r._inContent=!1},a(i,t)}function a(t,e){function i(t){n(t[z])||t.preventDefault()}function n(i){for(;i&&i!==e;){if(i===t)return!0;i=i.parentNode}}l.addEventListener(e,"touchstart",i),l.addEventListener(e,"touchmove",i),l.addEventListener(e,"touchend",i)}var o=t(ie),s=t("zrender/tool/color"),l=t("zrender/core/event"),u=t("../../util/format"),c=o.each,h=u.toCamelCase,d=t("zrender/core/env"),p=["","-webkit-","-moz-","-o-"],v="position:absolute;display:block;border-style:solid;white-space:nowrap;z-index:9999999;";return r[K]={constructor:r,enterable:!0,update:function(){var t=this._container,e=t.currentStyle||document.defaultView.getComputedStyle(t),i=t.style;"absolute"!==i[y]&&"absolute"!==e[y]&&(i[y]="relative")},show:function(t){clearTimeout(this._hideTimeout);var e=this.el;e.style.cssText=v+n(t)+";left:"+this._x+"px;top:"+this._y+"px;"+(t.get("extraCssText")||""),e.style.display=e.innerHTML?"block":"none",this._show=!0},setContent:function(t){var e=this.el;e.innerHTML=t,e.style.display=t?"block":"none"},moveTo:function(t,e){var i=this.el.style;i.left=t+"px",i.top=e+"px",this._x=t,this._y=e},hide:function(){this.el.style.display="none",this._show=!1},hideLater:function(t){!this._show||this._inContent&&this.enterable||(t?(this._hideDelay=t,this._show=!1,this._hideTimeout=setTimeout(o.bind(this.hide,this),t)):this.hide())},isShow:function(){return this._show}},r}),e("echarts/data/helper/completeDimensions",[ne,ie],function(t){function e(t,e,a,o){if(!e)return t;var s=i(e[0]),l=n[P](s)&&s[G]||1;a=a||[],o=o||"extra";for(var u=0;l>u;u++)if(!t[u]){var c=a[u]||o+(u-a[G]);t[u]=r(e,u)?{type:"ordinal",name:c}:c}return t}function i(t){return n[P](t)?t:n[D](t)?t.value:t}var n=t(ie),r=e.guessOrdinal=function(t,e){for(var r=0,a=t[G];a>r;r++){var o=i(t[r]);if(!n[P](o))return!1;var o=o[e];if(null!=o&&isFinite(o))return!1;if(n.isString(o)&&"-"!==o)return!0}return!1};return e}),e("echarts/scale/Ordinal",[ne,ie,"./Scale"],function(t){var e=t(ie),i=t("./Scale"),n=i[K],r=i[I]({type:"ordinal",init:function(t,e){this._data=t,this._extent=e||[0,t[G]-1]},parse:function(t){return typeof t===Q?e[F](this._data,t):Math.round(t)},contain:function(t){return t=this.parse(t),n[T].call(this,t)&&null!=this._data[t]},normalize:function(t){return n.normalize.call(this,this.parse(t))},scale:function(t){return Math.round(n.scale.call(this,t))},getTicks:function(){for(var t=[],e=this._extent,i=e[0];i<=e[1];)t.push(i),i++;return t},getLabel:function(t){return this._data[t]},count:function(){return this._extent[1]-this._extent[0]+1},niceTicks:e.noop,niceExtent:e.noop});return r[E]=function(){return new r},r}),e("echarts/chart/helper/SymbolDraw",[ne,"../../util/graphic","./Symbol"],function(t){function e(t){this.group=new n.Group,this._symbolCtor=t||r}function i(t,e,i){var n=t.getItemLayout(e);return!(!n||isNaN(n[0])||isNaN(n[1])||i&&i(e)||"none"===t[R](e,"symbol"))}var n=t("../../util/graphic"),r=t("./Symbol"),a=e[K];return a.updateData=function(t,e){var r=this.group,a=t.hostModel,o=this._data,s=this._symbolCtor,l={itemStyle:a[U]("itemStyle.normal").getItemStyle(["color"]),hoverItemStyle:a[U]("itemStyle.emphasis").getItemStyle(),symbolRotate:a.get("symbolRotate"),symbolOffset:a.get("symbolOffset"),hoverAnimation:a.get("hoverAnimation"),labelModel:a[U]("label.normal"),hoverLabelModel:a[U]("label.emphasis")};t.diff(o).add(function(n){var a=t.getItemLayout(n);if(i(t,n,e)){var o=new s(t,n,l);o.attr(y,a),t.setItemGraphicEl(n,o),r.add(o)}})[O](function(u,c){var h=o.getItemGraphicEl(c),f=t.getItemLayout(u);return i(t,u,e)?(h?(h.updateData(t,u,l),n.updateProps(h,{position:f},a)):(h=new s(t,u),h.attr(y,f)),r.add(h),void t.setItemGraphicEl(u,h)):void r.remove(h)}).remove(function(t){var e=o.getItemGraphicEl(t);e&&e.fadeOut(function(){r.remove(e)})}).execute(),this._data=t},a.updateLayout=function(){var t=this._data;t&&t.eachItemGraphicEl(function(e,i){var n=t.getItemLayout(i);e.attr(y,n)})},a.remove=function(t){var e=this.group,i=this._data;i&&(t?i.eachItemGraphicEl(function(t){t.fadeOut(function(){e.remove(t)})}):e[ee]())},e}),e("echarts/chart/helper/Symbol",[ne,ie,"../../util/symbol","../../util/graphic","../../util/number"],function(t){function e(t){return t=t instanceof Array?t.slice():[+t,+t],t[0]/=2,t[1]/=2,t}function i(t,e,i){o.Group.call(this),this.updateData(t,e,i)}function n(t,e){this.parent.drift(t,e)}var r=t(ie),a=t("../../util/symbol"),o=t("../../util/graphic"),s=t("../../util/number"),u=i[K];u._createSymbol=function(t,i,r){this[ee]();var s=i.hostModel,l=i[R](r,"color"),u=a.createSymbol(t,-1,-1,2,2,l);u.attr({z2:100,culling:!0,scale:[0,0]}),u.drift=n;var c=e(i[R](r,"symbolSize"));o.initProps(u,{scale:c},s,r),this._symbolType=t,this.add(u)},u.stopSymbolAnimation=function(t){this.childAt(0).stopAnimation(t)},u.getSymbolPath=function(){return this.childAt(0)},u.getScale=function(){return this.childAt(0).scale},u.highlight=function(){this.childAt(0).trigger("emphasis")},u.downplay=function(){this.childAt(0).trigger("normal")},u.setZ=function(t,e){var i=this.childAt(0);i[L]=t,i.z=e},u.setDraggable=function(t){var e=this.childAt(0);e.draggable=t,e.cursor=t?"move":"pointer"},u.updateData=function(t,i,n){this.silent=!1;var r=t[R](i,"symbol")||"circle",a=t.hostModel,s=e(t[R](i,"symbolSize"));if(r!==this._symbolType)this._createSymbol(r,t,i);else{var l=this.childAt(0);o.updateProps(l,{scale:s},a,i)}this._updateCommon(t,i,s,n),this._seriesModel=a};var c=["itemStyle","normal"],h=["itemStyle","emphasis"],f=["label","normal"],d=["label","emphasis"];return u._updateCommon=function(t,i,n,a){var u=this.childAt(0),x=t.hostModel,b=t[R](i,"color");"image"!==u.type&&u.useStyle({strokeNoScale:!0}),a=a||null;var w=a&&a.itemStyle,M=a&&a.hoverItemStyle,T=a&&a.symbolRotate,S=a&&a.symbolOffset,C=a&&a.labelModel,P=a&&a.hoverLabelModel,A=a&&a.hoverAnimation;if(!a||t.hasItemOption){var L=t[m](i);w=L[U](c).getItemStyle(["color"]),M=L[U](h).getItemStyle(),T=L[v]("symbolRotate"),S=L[v]("symbolOffset"),C=L[U](f),P=L[U](d),A=L[v]("hoverAnimation")}else M=r[I]({},M);var z=u.style;u.attr("rotation",(T||0)*Math.PI/180||0),S&&u.attr(y,[s[p](S[0],n[0]),s[p](S[1],n[1])]),u.setColor(b),u.setStyle(w);var k=t[R](i,"opacity");null!=k&&(z.opacity=k);for(var D,O,E=t[_].slice();E[G]&&(D=E.pop(),O=t.getDimensionInfo(D).type,O===g||"time"===O););null!=D&&C[v]("show")?(o.setText(z,C,b),z.text=r[l](x.getFormattedLabel(i,"normal"),t.get(D,i))):z.text="",null!=D&&P[v]("show")?(o.setText(M,P,b),M.text=r[l](x.getFormattedLabel(i,"emphasis"),t.get(D,i))):M.text="";var B=e(t[R](i,"symbolSize"));if(u.off("mouseover").off("mouseout").off("emphasis").off("normal"),u.hoverStyle=M,o.setHoverStyle(u),A&&x.ifEnableAnimation()){var N=function(){var t=B[1]/B[0];this.animateTo({scale:[Math.max(1.1*B[0],B[0]+3),Math.max(1.1*B[1],B[1]+3*t)]},400,"elasticOut")},F=function(){this.animateTo({scale:B},400,"elasticOut")};u.on("mouseover",N).on("mouseout",F).on("emphasis",N).on("normal",F)}},u.fadeOut=function(t){var e=this.childAt(0);this.silent=!0,e.style.text="",o.updateProps(e,{scale:[0,0]},this._seriesModel,this[B],t)},r[S](i,o.Group),i}),e("echarts/chart/line/lineAnimationDiff",[ne],function(){function t(t){return t>=0?1:-1}function e(e,i,n){for(var r,a=e.getBaseAxis(),o=e.getOtherAxis(a),s=a.onZero?0:o.scale[M]()[0],l=o.dim,u="x"===l||"radius"===l?1:0,c=i.stackedOn,h=i.get(l,n);c&&t(c.get(l,n))===t(h);){r=c;break}var f=[];return f[u]=i.get(a.dim,n),f[1-u]=r?r.get(l,n,!0):s,e.dataToPoint(f)}function i(t,e){var i=[];return e.diff(t).add(function(t){i.push({cmd:"+",idx:t})})[O](function(t,e){i.push({cmd:"=",idx:e,idx1:t})}).remove(function(t){i.push({cmd:"-",idx:t})}).execute(),i}return function(t,n,r,a,o,s){for(var l=i(t,n),u=[],c=[],h=[],f=[],d=[],p=[],v=[],m=s[_],g=0;g<l[G];g++){var y=l[g],x=!0;switch(y.cmd){case"=":var b=t.getItemLayout(y.idx),w=n.getItemLayout(y.idx1);(isNaN(b[0])||isNaN(b[1]))&&(b=w.slice()),u.push(b),c.push(w),h.push(r[y.idx]),f.push(a[y.idx1]),v.push(n.getRawIndex(y.idx1));break;case"+":var M=y.idx;u.push(o.dataToPoint([n.get(m[0],M,!0),n.get(m[1],M,!0)])),c.push(n.getItemLayout(M).slice()),h.push(e(o,n,M)),f.push(a[M]),v.push(n.getRawIndex(M));break;case"-":var M=y.idx,T=t.getRawIndex(M);T!==M?(u.push(t.getItemLayout(M)),c.push(s.dataToPoint([t.get(m[0],M,!0),t.get(m[1],M,!0)])),h.push(r[M]),f.push(e(s,t,M)),v.push(T)):x=!1}x&&(d.push(y),p.push(p[G]))}p.sort(function(t,e){return v[t]-v[e]});for(var S=[],C=[],P=[],A=[],L=[],g=0;g<p[G];g++){var M=p[g];S[g]=u[M],C[g]=c[M],P[g]=h[M],A[g]=f[M],L[g]=d[M]}return{current:S,next:C,stackedOnCurrent:P,stackedOnNext:A,status:L}}}),e("echarts/chart/line/poly",[ne,"zrender/graphic/Path","zrender/core/vector"],function(t){function e(t){return isNaN(t[0])||isNaN(t[1])}function n(t,n,r,o,v,m,g,y,_,x,b){for(var w=0,M=r,T=0;o>T;T++){var S=n[M];if(M>=v||0>M)break;if(e(S)){if(b){M+=m;continue}break}if(M===r)t[m>0?a:i](S[0],S[1]),h(d,S);else if(_>0){var C=M+m,P=n[C];if(b)for(;P&&e(n[C]);)C+=m,P=n[C];var A=.5,L=n[w],P=n[C];if(!P||e(P))h(p,S);else{e(P)&&!b&&(P=S),s.sub(f,P,L);var z,k;if("x"===x||"y"===x){var I="x"===x?0:1;z=Math.abs(S[I]-L[I]),k=Math.abs(S[I]-P[I])}else z=s.dist(S,L),k=s.dist(S,P);A=k/(k+z),c(p,S,f,-_*(1-A))}l(d,d,y),u(d,d,g),l(p,p,y),u(p,p,g),t.bezierCurveTo(d[0],d[1],p[0],p[1],S[0],S[1]),c(d,S,f,_*A)}else t[i](S[0],S[1]);w=M,M+=m}return T}function r(t,e){var i=[1/0,1/0],n=[-1/0,-1/0];if(e)for(var r=0;r<t[G];r++){var a=t[r];a[0]<i[0]&&(i[0]=a[0]),a[1]<i[1]&&(i[1]=a[1]),a[0]>n[0]&&(n[0]=a[0]),a[1]>n[1]&&(n[1]=a[1])}return{min:e?i:n,max:e?n:i}}var o=t("zrender/graphic/Path"),s=t("zrender/core/vector"),l=s.min,u=s.max,c=s.scaleAndAdd,h=s.copy,f=[],d=[],p=[];return{Polyline:o[I]({type:"ec-polyline",shape:{points:[],smooth:0,smoothConstraint:!0,smoothMonotone:null,connectNulls:!1},style:{fill:null,stroke:"#000"},buildPath:function(t,i){var a=i.points,o=0,s=a[G],l=r(a,i.smoothConstraint);if(i.connectNulls){for(;s>0&&e(a[s-1]);s--);for(;s>o&&e(a[o]);o++);}for(;s>o;)o+=n(t,a,o,s,s,1,l.min,l.max,i.smooth,i.smoothMonotone,i.connectNulls)+1}}),Polygon:o[I]({type:"ec-polygon",shape:{points:[],stackedOnPoints:[],smooth:0,stackedOnSmooth:0,smoothConstraint:!0,smoothMonotone:null,connectNulls:!1},buildPath:function(t,i){var a=i.points,o=i.stackedOnPoints,s=0,l=a[G],u=i.smoothMonotone,c=r(a,i.smoothConstraint),h=r(o,i.smoothConstraint);if(i.connectNulls){for(;l>0&&e(a[l-1]);l--);for(;l>s&&e(a[s]);s++);}for(;l>s;){var f=n(t,a,s,l,l,1,c.min,c.max,i.smooth,u,i.connectNulls);n(t,o,s+f-1,f,l,-1,h.min,h.max,i.stackedOnSmooth,u,i.connectNulls),s+=f+1,t.closePath()}}})}}),e("echarts/coord/cartesian/Cartesian",[ne,ie],function(t){function e(t){return this._axes[t]}var i=t(ie),n=function(t){this._axes={},this._dimList=[],this.name=t||""};return n[K]={constructor:n,type:"cartesian",getAxis:function(t){return this._axes[t]},getAxes:function(){return i.map(this._dimList,e,this)},getAxesByScale:function(t){return t=t[J](),i.filter(this.getAxes(),function(e){return e.scale.type===t})},addAxis:function(t){var e=t.dim;this._axes[e]=t,this._dimList.push(e)},dataToCoord:function(t){return this._dataCoordConvert(t,"dataToCoord")},coordToData:function(t){return this._dataCoordConvert(t,"coordToData")},_dataCoordConvert:function(t,e){for(var i=this._dimList,n=t instanceof Array?[]:{},r=0;r<i[G];r++){var a=i[r],o=this._axes[a];n[a]=o[e](t[a])}return n}},n}),e("echarts/coord/cartesian/axisLabelInterval",[ne,ie,"../axisHelper"],function(t){var e=t(ie),i=t("../axisHelper");return function(t){var n=t.model,r=n[U]("axisLabel"),a=r.get("interval");return"category"!==t.type||"auto"!==a?"auto"===a?0:a:i.getAxisLabelInterval(e.map(t.scale.getTicks(),t.dataToCoord,t),n.getFormattedLabels(),r[U](f).getFont(),t.isHorizontal())}}),e("echarts/coord/Axis",[ne,"../util/number",ie],function(t){function e(t,e){var i=t[1]-t[0],n=e,r=i/n/2;t[0]+=r,t[1]-=r}var i=t("../util/number"),n=i.linearMap,r=t(ie),a=[0,1],o=function(t,e,i){this.dim=t,this.scale=e,this._extent=i||[0,0],this.inverse=!1,this.onBand=!1};return o[K]={constructor:o,contain:function(t){var e=this._extent,i=Math.min(e[0],e[1]),n=Math.max(e[0],e[1]);return t>=i&&n>=t},containData:function(t){return this[T](this.dataToCoord(t))},getExtent:function(){var t=this._extent.slice();return t},getPixelPrecision:function(t){return i.getPixelPrecision(t||this.scale[M](),this._extent)},setExtent:function(t,e){var i=this._extent;i[0]=t,i[1]=e},dataToCoord:function(t,i){var r=this._extent,o=this.scale;return t=o.normalize(t),this.onBand&&o.type===g&&(r=r.slice(),e(r,o.count())),n(t,a,r,i)},coordToData:function(t,i){var r=this._extent,o=this.scale;this.onBand&&o.type===g&&(r=r.slice(),e(r,o.count()));var s=n(t,r,a,i);return this.scale.scale(s)},getTicksCoords:function(t){if(this.onBand&&!t){for(var e=this.getBands(),i=[],n=0;n<e[G];n++)i.push(e[n][0]);return e[n-1]&&i.push(e[n-1][1]),i}return r.map(this.scale.getTicks(),this.dataToCoord,this)},getLabelsCoords:function(){return r.map(this.scale.getTicks(),this.dataToCoord,this)},getBands:function(){for(var t=this[M](),e=[],i=this.scale.count(),n=t[0],r=t[1],a=r-n,o=0;i>o;o++)e.push([a*o/i+n,a*(o+1)/i+n]);return e},getBandWidth:function(){var t=this._extent,e=this.scale[M](),i=e[1]-e[0]+(this.onBand?1:0);0===i&&(i=1);var n=Math.abs(t[1]-t[0]);return Math.abs(n)/i}},o}),e("echarts/coord/cartesian/AxisModel",[ne,"../../model/Component",ie,"../axisModelCreator","../axisModelCommonMixin","../axisModelZoomMixin"],function(t){function e(t,e){return e.type||(e.data?"category":"value")}var i=t("../../model/Component"),n=t(ie),r=t("../axisModelCreator"),a=i[I]({type:"cartesian2dAxis",axis:null,init:function(){a.superApply(this,"init",arguments),this.resetRange()},mergeOption:function(){a.superApply(this,"mergeOption",arguments),this.resetRange()},restoreData:function(){a.superApply(this,"restoreData",arguments),this.resetRange()},findGridModel:function(){return this[s].queryComponents({mainType:"grid",index:this.get("gridIndex"),id:this.get("gridId")})[0]}});n.merge(a[K],t("../axisModelCommonMixin")),n.merge(a[K],t("../axisModelZoomMixin"));var o={offset:0};return r("x",a,e,o),r("y",a,e,o),a}),e("echarts/coord/axisModelCommonMixin",[ne,ie,"./axisHelper"],function(t){function e(t){return r[D](t)&&null!=t.value?t.value:t}function i(){return"category"===this.get("type")&&r.map(this.get("data"),e)}function n(){return a.getFormattedLabels(this.axis,this.get("axisLabel.formatter"))}var r=t(ie),a=t("./axisHelper");return{getFormattedLabels:n,getCategories:i}}),e("echarts/coord/axisModelCreator",[ne,"./axisDefault",ie,"../model/Component","../util/layout"],function(t){var e=t("./axisDefault"),i=t(ie),n=t("../model/Component"),r=t("../util/layout"),a=["value","category","time","log"];return function(t,o,s,l){i.each(a,function(n){o[I]({type:t+"Axis."+n,mergeDefaultAndTheme:function(e,a){var o=this.layoutMode,l=o?r.getLayoutParams(e):{},u=a.getTheme();i.merge(e,u.get(n+"Axis")),i.merge(e,this.getDefaultOption()),e.type=s(t,e),o&&r.mergeLayoutParam(e,l,o)},defaultOption:i.mergeAll([{},e[n+"Axis"],l],!0)})}),n.registerSubTypeDefaulter(t+"Axis",i.curry(s,t))}}),e("echarts/coord/axisModelZoomMixin",[ne],function(){return{getMin:function(){var t=this.option,e=null!=t.rangeStart?t.rangeStart:t.min;return e instanceof Date&&(e=+e),e},getMax:function(){var t=this.option,e=null!=t.rangeEnd?t.rangeEnd:t.max;return e instanceof Date&&(e=+e),e},getNeedCrossZero:function(){var t=this.option;return null!=t.rangeStart||null!=t.rangeEnd?!1:!t.scale},setRange:function(t,e){this.option.rangeStart=t,this.option.rangeEnd=e},resetRange:function(){this.option.rangeStart=this.option.rangeEnd=null}}}),e("echarts/coord/axisDefault",[ne,ie],function(t){var e=t(ie),i={show:!0,zlevel:0,z:0,inverse:!1,name:"",nameLocation:"end",nameRotate:null,nameTruncate:{maxWidth:null,ellipsis:"...",placeholder:"."},nameTextStyle:{},nameGap:15,silent:!1,triggerEvent:!1,tooltip:{show:!1},axisLine:{show:!0,onZero:!0,lineStyle:{color:"#333",width:1,type:"solid"}},axisTick:{show:!0,inside:!1,length:5,lineStyle:{width:1}},axisLabel:{show:!0,inside:!1,rotate:0,margin:8,textStyle:{fontSize:12}},splitLine:{show:!0,lineStyle:{color:["#ccc"],width:1,type:"solid"}},splitArea:{show:!1,areaStyle:{color:["rgba(250,250,250,0.3)","rgba(200,200,200,0.3)"]}}},n=e.merge({boundaryGap:!0,splitLine:{show:!1},axisTick:{alignWithLabel:!1,interval:"auto"},axisLabel:{interval:"auto"}},i),r=e.merge({boundaryGap:[0,0],splitNumber:5},i),a=e.defaults({scale:!0,min:"dataMin",max:"dataMax"},r),o=e.defaults({logBase:10},r);return o.scale=!0,{categoryAxis:n,valueAxis:r,timeAxis:a,logAxis:o}}),e("echarts/component/axis/AxisView",[ne,ie,"../../util/graphic","./AxisBuilder","../../echarts"],function(t){function e(t,e){function i(t){var e=n.getAxis(t);return e.toGlobalCoord(e.dataToCoord(0))}var n=t[te],r=e.axis,a={},o=r[y],s=r.onZero?"onZero":o,l=r.dim,u=n.getRect(),c=[u.x,u.x+u.width,u.y,u.y+u[$]],h=e.get("offset")||0,f={x:{top:c[2]-h,bottom:c[3]+h},y:{left:c[0]-h,right:c[1]+h}};f.x.onZero=Math.max(Math.min(i("y"),f.x[V]),f.x.top),f.y.onZero=Math.max(Math.min(i("x"),f.y.right),f.y.left),a[y]=["y"===l?f.y[s]:c[0],"x"===l?f.x[s]:c[3]],a.rotation=Math.PI/2*("x"===l?0:1);var d={top:-1,bottom:1,left:-1,right:1};a.labelDirection=a.tickDirection=a.nameDirection=d[o],r.onZero&&(a.labelOffset=f[l][o]-f[l].onZero),e[U]("axisTick").get("inside")&&(a.tickDirection=-a.tickDirection),e[U]("axisLabel").get("inside")&&(a.labelDirection=-a.labelDirection);var p=e[U]("axisLabel").get("rotate");return a.labelRotation="top"===s?-p:p,a.labelInterval=r.getLabelInterval(),a.z2=1,a}var i=t(ie),n=t("../../util/graphic"),r=t("./AxisBuilder"),a=r.ifIgnoreOnTick,o=r.getInterval,s=["axisLine","axisLabel","axisTick","axisName"],l=["splitArea","splitLine"],u=t("../../echarts").extendComponentView({type:"axis",render:function(t){this.group[ee]();var a=this._axisGroup;if(this._axisGroup=new n.Group,this.group.add(this._axisGroup),t.get("show")){var o=t.findGridModel(),u=e(o,t),c=new r(t,u);i.each(s,c.add,c),this._axisGroup.add(c.getGroup()),i.each(l,function(e){t.get(e+".show")&&this["_"+e](t,o,u.labelInterval)},this),n.groupTransition(a,this._axisGroup,t)}},_splitLine:function(t,e,r){var s=t.axis,l=t[U]("splitLine"),u=l[U]("lineStyle"),c=u.get("color"),h=o(l,r);c=i[P](c)?c:[c];for(var f=e[te].getRect(),d=s.isHorizontal(),p=0,v=s.getTicksCoords(),m=s.scale.getTicks(),g=[],y=[],_=u.getLineStyle(),x=0;x<v[G];x++)if(!a(s,x,h)){var b=s.toGlobalCoord(v[x]);d?(g[0]=b,g[1]=f.y,y[0]=b,y[1]=f.y+f[$]):(g[0]=f.x,g[1]=b,y[0]=f.x+f.width,y[1]=b);var w=p++%c[G];this._axisGroup.add(new n.Line(n.subPixelOptimizeLine({anid:"line_"+m[x],shape:{x1:g[0],y1:g[1],x2:y[0],y2:y[1]},style:i.defaults({stroke:c[w]},_),silent:!0})))}},_splitArea:function(t,e,r){var s=t.axis,l=t[U]("splitArea"),u=l[U]("areaStyle"),c=u.get("color"),h=e[te].getRect(),f=s.getTicksCoords(),d=s.scale.getTicks(),p=s.toGlobalCoord(f[0]),v=s.toGlobalCoord(f[0]),m=0,g=o(l,r),y=u.getAreaStyle();c=i[P](c)?c:[c];for(var _=1;_<f[G];_++)if(!a(s,_,g)){var x,b,w,M,T=s.toGlobalCoord(f[_]);s.isHorizontal()?(x=p,b=h.y,w=T-x,M=h[$]):(x=h.x,b=v,w=h.width,M=T-b);var S=m++%c[G];this._axisGroup.add(new n.Rect({anid:"area_"+d[_],shape:{x:x,y:b,width:w,height:M},style:i.defaults({fill:c[S]},y),silent:!0})),p=x+w,v=b+M}}});u[I]({type:"xAxis"}),u[I]({type:"yAxis"})}),e("echarts/component/axis/AxisBuilder",[ne,ie,"../../util/format","../../util/graphic","../../model/Model","../../util/number","zrender/core/vector"],function(t){function e(t){var e={componentType:t.mainType};return e[t.mainType+"Index"]=t.componentIndex,e}function i(t,e,i){var n,r,a=_(e-t.rotation);return b(a)?(r=i>0?"top":V,n=d):b(a-P)?(r=i>0?V:"top",n=d):(r=x,n=a>0&&P>a?i>0?"right":"left":i>0?"left":"right"),{rotation:a,textAlign:n,verticalAlign:r}}function n(t,e,i,n){var r,a,o=_(i-t.rotation),s=n[0]>n[1],l="start"===e&&!s||"start"!==e&&s;return b(o-P/2)?(a=l?V:"top",r=d):b(o-1.5*P)?(a=l?"top":V,r=d):(a=x,r=1.5*P>o&&o>P/2?l?"left":"right":l?"right":"left"),{rotation:o,textAlign:r,verticalAlign:a}
}function r(t){var e=t.get("tooltip");return t.get("silent")||!(t.get("triggerEvent")||e&&e.show)}var a=t(ie),h=t("../../util/format"),p=t("../../util/graphic"),v=t("../../model/Model"),m=t("../../util/number"),_=m.remRadian,b=m.isRadianAroundZero,w=t("zrender/core/vector"),T=w[u],S=a[l],P=Math.PI,A=function(t,e){this.opt=e,this.axisModel=t,a.defaults(e,{labelOffset:0,nameDirection:1,tickDirection:1,labelDirection:1,silent:!0}),this.group=new p.Group;var i=new p.Group({position:e[y].slice(),rotation:e.rotation});i.updateTransform(),this._transform=i[o],this._dumbGroup=i};A[K]={constructor:A,hasBuilder:function(t){return!!L[t]},add:function(t){L[t].call(this)},getGroup:function(){return this.group}};var L={axisLine:function(){var t=this.opt,e=this.axisModel;if(e.get("axisLine.show")){var i=this.axisModel.axis[M](),n=this._transform,r=[i[0],0],o=[i[1],0];n&&(T(r,r,n),T(o,o,n)),this.group.add(new p.Line(p.subPixelOptimizeLine({anid:"line",shape:{x1:r[0],y1:r[1],x2:o[0],y2:o[1]},style:a[I]({lineCap:"round"},e[U]("axisLine.lineStyle").getLineStyle()),strokeContainThreshold:t.strokeContainThreshold||5,silent:!0,z2:1})))}},axisTick:function(){var t=this.axisModel;if(t.get("axisTick.show"))for(var e=t.axis,i=t[U]("axisTick"),n=this.opt,r=i[U]("lineStyle"),o=i.get(G),s=k(i,n.labelInterval),l=e.getTicksCoords(i.get("alignWithLabel")),u=e.scale.getTicks(),c=[],h=[],f=this._transform,d=0;d<l[G];d++)if(!z(e,d,s)){var v=l[d];c[0]=v,c[1]=0,h[0]=v,h[1]=n.tickDirection*o,f&&(T(c,c,f),T(h,h,f)),this.group.add(new p.Line(p.subPixelOptimizeLine({anid:"tick_"+u[d],shape:{x1:c[0],y1:c[1],x2:h[0],y2:h[1]},style:a.defaults(r.getLineStyle(),{stroke:t.get("axisLine.lineStyle.color")}),z2:2,silent:!0})))}},axisLabel:function(){function t(t,e){var i=t&&t[c]().clone(),n=e&&e[c]().clone();return i&&n?(i[u](t.getLocalTransform()),n[u](e.getLocalTransform()),i.intersect(n)):void 0}var n=this.opt,a=this.axisModel,o=S(n.axisLabelShow,a.get("axisLabel.show"));if(o){var l=a.axis,h=a[U]("axisLabel"),d=h[U](f),m=h.get("margin"),g=l.scale.getTicks(),y=a.getFormattedLabels(),_=S(n.labelRotation,h.get("rotate"))||0;_=_*P/180;for(var x=i(n,_,n.labelDirection),b=a.get("data"),w=[],M=r(a),T=a.get("triggerEvent"),A=0;A<g[G];A++)if(!z(l,A,n.labelInterval)){var L=d;b&&b[A]&&b[A][f]&&(L=new v(b[A][f],d,a[s]));var k=L.getTextColor()||a.get("axisLine.lineStyle.color"),I=l.dataToCoord(g[A]),D=[I,n.labelOffset+n.labelDirection*m],O=l.scale.getLabel(g[A]),E=new p.Text({anid:"label_"+g[A],style:{text:y[A],textAlign:L.get("align",!0)||x.textAlign,textVerticalAlign:L.get("baseline",!0)||x.verticalAlign,textFont:L.getFont(),fill:typeof k===C?k(O):k},position:D,rotation:x.rotation,silent:M,z2:10});T&&(E.eventData=e(a),E.eventData.targetType="axisLabel",E.eventData.value=O),this._dumbGroup.add(E),E.updateTransform(),w.push(E),this.group.add(E),E.decomposeTransform()}if("category"!==l.type){if(a.getMin?a.getMin():a.get("min")){var R=w[0],B=w[1];t(R,B)&&(R[H]=!0)}if(a.getMax?a.getMax():a.get("max")){var N=w[w[G]-1],F=w[w[G]-2];t(F,N)&&(N[H]=!0)}}}},axisName:function(){var t=this.opt,o=this.axisModel,s=S(t.axisName,o.get("name"));if(s){var l,u=o.get("nameLocation"),c=t.nameDirection,f=o[U]("nameTextStyle"),d=o.get("nameGap")||0,v=this.axisModel.axis[M](),m=v[0]>v[1]?-1:1,g=["start"===u?v[0]-m*d:"end"===u?v[1]+m*d:(v[0]+v[1])/2,u===x?t.labelOffset+c*d:0],y=o.get("nameRotate");null!=y&&(y=y*P/180);var _;u===x?l=i(t,null!=y?y:t.rotation,c):(l=n(t,u,y||0,v),_=t.axisNameAvailableWidth,null!=_&&(_=Math.abs(_/Math.sin(l.rotation)),!isFinite(_)&&(_=null)));var b=f.getFont(),w=o.get("nameTruncate",!0)||{},T=w.ellipsis,C=S(w.maxWidth,_),A=null!=T&&null!=C?h.truncateText(s,C,b,T,{minChar:2,placeholder:w.placeholder}):s,L=o.get("tooltip",!0),z=o.mainType,k={componentType:z,name:s,$vars:["name"]};k[z+"Index"]=o.componentIndex;var D=new p.Text({anid:"name",__fullText:s,__truncatedText:A,style:{text:A,textFont:b,fill:f.getTextColor()||o.get("axisLine.lineStyle.color"),textAlign:l.textAlign,textVerticalAlign:l.verticalAlign},position:g,rotation:l.rotation,silent:r(o),z2:1,tooltip:L&&L.show?a[I]({content:s,formatter:function(){return s},formatterParams:k},L):null});o.get("triggerEvent")&&(D.eventData=e(o),D.eventData.targetType="axisName",D.eventData.name=s),this._dumbGroup.add(D),D.updateTransform(),this.group.add(D),D.decomposeTransform()}}},z=A.ifIgnoreOnTick=function(t,e,i){var n,r=t.scale;return r.type===g&&(typeof i===C?(n=r.getTicks()[e],!i(n,r.getLabel(n))):e%(i+1))},k=A.getInterval=function(t,e){var i=t.get("interval");return(null==i||"auto"==i)&&(i=e),i};return A}),e("zrender",["zrender/zrender"],function(t){return t}),e("echarts",["echarts/echarts"],function(t){return t});var re=t("echarts");return re.graphic=t("echarts/util/graphic"),re.number=t("echarts/util/number"),re.format=t("echarts/util/format"),t("echarts/chart/line"),t("echarts/chart/gauge"),t("echarts/component/grid"),t("echarts/component/legend"),t("echarts/component/tooltip"),t("zrender/vml/vml"),re});<?php
exit();
endif;
?><?php
if (!empty($_GET["file"]) && $_GET["file"] == "static/js/jquery.jsontotable.min.js"):
header("Content-type: text/javascript");?>/*! jQuery JSON to Table - v1.1.3 - 2014-10-11
* https://github.com/jongha/jquery-jsontotable
* Copyright (c) 2014 Jong-Ha Ahn; Licensed MIT */
!function(a){a.jsontotable=function(b,c){var d=a.extend({id:null,header:!0,className:null},c);c=a.extend(d,c);var e=b;if("string"==typeof e&&(e=a.parseJSON(e)),c.id&&e.length){var f,g,h=a("<table></table>");c.className&&h.addClass(c.className),a.fn.appendTr=function(b,c){var d,e,f,h,i,j=c?"thead":"tbody",k=c?"th":"td";if(a.isPlainObject(b)&&b._data){g="<tr";for(d in b)"_data"!==d&&(g+=" "+d+'="'+b[d]+'"');g+="></tr>",b=b._data}else g="<tr></tr>";g=a(g);for(e in b)if(f=b[e],"function"!=typeof f){if(h="",a.isPlainObject(f)&&f._data){h="<"+k;for(i in f)"_data"!==i&&(h+=" "+i+'="'+f[i]+'"');f=f._data,h+=">"+f+"</"+k+">"}else h="<"+k+">"+f+"</"+k+">";g.append(h)}if(c)a(this).append(a("<"+j+"></"+j+">").append(g));else{var l=a(this).find("tbody");0===l.length&&(l=a(this).append("<tbody></tbody>")),l.append(g)}return this};var i=function(a){if(null==a||"object"!=typeof a)return a;var b=a.constructor();for(var c in a)a.hasOwnProperty(c)&&(b[c]=i(a[c]));return b},j=!1,k={},l=null;if(c.header){if(k=i(e[0]._data?e[0]._data:e[0]),"[object Object]"===k.toString()){j=!0;for(l in k)k[l]=l}h.appendTr(k,!0)}for(f=c.header?1:0;f<e.length;f++)if(j&&k){var m={};for(l in k)m[l]=e[f]&&null!=e[f][l]?e[f][l]:"";h.appendTr(m,!1)}else h.appendTr(e[f],!1);a(c.id).append(h)}return this}}(jQuery);<?php
exit();
endif;
?><?php
if (!empty($_GET["file"]) && $_GET["file"] == "static/js/jquery.min.js"):
header("Content-type: text/javascript");?>/*! jQuery v1.9.1 | (c) 2005, 2012 jQuery Foundation, Inc. | jquery.org/license
//@ sourceMappingURL=jquery.min.map
*/(function(e,t){var n,r,i=typeof t,o=e.document,a=e.location,s=e.jQuery,u=e.$,l={},c=[],p="1.9.1",f=c.concat,d=c.push,h=c.slice,g=c.indexOf,m=l.toString,y=l.hasOwnProperty,v=p.trim,b=function(e,t){return new b.fn.init(e,t,r)},x=/[+-]?(?:\d*\.|)\d+(?:[eE][+-]?\d+|)/.source,w=/\S+/g,T=/^[\s\uFEFF\xA0]+|[\s\uFEFF\xA0]+$/g,N=/^(?:(<[\w\W]+>)[^>]*|#([\w-]*))$/,C=/^<(\w+)\s*\/?>(?:<\/\1>|)$/,k=/^[\],:{}\s]*$/,E=/(?:^|:|,)(?:\s*\[)+/g,S=/\\(?:["\\\/bfnrt]|u[\da-fA-F]{4})/g,A=/"[^"\\\r\n]*"|true|false|null|-?(?:\d+\.|)\d+(?:[eE][+-]?\d+|)/g,j=/^-ms-/,D=/-([\da-z])/gi,L=function(e,t){return t.toUpperCase()},H=function(e){(o.addEventListener||"load"===e.type||"complete"===o.readyState)&&(q(),b.ready())},q=function(){o.addEventListener?(o.removeEventListener("DOMContentLoaded",H,!1),e.removeEventListener("load",H,!1)):(o.detachEvent("onreadystatechange",H),e.detachEvent("onload",H))};b.fn=b.prototype={jquery:p,constructor:b,init:function(e,n,r){var i,a;if(!e)return this;if("string"==typeof e){if(i="<"===e.charAt(0)&&">"===e.charAt(e.length-1)&&e.length>=3?[null,e,null]:N.exec(e),!i||!i[1]&&n)return!n||n.jquery?(n||r).find(e):this.constructor(n).find(e);if(i[1]){if(n=n instanceof b?n[0]:n,b.merge(this,b.parseHTML(i[1],n&&n.nodeType?n.ownerDocument||n:o,!0)),C.test(i[1])&&b.isPlainObject(n))for(i in n)b.isFunction(this[i])?this[i](n[i]):this.attr(i,n[i]);return this}if(a=o.getElementById(i[2]),a&&a.parentNode){if(a.id!==i[2])return r.find(e);this.length=1,this[0]=a}return this.context=o,this.selector=e,this}return e.nodeType?(this.context=this[0]=e,this.length=1,this):b.isFunction(e)?r.ready(e):(e.selector!==t&&(this.selector=e.selector,this.context=e.context),b.makeArray(e,this))},selector:"",length:0,size:function(){return this.length},toArray:function(){return h.call(this)},get:function(e){return null==e?this.toArray():0>e?this[this.length+e]:this[e]},pushStack:function(e){var t=b.merge(this.constructor(),e);return t.prevObject=this,t.context=this.context,t},each:function(e,t){return b.each(this,e,t)},ready:function(e){return b.ready.promise().done(e),this},slice:function(){return this.pushStack(h.apply(this,arguments))},first:function(){return this.eq(0)},last:function(){return this.eq(-1)},eq:function(e){var t=this.length,n=+e+(0>e?t:0);return this.pushStack(n>=0&&t>n?[this[n]]:[])},map:function(e){return this.pushStack(b.map(this,function(t,n){return e.call(t,n,t)}))},end:function(){return this.prevObject||this.constructor(null)},push:d,sort:[].sort,splice:[].splice},b.fn.init.prototype=b.fn,b.extend=b.fn.extend=function(){var e,n,r,i,o,a,s=arguments[0]||{},u=1,l=arguments.length,c=!1;for("boolean"==typeof s&&(c=s,s=arguments[1]||{},u=2),"object"==typeof s||b.isFunction(s)||(s={}),l===u&&(s=this,--u);l>u;u++)if(null!=(o=arguments[u]))for(i in o)e=s[i],r=o[i],s!==r&&(c&&r&&(b.isPlainObject(r)||(n=b.isArray(r)))?(n?(n=!1,a=e&&b.isArray(e)?e:[]):a=e&&b.isPlainObject(e)?e:{},s[i]=b.extend(c,a,r)):r!==t&&(s[i]=r));return s},b.extend({noConflict:function(t){return e.$===b&&(e.$=u),t&&e.jQuery===b&&(e.jQuery=s),b},isReady:!1,readyWait:1,holdReady:function(e){e?b.readyWait++:b.ready(!0)},ready:function(e){if(e===!0?!--b.readyWait:!b.isReady){if(!o.body)return setTimeout(b.ready);b.isReady=!0,e!==!0&&--b.readyWait>0||(n.resolveWith(o,[b]),b.fn.trigger&&b(o).trigger("ready").off("ready"))}},isFunction:function(e){return"function"===b.type(e)},isArray:Array.isArray||function(e){return"array"===b.type(e)},isWindow:function(e){return null!=e&&e==e.window},isNumeric:function(e){return!isNaN(parseFloat(e))&&isFinite(e)},type:function(e){return null==e?e+"":"object"==typeof e||"function"==typeof e?l[m.call(e)]||"object":typeof e},isPlainObject:function(e){if(!e||"object"!==b.type(e)||e.nodeType||b.isWindow(e))return!1;try{if(e.constructor&&!y.call(e,"constructor")&&!y.call(e.constructor.prototype,"isPrototypeOf"))return!1}catch(n){return!1}var r;for(r in e);return r===t||y.call(e,r)},isEmptyObject:function(e){var t;for(t in e)return!1;return!0},error:function(e){throw Error(e)},parseHTML:function(e,t,n){if(!e||"string"!=typeof e)return null;"boolean"==typeof t&&(n=t,t=!1),t=t||o;var r=C.exec(e),i=!n&&[];return r?[t.createElement(r[1])]:(r=b.buildFragment([e],t,i),i&&b(i).remove(),b.merge([],r.childNodes))},parseJSON:function(n){return e.JSON&&e.JSON.parse?e.JSON.parse(n):null===n?n:"string"==typeof n&&(n=b.trim(n),n&&k.test(n.replace(S,"@").replace(A,"]").replace(E,"")))?Function("return "+n)():(b.error("Invalid JSON: "+n),t)},parseXML:function(n){var r,i;if(!n||"string"!=typeof n)return null;try{e.DOMParser?(i=new DOMParser,r=i.parseFromString(n,"text/xml")):(r=new ActiveXObject("Microsoft.XMLDOM"),r.async="false",r.loadXML(n))}catch(o){r=t}return r&&r.documentElement&&!r.getElementsByTagName("parsererror").length||b.error("Invalid XML: "+n),r},noop:function(){},globalEval:function(t){t&&b.trim(t)&&(e.execScript||function(t){e.eval.call(e,t)})(t)},camelCase:function(e){return e.replace(j,"ms-").replace(D,L)},nodeName:function(e,t){return e.nodeName&&e.nodeName.toLowerCase()===t.toLowerCase()},each:function(e,t,n){var r,i=0,o=e.length,a=M(e);if(n){if(a){for(;o>i;i++)if(r=t.apply(e[i],n),r===!1)break}else for(i in e)if(r=t.apply(e[i],n),r===!1)break}else if(a){for(;o>i;i++)if(r=t.call(e[i],i,e[i]),r===!1)break}else for(i in e)if(r=t.call(e[i],i,e[i]),r===!1)break;return e},trim:v&&!v.call("\ufeff\u00a0")?function(e){return null==e?"":v.call(e)}:function(e){return null==e?"":(e+"").replace(T,"")},makeArray:function(e,t){var n=t||[];return null!=e&&(M(Object(e))?b.merge(n,"string"==typeof e?[e]:e):d.call(n,e)),n},inArray:function(e,t,n){var r;if(t){if(g)return g.call(t,e,n);for(r=t.length,n=n?0>n?Math.max(0,r+n):n:0;r>n;n++)if(n in t&&t[n]===e)return n}return-1},merge:function(e,n){var r=n.length,i=e.length,o=0;if("number"==typeof r)for(;r>o;o++)e[i++]=n[o];else while(n[o]!==t)e[i++]=n[o++];return e.length=i,e},grep:function(e,t,n){var r,i=[],o=0,a=e.length;for(n=!!n;a>o;o++)r=!!t(e[o],o),n!==r&&i.push(e[o]);return i},map:function(e,t,n){var r,i=0,o=e.length,a=M(e),s=[];if(a)for(;o>i;i++)r=t(e[i],i,n),null!=r&&(s[s.length]=r);else for(i in e)r=t(e[i],i,n),null!=r&&(s[s.length]=r);return f.apply([],s)},guid:1,proxy:function(e,n){var r,i,o;return"string"==typeof n&&(o=e[n],n=e,e=o),b.isFunction(e)?(r=h.call(arguments,2),i=function(){return e.apply(n||this,r.concat(h.call(arguments)))},i.guid=e.guid=e.guid||b.guid++,i):t},access:function(e,n,r,i,o,a,s){var u=0,l=e.length,c=null==r;if("object"===b.type(r)){o=!0;for(u in r)b.access(e,n,u,r[u],!0,a,s)}else if(i!==t&&(o=!0,b.isFunction(i)||(s=!0),c&&(s?(n.call(e,i),n=null):(c=n,n=function(e,t,n){return c.call(b(e),n)})),n))for(;l>u;u++)n(e[u],r,s?i:i.call(e[u],u,n(e[u],r)));return o?e:c?n.call(e):l?n(e[0],r):a},now:function(){return(new Date).getTime()}}),b.ready.promise=function(t){if(!n)if(n=b.Deferred(),"complete"===o.readyState)setTimeout(b.ready);else if(o.addEventListener)o.addEventListener("DOMContentLoaded",H,!1),e.addEventListener("load",H,!1);else{o.attachEvent("onreadystatechange",H),e.attachEvent("onload",H);var r=!1;try{r=null==e.frameElement&&o.documentElement}catch(i){}r&&r.doScroll&&function a(){if(!b.isReady){try{r.doScroll("left")}catch(e){return setTimeout(a,50)}q(),b.ready()}}()}return n.promise(t)},b.each("Boolean Number String Function Array Date RegExp Object Error".split(" "),function(e,t){l["[object "+t+"]"]=t.toLowerCase()});function M(e){var t=e.length,n=b.type(e);return b.isWindow(e)?!1:1===e.nodeType&&t?!0:"array"===n||"function"!==n&&(0===t||"number"==typeof t&&t>0&&t-1 in e)}r=b(o);var _={};function F(e){var t=_[e]={};return b.each(e.match(w)||[],function(e,n){t[n]=!0}),t}b.Callbacks=function(e){e="string"==typeof e?_[e]||F(e):b.extend({},e);var n,r,i,o,a,s,u=[],l=!e.once&&[],c=function(t){for(r=e.memory&&t,i=!0,a=s||0,s=0,o=u.length,n=!0;u&&o>a;a++)if(u[a].apply(t[0],t[1])===!1&&e.stopOnFalse){r=!1;break}n=!1,u&&(l?l.length&&c(l.shift()):r?u=[]:p.disable())},p={add:function(){if(u){var t=u.length;(function i(t){b.each(t,function(t,n){var r=b.type(n);"function"===r?e.unique&&p.has(n)||u.push(n):n&&n.length&&"string"!==r&&i(n)})})(arguments),n?o=u.length:r&&(s=t,c(r))}return this},remove:function(){return u&&b.each(arguments,function(e,t){var r;while((r=b.inArray(t,u,r))>-1)u.splice(r,1),n&&(o>=r&&o--,a>=r&&a--)}),this},has:function(e){return e?b.inArray(e,u)>-1:!(!u||!u.length)},empty:function(){return u=[],this},disable:function(){return u=l=r=t,this},disabled:function(){return!u},lock:function(){return l=t,r||p.disable(),this},locked:function(){return!l},fireWith:function(e,t){return t=t||[],t=[e,t.slice?t.slice():t],!u||i&&!l||(n?l.push(t):c(t)),this},fire:function(){return p.fireWith(this,arguments),this},fired:function(){return!!i}};return p},b.extend({Deferred:function(e){var t=[["resolve","done",b.Callbacks("once memory"),"resolved"],["reject","fail",b.Callbacks("once memory"),"rejected"],["notify","progress",b.Callbacks("memory")]],n="pending",r={state:function(){return n},always:function(){return i.done(arguments).fail(arguments),this},then:function(){var e=arguments;return b.Deferred(function(n){b.each(t,function(t,o){var a=o[0],s=b.isFunction(e[t])&&e[t];i[o[1]](function(){var e=s&&s.apply(this,arguments);e&&b.isFunction(e.promise)?e.promise().done(n.resolve).fail(n.reject).progress(n.notify):n[a+"With"](this===r?n.promise():this,s?[e]:arguments)})}),e=null}).promise()},promise:function(e){return null!=e?b.extend(e,r):r}},i={};return r.pipe=r.then,b.each(t,function(e,o){var a=o[2],s=o[3];r[o[1]]=a.add,s&&a.add(function(){n=s},t[1^e][2].disable,t[2][2].lock),i[o[0]]=function(){return i[o[0]+"With"](this===i?r:this,arguments),this},i[o[0]+"With"]=a.fireWith}),r.promise(i),e&&e.call(i,i),i},when:function(e){var t=0,n=h.call(arguments),r=n.length,i=1!==r||e&&b.isFunction(e.promise)?r:0,o=1===i?e:b.Deferred(),a=function(e,t,n){return function(r){t[e]=this,n[e]=arguments.length>1?h.call(arguments):r,n===s?o.notifyWith(t,n):--i||o.resolveWith(t,n)}},s,u,l;if(r>1)for(s=Array(r),u=Array(r),l=Array(r);r>t;t++)n[t]&&b.isFunction(n[t].promise)?n[t].promise().done(a(t,l,n)).fail(o.reject).progress(a(t,u,s)):--i;return i||o.resolveWith(l,n),o.promise()}}),b.support=function(){var t,n,r,a,s,u,l,c,p,f,d=o.createElement("div");if(d.setAttribute("className","t"),d.innerHTML="  <link/><table></table><a href='/a'>a</a><input type='checkbox'/>",n=d.getElementsByTagName("*"),r=d.getElementsByTagName("a")[0],!n||!r||!n.length)return{};s=o.createElement("select"),l=s.appendChild(o.createElement("option")),a=d.getElementsByTagName("input")[0],r.style.cssText="top:1px;float:left;opacity:.5",t={getSetAttribute:"t"!==d.className,leadingWhitespace:3===d.firstChild.nodeType,tbody:!d.getElementsByTagName("tbody").length,htmlSerialize:!!d.getElementsByTagName("link").length,style:/top/.test(r.getAttribute("style")),hrefNormalized:"/a"===r.getAttribute("href"),opacity:/^0.5/.test(r.style.opacity),cssFloat:!!r.style.cssFloat,checkOn:!!a.value,optSelected:l.selected,enctype:!!o.createElement("form").enctype,html5Clone:"<:nav></:nav>"!==o.createElement("nav").cloneNode(!0).outerHTML,boxModel:"CSS1Compat"===o.compatMode,deleteExpando:!0,noCloneEvent:!0,inlineBlockNeedsLayout:!1,shrinkWrapBlocks:!1,reliableMarginRight:!0,boxSizingReliable:!0,pixelPosition:!1},a.checked=!0,t.noCloneChecked=a.cloneNode(!0).checked,s.disabled=!0,t.optDisabled=!l.disabled;try{delete d.test}catch(h){t.deleteExpando=!1}a=o.createElement("input"),a.setAttribute("value",""),t.input=""===a.getAttribute("value"),a.value="t",a.setAttribute("type","radio"),t.radioValue="t"===a.value,a.setAttribute("checked","t"),a.setAttribute("name","t"),u=o.createDocumentFragment(),u.appendChild(a),t.appendChecked=a.checked,t.checkClone=u.cloneNode(!0).cloneNode(!0).lastChild.checked,d.attachEvent&&(d.attachEvent("onclick",function(){t.noCloneEvent=!1}),d.cloneNode(!0).click());for(f in{submit:!0,change:!0,focusin:!0})d.setAttribute(c="on"+f,"t"),t[f+"Bubbles"]=c in e||d.attributes[c].expando===!1;return d.style.backgroundClip="content-box",d.cloneNode(!0).style.backgroundClip="",t.clearCloneStyle="content-box"===d.style.backgroundClip,b(function(){var n,r,a,s="padding:0;margin:0;border:0;display:block;box-sizing:content-box;-moz-box-sizing:content-box;-webkit-box-sizing:content-box;",u=o.getElementsByTagName("body")[0];u&&(n=o.createElement("div"),n.style.cssText="border:0;width:0;height:0;position:absolute;top:0;left:-9999px;margin-top:1px",u.appendChild(n).appendChild(d),d.innerHTML="<table><tr><td></td><td>t</td></tr></table>",a=d.getElementsByTagName("td"),a[0].style.cssText="padding:0;margin:0;border:0;display:none",p=0===a[0].offsetHeight,a[0].style.display="",a[1].style.display="none",t.reliableHiddenOffsets=p&&0===a[0].offsetHeight,d.innerHTML="",d.style.cssText="box-sizing:border-box;-moz-box-sizing:border-box;-webkit-box-sizing:border-box;padding:1px;border:1px;display:block;width:4px;margin-top:1%;position:absolute;top:1%;",t.boxSizing=4===d.offsetWidth,t.doesNotIncludeMarginInBodyOffset=1!==u.offsetTop,e.getComputedStyle&&(t.pixelPosition="1%"!==(e.getComputedStyle(d,null)||{}).top,t.boxSizingReliable="4px"===(e.getComputedStyle(d,null)||{width:"4px"}).width,r=d.appendChild(o.createElement("div")),r.style.cssText=d.style.cssText=s,r.style.marginRight=r.style.width="0",d.style.width="1px",t.reliableMarginRight=!parseFloat((e.getComputedStyle(r,null)||{}).marginRight)),typeof d.style.zoom!==i&&(d.innerHTML="",d.style.cssText=s+"width:1px;padding:1px;display:inline;zoom:1",t.inlineBlockNeedsLayout=3===d.offsetWidth,d.style.display="block",d.innerHTML="<div></div>",d.firstChild.style.width="5px",t.shrinkWrapBlocks=3!==d.offsetWidth,t.inlineBlockNeedsLayout&&(u.style.zoom=1)),u.removeChild(n),n=d=a=r=null)}),n=s=u=l=r=a=null,t}();var O=/(?:\{[\s\S]*\}|\[[\s\S]*\])$/,B=/([A-Z])/g;function P(e,n,r,i){if(b.acceptData(e)){var o,a,s=b.expando,u="string"==typeof n,l=e.nodeType,p=l?b.cache:e,f=l?e[s]:e[s]&&s;if(f&&p[f]&&(i||p[f].data)||!u||r!==t)return f||(l?e[s]=f=c.pop()||b.guid++:f=s),p[f]||(p[f]={},l||(p[f].toJSON=b.noop)),("object"==typeof n||"function"==typeof n)&&(i?p[f]=b.extend(p[f],n):p[f].data=b.extend(p[f].data,n)),o=p[f],i||(o.data||(o.data={}),o=o.data),r!==t&&(o[b.camelCase(n)]=r),u?(a=o[n],null==a&&(a=o[b.camelCase(n)])):a=o,a}}function R(e,t,n){if(b.acceptData(e)){var r,i,o,a=e.nodeType,s=a?b.cache:e,u=a?e[b.expando]:b.expando;if(s[u]){if(t&&(o=n?s[u]:s[u].data)){b.isArray(t)?t=t.concat(b.map(t,b.camelCase)):t in o?t=[t]:(t=b.camelCase(t),t=t in o?[t]:t.split(" "));for(r=0,i=t.length;i>r;r++)delete o[t[r]];if(!(n?$:b.isEmptyObject)(o))return}(n||(delete s[u].data,$(s[u])))&&(a?b.cleanData([e],!0):b.support.deleteExpando||s!=s.window?delete s[u]:s[u]=null)}}}b.extend({cache:{},expando:"jQuery"+(p+Math.random()).replace(/\D/g,""),noData:{embed:!0,object:"clsid:D27CDB6E-AE6D-11cf-96B8-444553540000",applet:!0},hasData:function(e){return e=e.nodeType?b.cache[e[b.expando]]:e[b.expando],!!e&&!$(e)},data:function(e,t,n){return P(e,t,n)},removeData:function(e,t){return R(e,t)},_data:function(e,t,n){return P(e,t,n,!0)},_removeData:function(e,t){return R(e,t,!0)},acceptData:function(e){if(e.nodeType&&1!==e.nodeType&&9!==e.nodeType)return!1;var t=e.nodeName&&b.noData[e.nodeName.toLowerCase()];return!t||t!==!0&&e.getAttribute("classid")===t}}),b.fn.extend({data:function(e,n){var r,i,o=this[0],a=0,s=null;if(e===t){if(this.length&&(s=b.data(o),1===o.nodeType&&!b._data(o,"parsedAttrs"))){for(r=o.attributes;r.length>a;a++)i=r[a].name,i.indexOf("data-")||(i=b.camelCase(i.slice(5)),W(o,i,s[i]));b._data(o,"parsedAttrs",!0)}return s}return"object"==typeof e?this.each(function(){b.data(this,e)}):b.access(this,function(n){return n===t?o?W(o,e,b.data(o,e)):null:(this.each(function(){b.data(this,e,n)}),t)},null,n,arguments.length>1,null,!0)},removeData:function(e){return this.each(function(){b.removeData(this,e)})}});function W(e,n,r){if(r===t&&1===e.nodeType){var i="data-"+n.replace(B,"-$1").toLowerCase();if(r=e.getAttribute(i),"string"==typeof r){try{r="true"===r?!0:"false"===r?!1:"null"===r?null:+r+""===r?+r:O.test(r)?b.parseJSON(r):r}catch(o){}b.data(e,n,r)}else r=t}return r}function $(e){var t;for(t in e)if(("data"!==t||!b.isEmptyObject(e[t]))&&"toJSON"!==t)return!1;return!0}b.extend({queue:function(e,n,r){var i;return e?(n=(n||"fx")+"queue",i=b._data(e,n),r&&(!i||b.isArray(r)?i=b._data(e,n,b.makeArray(r)):i.push(r)),i||[]):t},dequeue:function(e,t){t=t||"fx";var n=b.queue(e,t),r=n.length,i=n.shift(),o=b._queueHooks(e,t),a=function(){b.dequeue(e,t)};"inprogress"===i&&(i=n.shift(),r--),o.cur=i,i&&("fx"===t&&n.unshift("inprogress"),delete o.stop,i.call(e,a,o)),!r&&o&&o.empty.fire()},_queueHooks:function(e,t){var n=t+"queueHooks";return b._data(e,n)||b._data(e,n,{empty:b.Callbacks("once memory").add(function(){b._removeData(e,t+"queue"),b._removeData(e,n)})})}}),b.fn.extend({queue:function(e,n){var r=2;return"string"!=typeof e&&(n=e,e="fx",r--),r>arguments.length?b.queue(this[0],e):n===t?this:this.each(function(){var t=b.queue(this,e,n);b._queueHooks(this,e),"fx"===e&&"inprogress"!==t[0]&&b.dequeue(this,e)})},dequeue:function(e){return this.each(function(){b.dequeue(this,e)})},delay:function(e,t){return e=b.fx?b.fx.speeds[e]||e:e,t=t||"fx",this.queue(t,function(t,n){var r=setTimeout(t,e);n.stop=function(){clearTimeout(r)}})},clearQueue:function(e){return this.queue(e||"fx",[])},promise:function(e,n){var r,i=1,o=b.Deferred(),a=this,s=this.length,u=function(){--i||o.resolveWith(a,[a])};"string"!=typeof e&&(n=e,e=t),e=e||"fx";while(s--)r=b._data(a[s],e+"queueHooks"),r&&r.empty&&(i++,r.empty.add(u));return u(),o.promise(n)}});var I,z,X=/[\t\r\n]/g,U=/\r/g,V=/^(?:input|select|textarea|button|object)$/i,Y=/^(?:a|area)$/i,J=/^(?:checked|selected|autofocus|autoplay|async|controls|defer|disabled|hidden|loop|multiple|open|readonly|required|scoped)$/i,G=/^(?:checked|selected)$/i,Q=b.support.getSetAttribute,K=b.support.input;b.fn.extend({attr:function(e,t){return b.access(this,b.attr,e,t,arguments.length>1)},removeAttr:function(e){return this.each(function(){b.removeAttr(this,e)})},prop:function(e,t){return b.access(this,b.prop,e,t,arguments.length>1)},removeProp:function(e){return e=b.propFix[e]||e,this.each(function(){try{this[e]=t,delete this[e]}catch(n){}})},addClass:function(e){var t,n,r,i,o,a=0,s=this.length,u="string"==typeof e&&e;if(b.isFunction(e))return this.each(function(t){b(this).addClass(e.call(this,t,this.className))});if(u)for(t=(e||"").match(w)||[];s>a;a++)if(n=this[a],r=1===n.nodeType&&(n.className?(" "+n.className+" ").replace(X," "):" ")){o=0;while(i=t[o++])0>r.indexOf(" "+i+" ")&&(r+=i+" ");n.className=b.trim(r)}return this},removeClass:function(e){var t,n,r,i,o,a=0,s=this.length,u=0===arguments.length||"string"==typeof e&&e;if(b.isFunction(e))return this.each(function(t){b(this).removeClass(e.call(this,t,this.className))});if(u)for(t=(e||"").match(w)||[];s>a;a++)if(n=this[a],r=1===n.nodeType&&(n.className?(" "+n.className+" ").replace(X," "):"")){o=0;while(i=t[o++])while(r.indexOf(" "+i+" ")>=0)r=r.replace(" "+i+" "," ");n.className=e?b.trim(r):""}return this},toggleClass:function(e,t){var n=typeof e,r="boolean"==typeof t;return b.isFunction(e)?this.each(function(n){b(this).toggleClass(e.call(this,n,this.className,t),t)}):this.each(function(){if("string"===n){var o,a=0,s=b(this),u=t,l=e.match(w)||[];while(o=l[a++])u=r?u:!s.hasClass(o),s[u?"addClass":"removeClass"](o)}else(n===i||"boolean"===n)&&(this.className&&b._data(this,"__className__",this.className),this.className=this.className||e===!1?"":b._data(this,"__className__")||"")})},hasClass:function(e){var t=" "+e+" ",n=0,r=this.length;for(;r>n;n++)if(1===this[n].nodeType&&(" "+this[n].className+" ").replace(X," ").indexOf(t)>=0)return!0;return!1},val:function(e){var n,r,i,o=this[0];{if(arguments.length)return i=b.isFunction(e),this.each(function(n){var o,a=b(this);1===this.nodeType&&(o=i?e.call(this,n,a.val()):e,null==o?o="":"number"==typeof o?o+="":b.isArray(o)&&(o=b.map(o,function(e){return null==e?"":e+""})),r=b.valHooks[this.type]||b.valHooks[this.nodeName.toLowerCase()],r&&"set"in r&&r.set(this,o,"value")!==t||(this.value=o))});if(o)return r=b.valHooks[o.type]||b.valHooks[o.nodeName.toLowerCase()],r&&"get"in r&&(n=r.get(o,"value"))!==t?n:(n=o.value,"string"==typeof n?n.replace(U,""):null==n?"":n)}}}),b.extend({valHooks:{option:{get:function(e){var t=e.attributes.value;return!t||t.specified?e.value:e.text}},select:{get:function(e){var t,n,r=e.options,i=e.selectedIndex,o="select-one"===e.type||0>i,a=o?null:[],s=o?i+1:r.length,u=0>i?s:o?i:0;for(;s>u;u++)if(n=r[u],!(!n.selected&&u!==i||(b.support.optDisabled?n.disabled:null!==n.getAttribute("disabled"))||n.parentNode.disabled&&b.nodeName(n.parentNode,"optgroup"))){if(t=b(n).val(),o)return t;a.push(t)}return a},set:function(e,t){var n=b.makeArray(t);return b(e).find("option").each(function(){this.selected=b.inArray(b(this).val(),n)>=0}),n.length||(e.selectedIndex=-1),n}}},attr:function(e,n,r){var o,a,s,u=e.nodeType;if(e&&3!==u&&8!==u&&2!==u)return typeof e.getAttribute===i?b.prop(e,n,r):(a=1!==u||!b.isXMLDoc(e),a&&(n=n.toLowerCase(),o=b.attrHooks[n]||(J.test(n)?z:I)),r===t?o&&a&&"get"in o&&null!==(s=o.get(e,n))?s:(typeof e.getAttribute!==i&&(s=e.getAttribute(n)),null==s?t:s):null!==r?o&&a&&"set"in o&&(s=o.set(e,r,n))!==t?s:(e.setAttribute(n,r+""),r):(b.removeAttr(e,n),t))},removeAttr:function(e,t){var n,r,i=0,o=t&&t.match(w);if(o&&1===e.nodeType)while(n=o[i++])r=b.propFix[n]||n,J.test(n)?!Q&&G.test(n)?e[b.camelCase("default-"+n)]=e[r]=!1:e[r]=!1:b.attr(e,n,""),e.removeAttribute(Q?n:r)},attrHooks:{type:{set:function(e,t){if(!b.support.radioValue&&"radio"===t&&b.nodeName(e,"input")){var n=e.value;return e.setAttribute("type",t),n&&(e.value=n),t}}}},propFix:{tabindex:"tabIndex",readonly:"readOnly","for":"htmlFor","class":"className",maxlength:"maxLength",cellspacing:"cellSpacing",cellpadding:"cellPadding",rowspan:"rowSpan",colspan:"colSpan",usemap:"useMap",frameborder:"frameBorder",contenteditable:"contentEditable"},prop:function(e,n,r){var i,o,a,s=e.nodeType;if(e&&3!==s&&8!==s&&2!==s)return a=1!==s||!b.isXMLDoc(e),a&&(n=b.propFix[n]||n,o=b.propHooks[n]),r!==t?o&&"set"in o&&(i=o.set(e,r,n))!==t?i:e[n]=r:o&&"get"in o&&null!==(i=o.get(e,n))?i:e[n]},propHooks:{tabIndex:{get:function(e){var n=e.getAttributeNode("tabindex");return n&&n.specified?parseInt(n.value,10):V.test(e.nodeName)||Y.test(e.nodeName)&&e.href?0:t}}}}),z={get:function(e,n){var r=b.prop(e,n),i="boolean"==typeof r&&e.getAttribute(n),o="boolean"==typeof r?K&&Q?null!=i:G.test(n)?e[b.camelCase("default-"+n)]:!!i:e.getAttributeNode(n);return o&&o.value!==!1?n.toLowerCase():t},set:function(e,t,n){return t===!1?b.removeAttr(e,n):K&&Q||!G.test(n)?e.setAttribute(!Q&&b.propFix[n]||n,n):e[b.camelCase("default-"+n)]=e[n]=!0,n}},K&&Q||(b.attrHooks.value={get:function(e,n){var r=e.getAttributeNode(n);return b.nodeName(e,"input")?e.defaultValue:r&&r.specified?r.value:t},set:function(e,n,r){return b.nodeName(e,"input")?(e.defaultValue=n,t):I&&I.set(e,n,r)}}),Q||(I=b.valHooks.button={get:function(e,n){var r=e.getAttributeNode(n);return r&&("id"===n||"name"===n||"coords"===n?""!==r.value:r.specified)?r.value:t},set:function(e,n,r){var i=e.getAttributeNode(r);return i||e.setAttributeNode(i=e.ownerDocument.createAttribute(r)),i.value=n+="","value"===r||n===e.getAttribute(r)?n:t}},b.attrHooks.contenteditable={get:I.get,set:function(e,t,n){I.set(e,""===t?!1:t,n)}},b.each(["width","height"],function(e,n){b.attrHooks[n]=b.extend(b.attrHooks[n],{set:function(e,r){return""===r?(e.setAttribute(n,"auto"),r):t}})})),b.support.hrefNormalized||(b.each(["href","src","width","height"],function(e,n){b.attrHooks[n]=b.extend(b.attrHooks[n],{get:function(e){var r=e.getAttribute(n,2);return null==r?t:r}})}),b.each(["href","src"],function(e,t){b.propHooks[t]={get:function(e){return e.getAttribute(t,4)}}})),b.support.style||(b.attrHooks.style={get:function(e){return e.style.cssText||t},set:function(e,t){return e.style.cssText=t+""}}),b.support.optSelected||(b.propHooks.selected=b.extend(b.propHooks.selected,{get:function(e){var t=e.parentNode;return t&&(t.selectedIndex,t.parentNode&&t.parentNode.selectedIndex),null}})),b.support.enctype||(b.propFix.enctype="encoding"),b.support.checkOn||b.each(["radio","checkbox"],function(){b.valHooks[this]={get:function(e){return null===e.getAttribute("value")?"on":e.value}}}),b.each(["radio","checkbox"],function(){b.valHooks[this]=b.extend(b.valHooks[this],{set:function(e,n){return b.isArray(n)?e.checked=b.inArray(b(e).val(),n)>=0:t}})});var Z=/^(?:input|select|textarea)$/i,et=/^key/,tt=/^(?:mouse|contextmenu)|click/,nt=/^(?:focusinfocus|focusoutblur)$/,rt=/^([^.]*)(?:\.(.+)|)$/;function it(){return!0}function ot(){return!1}b.event={global:{},add:function(e,n,r,o,a){var s,u,l,c,p,f,d,h,g,m,y,v=b._data(e);if(v){r.handler&&(c=r,r=c.handler,a=c.selector),r.guid||(r.guid=b.guid++),(u=v.events)||(u=v.events={}),(f=v.handle)||(f=v.handle=function(e){return typeof b===i||e&&b.event.triggered===e.type?t:b.event.dispatch.apply(f.elem,arguments)},f.elem=e),n=(n||"").match(w)||[""],l=n.length;while(l--)s=rt.exec(n[l])||[],g=y=s[1],m=(s[2]||"").split(".").sort(),p=b.event.special[g]||{},g=(a?p.delegateType:p.bindType)||g,p=b.event.special[g]||{},d=b.extend({type:g,origType:y,data:o,handler:r,guid:r.guid,selector:a,needsContext:a&&b.expr.match.needsContext.test(a),namespace:m.join(".")},c),(h=u[g])||(h=u[g]=[],h.delegateCount=0,p.setup&&p.setup.call(e,o,m,f)!==!1||(e.addEventListener?e.addEventListener(g,f,!1):e.attachEvent&&e.attachEvent("on"+g,f))),p.add&&(p.add.call(e,d),d.handler.guid||(d.handler.guid=r.guid)),a?h.splice(h.delegateCount++,0,d):h.push(d),b.event.global[g]=!0;e=null}},remove:function(e,t,n,r,i){var o,a,s,u,l,c,p,f,d,h,g,m=b.hasData(e)&&b._data(e);if(m&&(c=m.events)){t=(t||"").match(w)||[""],l=t.length;while(l--)if(s=rt.exec(t[l])||[],d=g=s[1],h=(s[2]||"").split(".").sort(),d){p=b.event.special[d]||{},d=(r?p.delegateType:p.bindType)||d,f=c[d]||[],s=s[2]&&RegExp("(^|\\.)"+h.join("\\.(?:.*\\.|)")+"(\\.|$)"),u=o=f.length;while(o--)a=f[o],!i&&g!==a.origType||n&&n.guid!==a.guid||s&&!s.test(a.namespace)||r&&r!==a.selector&&("**"!==r||!a.selector)||(f.splice(o,1),a.selector&&f.delegateCount--,p.remove&&p.remove.call(e,a));u&&!f.length&&(p.teardown&&p.teardown.call(e,h,m.handle)!==!1||b.removeEvent(e,d,m.handle),delete c[d])}else for(d in c)b.event.remove(e,d+t[l],n,r,!0);b.isEmptyObject(c)&&(delete m.handle,b._removeData(e,"events"))}},trigger:function(n,r,i,a){var s,u,l,c,p,f,d,h=[i||o],g=y.call(n,"type")?n.type:n,m=y.call(n,"namespace")?n.namespace.split("."):[];if(l=f=i=i||o,3!==i.nodeType&&8!==i.nodeType&&!nt.test(g+b.event.triggered)&&(g.indexOf(".")>=0&&(m=g.split("."),g=m.shift(),m.sort()),u=0>g.indexOf(":")&&"on"+g,n=n[b.expando]?n:new b.Event(g,"object"==typeof n&&n),n.isTrigger=!0,n.namespace=m.join("."),n.namespace_re=n.namespace?RegExp("(^|\\.)"+m.join("\\.(?:.*\\.|)")+"(\\.|$)"):null,n.result=t,n.target||(n.target=i),r=null==r?[n]:b.makeArray(r,[n]),p=b.event.special[g]||{},a||!p.trigger||p.trigger.apply(i,r)!==!1)){if(!a&&!p.noBubble&&!b.isWindow(i)){for(c=p.delegateType||g,nt.test(c+g)||(l=l.parentNode);l;l=l.parentNode)h.push(l),f=l;f===(i.ownerDocument||o)&&h.push(f.defaultView||f.parentWindow||e)}d=0;while((l=h[d++])&&!n.isPropagationStopped())n.type=d>1?c:p.bindType||g,s=(b._data(l,"events")||{})[n.type]&&b._data(l,"handle"),s&&s.apply(l,r),s=u&&l[u],s&&b.acceptData(l)&&s.apply&&s.apply(l,r)===!1&&n.preventDefault();if(n.type=g,!(a||n.isDefaultPrevented()||p._default&&p._default.apply(i.ownerDocument,r)!==!1||"click"===g&&b.nodeName(i,"a")||!b.acceptData(i)||!u||!i[g]||b.isWindow(i))){f=i[u],f&&(i[u]=null),b.event.triggered=g;try{i[g]()}catch(v){}b.event.triggered=t,f&&(i[u]=f)}return n.result}},dispatch:function(e){e=b.event.fix(e);var n,r,i,o,a,s=[],u=h.call(arguments),l=(b._data(this,"events")||{})[e.type]||[],c=b.event.special[e.type]||{};if(u[0]=e,e.delegateTarget=this,!c.preDispatch||c.preDispatch.call(this,e)!==!1){s=b.event.handlers.call(this,e,l),n=0;while((o=s[n++])&&!e.isPropagationStopped()){e.currentTarget=o.elem,a=0;while((i=o.handlers[a++])&&!e.isImmediatePropagationStopped())(!e.namespace_re||e.namespace_re.test(i.namespace))&&(e.handleObj=i,e.data=i.data,r=((b.event.special[i.origType]||{}).handle||i.handler).apply(o.elem,u),r!==t&&(e.result=r)===!1&&(e.preventDefault(),e.stopPropagation()))}return c.postDispatch&&c.postDispatch.call(this,e),e.result}},handlers:function(e,n){var r,i,o,a,s=[],u=n.delegateCount,l=e.target;if(u&&l.nodeType&&(!e.button||"click"!==e.type))for(;l!=this;l=l.parentNode||this)if(1===l.nodeType&&(l.disabled!==!0||"click"!==e.type)){for(o=[],a=0;u>a;a++)i=n[a],r=i.selector+" ",o[r]===t&&(o[r]=i.needsContext?b(r,this).index(l)>=0:b.find(r,this,null,[l]).length),o[r]&&o.push(i);o.length&&s.push({elem:l,handlers:o})}return n.length>u&&s.push({elem:this,handlers:n.slice(u)}),s},fix:function(e){if(e[b.expando])return e;var t,n,r,i=e.type,a=e,s=this.fixHooks[i];s||(this.fixHooks[i]=s=tt.test(i)?this.mouseHooks:et.test(i)?this.keyHooks:{}),r=s.props?this.props.concat(s.props):this.props,e=new b.Event(a),t=r.length;while(t--)n=r[t],e[n]=a[n];return e.target||(e.target=a.srcElement||o),3===e.target.nodeType&&(e.target=e.target.parentNode),e.metaKey=!!e.metaKey,s.filter?s.filter(e,a):e},props:"altKey bubbles cancelable ctrlKey currentTarget eventPhase metaKey relatedTarget shiftKey target timeStamp view which".split(" "),fixHooks:{},keyHooks:{props:"char charCode key keyCode".split(" "),filter:function(e,t){return null==e.which&&(e.which=null!=t.charCode?t.charCode:t.keyCode),e}},mouseHooks:{props:"button buttons clientX clientY fromElement offsetX offsetY pageX pageY screenX screenY toElement".split(" "),filter:function(e,n){var r,i,a,s=n.button,u=n.fromElement;return null==e.pageX&&null!=n.clientX&&(i=e.target.ownerDocument||o,a=i.documentElement,r=i.body,e.pageX=n.clientX+(a&&a.scrollLeft||r&&r.scrollLeft||0)-(a&&a.clientLeft||r&&r.clientLeft||0),e.pageY=n.clientY+(a&&a.scrollTop||r&&r.scrollTop||0)-(a&&a.clientTop||r&&r.clientTop||0)),!e.relatedTarget&&u&&(e.relatedTarget=u===e.target?n.toElement:u),e.which||s===t||(e.which=1&s?1:2&s?3:4&s?2:0),e}},special:{load:{noBubble:!0},click:{trigger:function(){return b.nodeName(this,"input")&&"checkbox"===this.type&&this.click?(this.click(),!1):t}},focus:{trigger:function(){if(this!==o.activeElement&&this.focus)try{return this.focus(),!1}catch(e){}},delegateType:"focusin"},blur:{trigger:function(){return this===o.activeElement&&this.blur?(this.blur(),!1):t},delegateType:"focusout"},beforeunload:{postDispatch:function(e){e.result!==t&&(e.originalEvent.returnValue=e.result)}}},simulate:function(e,t,n,r){var i=b.extend(new b.Event,n,{type:e,isSimulated:!0,originalEvent:{}});r?b.event.trigger(i,null,t):b.event.dispatch.call(t,i),i.isDefaultPrevented()&&n.preventDefault()}},b.removeEvent=o.removeEventListener?function(e,t,n){e.removeEventListener&&e.removeEventListener(t,n,!1)}:function(e,t,n){var r="on"+t;e.detachEvent&&(typeof e[r]===i&&(e[r]=null),e.detachEvent(r,n))},b.Event=function(e,n){return this instanceof b.Event?(e&&e.type?(this.originalEvent=e,this.type=e.type,this.isDefaultPrevented=e.defaultPrevented||e.returnValue===!1||e.getPreventDefault&&e.getPreventDefault()?it:ot):this.type=e,n&&b.extend(this,n),this.timeStamp=e&&e.timeStamp||b.now(),this[b.expando]=!0,t):new b.Event(e,n)},b.Event.prototype={isDefaultPrevented:ot,isPropagationStopped:ot,isImmediatePropagationStopped:ot,preventDefault:function(){var e=this.originalEvent;this.isDefaultPrevented=it,e&&(e.preventDefault?e.preventDefault():e.returnValue=!1)},stopPropagation:function(){var e=this.originalEvent;this.isPropagationStopped=it,e&&(e.stopPropagation&&e.stopPropagation(),e.cancelBubble=!0)},stopImmediatePropagation:function(){this.isImmediatePropagationStopped=it,this.stopPropagation()}},b.each({mouseenter:"mouseover",mouseleave:"mouseout"},function(e,t){b.event.special[e]={delegateType:t,bindType:t,handle:function(e){var n,r=this,i=e.relatedTarget,o=e.handleObj;
return(!i||i!==r&&!b.contains(r,i))&&(e.type=o.origType,n=o.handler.apply(this,arguments),e.type=t),n}}}),b.support.submitBubbles||(b.event.special.submit={setup:function(){return b.nodeName(this,"form")?!1:(b.event.add(this,"click._submit keypress._submit",function(e){var n=e.target,r=b.nodeName(n,"input")||b.nodeName(n,"button")?n.form:t;r&&!b._data(r,"submitBubbles")&&(b.event.add(r,"submit._submit",function(e){e._submit_bubble=!0}),b._data(r,"submitBubbles",!0))}),t)},postDispatch:function(e){e._submit_bubble&&(delete e._submit_bubble,this.parentNode&&!e.isTrigger&&b.event.simulate("submit",this.parentNode,e,!0))},teardown:function(){return b.nodeName(this,"form")?!1:(b.event.remove(this,"._submit"),t)}}),b.support.changeBubbles||(b.event.special.change={setup:function(){return Z.test(this.nodeName)?(("checkbox"===this.type||"radio"===this.type)&&(b.event.add(this,"propertychange._change",function(e){"checked"===e.originalEvent.propertyName&&(this._just_changed=!0)}),b.event.add(this,"click._change",function(e){this._just_changed&&!e.isTrigger&&(this._just_changed=!1),b.event.simulate("change",this,e,!0)})),!1):(b.event.add(this,"beforeactivate._change",function(e){var t=e.target;Z.test(t.nodeName)&&!b._data(t,"changeBubbles")&&(b.event.add(t,"change._change",function(e){!this.parentNode||e.isSimulated||e.isTrigger||b.event.simulate("change",this.parentNode,e,!0)}),b._data(t,"changeBubbles",!0))}),t)},handle:function(e){var n=e.target;return this!==n||e.isSimulated||e.isTrigger||"radio"!==n.type&&"checkbox"!==n.type?e.handleObj.handler.apply(this,arguments):t},teardown:function(){return b.event.remove(this,"._change"),!Z.test(this.nodeName)}}),b.support.focusinBubbles||b.each({focus:"focusin",blur:"focusout"},function(e,t){var n=0,r=function(e){b.event.simulate(t,e.target,b.event.fix(e),!0)};b.event.special[t]={setup:function(){0===n++&&o.addEventListener(e,r,!0)},teardown:function(){0===--n&&o.removeEventListener(e,r,!0)}}}),b.fn.extend({on:function(e,n,r,i,o){var a,s;if("object"==typeof e){"string"!=typeof n&&(r=r||n,n=t);for(a in e)this.on(a,n,r,e[a],o);return this}if(null==r&&null==i?(i=n,r=n=t):null==i&&("string"==typeof n?(i=r,r=t):(i=r,r=n,n=t)),i===!1)i=ot;else if(!i)return this;return 1===o&&(s=i,i=function(e){return b().off(e),s.apply(this,arguments)},i.guid=s.guid||(s.guid=b.guid++)),this.each(function(){b.event.add(this,e,i,r,n)})},one:function(e,t,n,r){return this.on(e,t,n,r,1)},off:function(e,n,r){var i,o;if(e&&e.preventDefault&&e.handleObj)return i=e.handleObj,b(e.delegateTarget).off(i.namespace?i.origType+"."+i.namespace:i.origType,i.selector,i.handler),this;if("object"==typeof e){for(o in e)this.off(o,n,e[o]);return this}return(n===!1||"function"==typeof n)&&(r=n,n=t),r===!1&&(r=ot),this.each(function(){b.event.remove(this,e,r,n)})},bind:function(e,t,n){return this.on(e,null,t,n)},unbind:function(e,t){return this.off(e,null,t)},delegate:function(e,t,n,r){return this.on(t,e,n,r)},undelegate:function(e,t,n){return 1===arguments.length?this.off(e,"**"):this.off(t,e||"**",n)},trigger:function(e,t){return this.each(function(){b.event.trigger(e,t,this)})},triggerHandler:function(e,n){var r=this[0];return r?b.event.trigger(e,n,r,!0):t}}),function(e,t){var n,r,i,o,a,s,u,l,c,p,f,d,h,g,m,y,v,x="sizzle"+-new Date,w=e.document,T={},N=0,C=0,k=it(),E=it(),S=it(),A=typeof t,j=1<<31,D=[],L=D.pop,H=D.push,q=D.slice,M=D.indexOf||function(e){var t=0,n=this.length;for(;n>t;t++)if(this[t]===e)return t;return-1},_="[\\x20\\t\\r\\n\\f]",F="(?:\\\\.|[\\w-]|[^\\x00-\\xa0])+",O=F.replace("w","w#"),B="([*^$|!~]?=)",P="\\["+_+"*("+F+")"+_+"*(?:"+B+_+"*(?:(['\"])((?:\\\\.|[^\\\\])*?)\\3|("+O+")|)|)"+_+"*\\]",R=":("+F+")(?:\\(((['\"])((?:\\\\.|[^\\\\])*?)\\3|((?:\\\\.|[^\\\\()[\\]]|"+P.replace(3,8)+")*)|.*)\\)|)",W=RegExp("^"+_+"+|((?:^|[^\\\\])(?:\\\\.)*)"+_+"+$","g"),$=RegExp("^"+_+"*,"+_+"*"),I=RegExp("^"+_+"*([\\x20\\t\\r\\n\\f>+~])"+_+"*"),z=RegExp(R),X=RegExp("^"+O+"$"),U={ID:RegExp("^#("+F+")"),CLASS:RegExp("^\\.("+F+")"),NAME:RegExp("^\\[name=['\"]?("+F+")['\"]?\\]"),TAG:RegExp("^("+F.replace("w","w*")+")"),ATTR:RegExp("^"+P),PSEUDO:RegExp("^"+R),CHILD:RegExp("^:(only|first|last|nth|nth-last)-(child|of-type)(?:\\("+_+"*(even|odd|(([+-]|)(\\d*)n|)"+_+"*(?:([+-]|)"+_+"*(\\d+)|))"+_+"*\\)|)","i"),needsContext:RegExp("^"+_+"*[>+~]|:(even|odd|eq|gt|lt|nth|first|last)(?:\\("+_+"*((?:-\\d)?\\d*)"+_+"*\\)|)(?=[^-]|$)","i")},V=/[\x20\t\r\n\f]*[+~]/,Y=/^[^{]+\{\s*\[native code/,J=/^(?:#([\w-]+)|(\w+)|\.([\w-]+))$/,G=/^(?:input|select|textarea|button)$/i,Q=/^h\d$/i,K=/'|\\/g,Z=/\=[\x20\t\r\n\f]*([^'"\]]*)[\x20\t\r\n\f]*\]/g,et=/\\([\da-fA-F]{1,6}[\x20\t\r\n\f]?|.)/g,tt=function(e,t){var n="0x"+t-65536;return n!==n?t:0>n?String.fromCharCode(n+65536):String.fromCharCode(55296|n>>10,56320|1023&n)};try{q.call(w.documentElement.childNodes,0)[0].nodeType}catch(nt){q=function(e){var t,n=[];while(t=this[e++])n.push(t);return n}}function rt(e){return Y.test(e+"")}function it(){var e,t=[];return e=function(n,r){return t.push(n+=" ")>i.cacheLength&&delete e[t.shift()],e[n]=r}}function ot(e){return e[x]=!0,e}function at(e){var t=p.createElement("div");try{return e(t)}catch(n){return!1}finally{t=null}}function st(e,t,n,r){var i,o,a,s,u,l,f,g,m,v;if((t?t.ownerDocument||t:w)!==p&&c(t),t=t||p,n=n||[],!e||"string"!=typeof e)return n;if(1!==(s=t.nodeType)&&9!==s)return[];if(!d&&!r){if(i=J.exec(e))if(a=i[1]){if(9===s){if(o=t.getElementById(a),!o||!o.parentNode)return n;if(o.id===a)return n.push(o),n}else if(t.ownerDocument&&(o=t.ownerDocument.getElementById(a))&&y(t,o)&&o.id===a)return n.push(o),n}else{if(i[2])return H.apply(n,q.call(t.getElementsByTagName(e),0)),n;if((a=i[3])&&T.getByClassName&&t.getElementsByClassName)return H.apply(n,q.call(t.getElementsByClassName(a),0)),n}if(T.qsa&&!h.test(e)){if(f=!0,g=x,m=t,v=9===s&&e,1===s&&"object"!==t.nodeName.toLowerCase()){l=ft(e),(f=t.getAttribute("id"))?g=f.replace(K,"\\$&"):t.setAttribute("id",g),g="[id='"+g+"'] ",u=l.length;while(u--)l[u]=g+dt(l[u]);m=V.test(e)&&t.parentNode||t,v=l.join(",")}if(v)try{return H.apply(n,q.call(m.querySelectorAll(v),0)),n}catch(b){}finally{f||t.removeAttribute("id")}}}return wt(e.replace(W,"$1"),t,n,r)}a=st.isXML=function(e){var t=e&&(e.ownerDocument||e).documentElement;return t?"HTML"!==t.nodeName:!1},c=st.setDocument=function(e){var n=e?e.ownerDocument||e:w;return n!==p&&9===n.nodeType&&n.documentElement?(p=n,f=n.documentElement,d=a(n),T.tagNameNoComments=at(function(e){return e.appendChild(n.createComment("")),!e.getElementsByTagName("*").length}),T.attributes=at(function(e){e.innerHTML="<select></select>";var t=typeof e.lastChild.getAttribute("multiple");return"boolean"!==t&&"string"!==t}),T.getByClassName=at(function(e){return e.innerHTML="<div class='hidden e'></div><div class='hidden'></div>",e.getElementsByClassName&&e.getElementsByClassName("e").length?(e.lastChild.className="e",2===e.getElementsByClassName("e").length):!1}),T.getByName=at(function(e){e.id=x+0,e.innerHTML="<a name='"+x+"'></a><div name='"+x+"'></div>",f.insertBefore(e,f.firstChild);var t=n.getElementsByName&&n.getElementsByName(x).length===2+n.getElementsByName(x+0).length;return T.getIdNotName=!n.getElementById(x),f.removeChild(e),t}),i.attrHandle=at(function(e){return e.innerHTML="<a href='#'></a>",e.firstChild&&typeof e.firstChild.getAttribute!==A&&"#"===e.firstChild.getAttribute("href")})?{}:{href:function(e){return e.getAttribute("href",2)},type:function(e){return e.getAttribute("type")}},T.getIdNotName?(i.find.ID=function(e,t){if(typeof t.getElementById!==A&&!d){var n=t.getElementById(e);return n&&n.parentNode?[n]:[]}},i.filter.ID=function(e){var t=e.replace(et,tt);return function(e){return e.getAttribute("id")===t}}):(i.find.ID=function(e,n){if(typeof n.getElementById!==A&&!d){var r=n.getElementById(e);return r?r.id===e||typeof r.getAttributeNode!==A&&r.getAttributeNode("id").value===e?[r]:t:[]}},i.filter.ID=function(e){var t=e.replace(et,tt);return function(e){var n=typeof e.getAttributeNode!==A&&e.getAttributeNode("id");return n&&n.value===t}}),i.find.TAG=T.tagNameNoComments?function(e,n){return typeof n.getElementsByTagName!==A?n.getElementsByTagName(e):t}:function(e,t){var n,r=[],i=0,o=t.getElementsByTagName(e);if("*"===e){while(n=o[i++])1===n.nodeType&&r.push(n);return r}return o},i.find.NAME=T.getByName&&function(e,n){return typeof n.getElementsByName!==A?n.getElementsByName(name):t},i.find.CLASS=T.getByClassName&&function(e,n){return typeof n.getElementsByClassName===A||d?t:n.getElementsByClassName(e)},g=[],h=[":focus"],(T.qsa=rt(n.querySelectorAll))&&(at(function(e){e.innerHTML="<select><option selected=''></option></select>",e.querySelectorAll("[selected]").length||h.push("\\["+_+"*(?:checked|disabled|ismap|multiple|readonly|selected|value)"),e.querySelectorAll(":checked").length||h.push(":checked")}),at(function(e){e.innerHTML="<input type='hidden' i=''/>",e.querySelectorAll("[i^='']").length&&h.push("[*^$]="+_+"*(?:\"\"|'')"),e.querySelectorAll(":enabled").length||h.push(":enabled",":disabled"),e.querySelectorAll("*,:x"),h.push(",.*:")})),(T.matchesSelector=rt(m=f.matchesSelector||f.mozMatchesSelector||f.webkitMatchesSelector||f.oMatchesSelector||f.msMatchesSelector))&&at(function(e){T.disconnectedMatch=m.call(e,"div"),m.call(e,"[s!='']:x"),g.push("!=",R)}),h=RegExp(h.join("|")),g=RegExp(g.join("|")),y=rt(f.contains)||f.compareDocumentPosition?function(e,t){var n=9===e.nodeType?e.documentElement:e,r=t&&t.parentNode;return e===r||!(!r||1!==r.nodeType||!(n.contains?n.contains(r):e.compareDocumentPosition&&16&e.compareDocumentPosition(r)))}:function(e,t){if(t)while(t=t.parentNode)if(t===e)return!0;return!1},v=f.compareDocumentPosition?function(e,t){var r;return e===t?(u=!0,0):(r=t.compareDocumentPosition&&e.compareDocumentPosition&&e.compareDocumentPosition(t))?1&r||e.parentNode&&11===e.parentNode.nodeType?e===n||y(w,e)?-1:t===n||y(w,t)?1:0:4&r?-1:1:e.compareDocumentPosition?-1:1}:function(e,t){var r,i=0,o=e.parentNode,a=t.parentNode,s=[e],l=[t];if(e===t)return u=!0,0;if(!o||!a)return e===n?-1:t===n?1:o?-1:a?1:0;if(o===a)return ut(e,t);r=e;while(r=r.parentNode)s.unshift(r);r=t;while(r=r.parentNode)l.unshift(r);while(s[i]===l[i])i++;return i?ut(s[i],l[i]):s[i]===w?-1:l[i]===w?1:0},u=!1,[0,0].sort(v),T.detectDuplicates=u,p):p},st.matches=function(e,t){return st(e,null,null,t)},st.matchesSelector=function(e,t){if((e.ownerDocument||e)!==p&&c(e),t=t.replace(Z,"='$1']"),!(!T.matchesSelector||d||g&&g.test(t)||h.test(t)))try{var n=m.call(e,t);if(n||T.disconnectedMatch||e.document&&11!==e.document.nodeType)return n}catch(r){}return st(t,p,null,[e]).length>0},st.contains=function(e,t){return(e.ownerDocument||e)!==p&&c(e),y(e,t)},st.attr=function(e,t){var n;return(e.ownerDocument||e)!==p&&c(e),d||(t=t.toLowerCase()),(n=i.attrHandle[t])?n(e):d||T.attributes?e.getAttribute(t):((n=e.getAttributeNode(t))||e.getAttribute(t))&&e[t]===!0?t:n&&n.specified?n.value:null},st.error=function(e){throw Error("Syntax error, unrecognized expression: "+e)},st.uniqueSort=function(e){var t,n=[],r=1,i=0;if(u=!T.detectDuplicates,e.sort(v),u){for(;t=e[r];r++)t===e[r-1]&&(i=n.push(r));while(i--)e.splice(n[i],1)}return e};function ut(e,t){var n=t&&e,r=n&&(~t.sourceIndex||j)-(~e.sourceIndex||j);if(r)return r;if(n)while(n=n.nextSibling)if(n===t)return-1;return e?1:-1}function lt(e){return function(t){var n=t.nodeName.toLowerCase();return"input"===n&&t.type===e}}function ct(e){return function(t){var n=t.nodeName.toLowerCase();return("input"===n||"button"===n)&&t.type===e}}function pt(e){return ot(function(t){return t=+t,ot(function(n,r){var i,o=e([],n.length,t),a=o.length;while(a--)n[i=o[a]]&&(n[i]=!(r[i]=n[i]))})})}o=st.getText=function(e){var t,n="",r=0,i=e.nodeType;if(i){if(1===i||9===i||11===i){if("string"==typeof e.textContent)return e.textContent;for(e=e.firstChild;e;e=e.nextSibling)n+=o(e)}else if(3===i||4===i)return e.nodeValue}else for(;t=e[r];r++)n+=o(t);return n},i=st.selectors={cacheLength:50,createPseudo:ot,match:U,find:{},relative:{">":{dir:"parentNode",first:!0}," ":{dir:"parentNode"},"+":{dir:"previousSibling",first:!0},"~":{dir:"previousSibling"}},preFilter:{ATTR:function(e){return e[1]=e[1].replace(et,tt),e[3]=(e[4]||e[5]||"").replace(et,tt),"~="===e[2]&&(e[3]=" "+e[3]+" "),e.slice(0,4)},CHILD:function(e){return e[1]=e[1].toLowerCase(),"nth"===e[1].slice(0,3)?(e[3]||st.error(e[0]),e[4]=+(e[4]?e[5]+(e[6]||1):2*("even"===e[3]||"odd"===e[3])),e[5]=+(e[7]+e[8]||"odd"===e[3])):e[3]&&st.error(e[0]),e},PSEUDO:function(e){var t,n=!e[5]&&e[2];return U.CHILD.test(e[0])?null:(e[4]?e[2]=e[4]:n&&z.test(n)&&(t=ft(n,!0))&&(t=n.indexOf(")",n.length-t)-n.length)&&(e[0]=e[0].slice(0,t),e[2]=n.slice(0,t)),e.slice(0,3))}},filter:{TAG:function(e){return"*"===e?function(){return!0}:(e=e.replace(et,tt).toLowerCase(),function(t){return t.nodeName&&t.nodeName.toLowerCase()===e})},CLASS:function(e){var t=k[e+" "];return t||(t=RegExp("(^|"+_+")"+e+"("+_+"|$)"))&&k(e,function(e){return t.test(e.className||typeof e.getAttribute!==A&&e.getAttribute("class")||"")})},ATTR:function(e,t,n){return function(r){var i=st.attr(r,e);return null==i?"!="===t:t?(i+="","="===t?i===n:"!="===t?i!==n:"^="===t?n&&0===i.indexOf(n):"*="===t?n&&i.indexOf(n)>-1:"$="===t?n&&i.slice(-n.length)===n:"~="===t?(" "+i+" ").indexOf(n)>-1:"|="===t?i===n||i.slice(0,n.length+1)===n+"-":!1):!0}},CHILD:function(e,t,n,r,i){var o="nth"!==e.slice(0,3),a="last"!==e.slice(-4),s="of-type"===t;return 1===r&&0===i?function(e){return!!e.parentNode}:function(t,n,u){var l,c,p,f,d,h,g=o!==a?"nextSibling":"previousSibling",m=t.parentNode,y=s&&t.nodeName.toLowerCase(),v=!u&&!s;if(m){if(o){while(g){p=t;while(p=p[g])if(s?p.nodeName.toLowerCase()===y:1===p.nodeType)return!1;h=g="only"===e&&!h&&"nextSibling"}return!0}if(h=[a?m.firstChild:m.lastChild],a&&v){c=m[x]||(m[x]={}),l=c[e]||[],d=l[0]===N&&l[1],f=l[0]===N&&l[2],p=d&&m.childNodes[d];while(p=++d&&p&&p[g]||(f=d=0)||h.pop())if(1===p.nodeType&&++f&&p===t){c[e]=[N,d,f];break}}else if(v&&(l=(t[x]||(t[x]={}))[e])&&l[0]===N)f=l[1];else while(p=++d&&p&&p[g]||(f=d=0)||h.pop())if((s?p.nodeName.toLowerCase()===y:1===p.nodeType)&&++f&&(v&&((p[x]||(p[x]={}))[e]=[N,f]),p===t))break;return f-=i,f===r||0===f%r&&f/r>=0}}},PSEUDO:function(e,t){var n,r=i.pseudos[e]||i.setFilters[e.toLowerCase()]||st.error("unsupported pseudo: "+e);return r[x]?r(t):r.length>1?(n=[e,e,"",t],i.setFilters.hasOwnProperty(e.toLowerCase())?ot(function(e,n){var i,o=r(e,t),a=o.length;while(a--)i=M.call(e,o[a]),e[i]=!(n[i]=o[a])}):function(e){return r(e,0,n)}):r}},pseudos:{not:ot(function(e){var t=[],n=[],r=s(e.replace(W,"$1"));return r[x]?ot(function(e,t,n,i){var o,a=r(e,null,i,[]),s=e.length;while(s--)(o=a[s])&&(e[s]=!(t[s]=o))}):function(e,i,o){return t[0]=e,r(t,null,o,n),!n.pop()}}),has:ot(function(e){return function(t){return st(e,t).length>0}}),contains:ot(function(e){return function(t){return(t.textContent||t.innerText||o(t)).indexOf(e)>-1}}),lang:ot(function(e){return X.test(e||"")||st.error("unsupported lang: "+e),e=e.replace(et,tt).toLowerCase(),function(t){var n;do if(n=d?t.getAttribute("xml:lang")||t.getAttribute("lang"):t.lang)return n=n.toLowerCase(),n===e||0===n.indexOf(e+"-");while((t=t.parentNode)&&1===t.nodeType);return!1}}),target:function(t){var n=e.location&&e.location.hash;return n&&n.slice(1)===t.id},root:function(e){return e===f},focus:function(e){return e===p.activeElement&&(!p.hasFocus||p.hasFocus())&&!!(e.type||e.href||~e.tabIndex)},enabled:function(e){return e.disabled===!1},disabled:function(e){return e.disabled===!0},checked:function(e){var t=e.nodeName.toLowerCase();return"input"===t&&!!e.checked||"option"===t&&!!e.selected},selected:function(e){return e.parentNode&&e.parentNode.selectedIndex,e.selected===!0},empty:function(e){for(e=e.firstChild;e;e=e.nextSibling)if(e.nodeName>"@"||3===e.nodeType||4===e.nodeType)return!1;return!0},parent:function(e){return!i.pseudos.empty(e)},header:function(e){return Q.test(e.nodeName)},input:function(e){return G.test(e.nodeName)},button:function(e){var t=e.nodeName.toLowerCase();return"input"===t&&"button"===e.type||"button"===t},text:function(e){var t;return"input"===e.nodeName.toLowerCase()&&"text"===e.type&&(null==(t=e.getAttribute("type"))||t.toLowerCase()===e.type)},first:pt(function(){return[0]}),last:pt(function(e,t){return[t-1]}),eq:pt(function(e,t,n){return[0>n?n+t:n]}),even:pt(function(e,t){var n=0;for(;t>n;n+=2)e.push(n);return e}),odd:pt(function(e,t){var n=1;for(;t>n;n+=2)e.push(n);return e}),lt:pt(function(e,t,n){var r=0>n?n+t:n;for(;--r>=0;)e.push(r);return e}),gt:pt(function(e,t,n){var r=0>n?n+t:n;for(;t>++r;)e.push(r);return e})}};for(n in{radio:!0,checkbox:!0,file:!0,password:!0,image:!0})i.pseudos[n]=lt(n);for(n in{submit:!0,reset:!0})i.pseudos[n]=ct(n);function ft(e,t){var n,r,o,a,s,u,l,c=E[e+" "];if(c)return t?0:c.slice(0);s=e,u=[],l=i.preFilter;while(s){(!n||(r=$.exec(s)))&&(r&&(s=s.slice(r[0].length)||s),u.push(o=[])),n=!1,(r=I.exec(s))&&(n=r.shift(),o.push({value:n,type:r[0].replace(W," ")}),s=s.slice(n.length));for(a in i.filter)!(r=U[a].exec(s))||l[a]&&!(r=l[a](r))||(n=r.shift(),o.push({value:n,type:a,matches:r}),s=s.slice(n.length));if(!n)break}return t?s.length:s?st.error(e):E(e,u).slice(0)}function dt(e){var t=0,n=e.length,r="";for(;n>t;t++)r+=e[t].value;return r}function ht(e,t,n){var i=t.dir,o=n&&"parentNode"===i,a=C++;return t.first?function(t,n,r){while(t=t[i])if(1===t.nodeType||o)return e(t,n,r)}:function(t,n,s){var u,l,c,p=N+" "+a;if(s){while(t=t[i])if((1===t.nodeType||o)&&e(t,n,s))return!0}else while(t=t[i])if(1===t.nodeType||o)if(c=t[x]||(t[x]={}),(l=c[i])&&l[0]===p){if((u=l[1])===!0||u===r)return u===!0}else if(l=c[i]=[p],l[1]=e(t,n,s)||r,l[1]===!0)return!0}}function gt(e){return e.length>1?function(t,n,r){var i=e.length;while(i--)if(!e[i](t,n,r))return!1;return!0}:e[0]}function mt(e,t,n,r,i){var o,a=[],s=0,u=e.length,l=null!=t;for(;u>s;s++)(o=e[s])&&(!n||n(o,r,i))&&(a.push(o),l&&t.push(s));return a}function yt(e,t,n,r,i,o){return r&&!r[x]&&(r=yt(r)),i&&!i[x]&&(i=yt(i,o)),ot(function(o,a,s,u){var l,c,p,f=[],d=[],h=a.length,g=o||xt(t||"*",s.nodeType?[s]:s,[]),m=!e||!o&&t?g:mt(g,f,e,s,u),y=n?i||(o?e:h||r)?[]:a:m;if(n&&n(m,y,s,u),r){l=mt(y,d),r(l,[],s,u),c=l.length;while(c--)(p=l[c])&&(y[d[c]]=!(m[d[c]]=p))}if(o){if(i||e){if(i){l=[],c=y.length;while(c--)(p=y[c])&&l.push(m[c]=p);i(null,y=[],l,u)}c=y.length;while(c--)(p=y[c])&&(l=i?M.call(o,p):f[c])>-1&&(o[l]=!(a[l]=p))}}else y=mt(y===a?y.splice(h,y.length):y),i?i(null,a,y,u):H.apply(a,y)})}function vt(e){var t,n,r,o=e.length,a=i.relative[e[0].type],s=a||i.relative[" "],u=a?1:0,c=ht(function(e){return e===t},s,!0),p=ht(function(e){return M.call(t,e)>-1},s,!0),f=[function(e,n,r){return!a&&(r||n!==l)||((t=n).nodeType?c(e,n,r):p(e,n,r))}];for(;o>u;u++)if(n=i.relative[e[u].type])f=[ht(gt(f),n)];else{if(n=i.filter[e[u].type].apply(null,e[u].matches),n[x]){for(r=++u;o>r;r++)if(i.relative[e[r].type])break;return yt(u>1&&gt(f),u>1&&dt(e.slice(0,u-1)).replace(W,"$1"),n,r>u&&vt(e.slice(u,r)),o>r&&vt(e=e.slice(r)),o>r&&dt(e))}f.push(n)}return gt(f)}function bt(e,t){var n=0,o=t.length>0,a=e.length>0,s=function(s,u,c,f,d){var h,g,m,y=[],v=0,b="0",x=s&&[],w=null!=d,T=l,C=s||a&&i.find.TAG("*",d&&u.parentNode||u),k=N+=null==T?1:Math.random()||.1;for(w&&(l=u!==p&&u,r=n);null!=(h=C[b]);b++){if(a&&h){g=0;while(m=e[g++])if(m(h,u,c)){f.push(h);break}w&&(N=k,r=++n)}o&&((h=!m&&h)&&v--,s&&x.push(h))}if(v+=b,o&&b!==v){g=0;while(m=t[g++])m(x,y,u,c);if(s){if(v>0)while(b--)x[b]||y[b]||(y[b]=L.call(f));y=mt(y)}H.apply(f,y),w&&!s&&y.length>0&&v+t.length>1&&st.uniqueSort(f)}return w&&(N=k,l=T),x};return o?ot(s):s}s=st.compile=function(e,t){var n,r=[],i=[],o=S[e+" "];if(!o){t||(t=ft(e)),n=t.length;while(n--)o=vt(t[n]),o[x]?r.push(o):i.push(o);o=S(e,bt(i,r))}return o};function xt(e,t,n){var r=0,i=t.length;for(;i>r;r++)st(e,t[r],n);return n}function wt(e,t,n,r){var o,a,u,l,c,p=ft(e);if(!r&&1===p.length){if(a=p[0]=p[0].slice(0),a.length>2&&"ID"===(u=a[0]).type&&9===t.nodeType&&!d&&i.relative[a[1].type]){if(t=i.find.ID(u.matches[0].replace(et,tt),t)[0],!t)return n;e=e.slice(a.shift().value.length)}o=U.needsContext.test(e)?0:a.length;while(o--){if(u=a[o],i.relative[l=u.type])break;if((c=i.find[l])&&(r=c(u.matches[0].replace(et,tt),V.test(a[0].type)&&t.parentNode||t))){if(a.splice(o,1),e=r.length&&dt(a),!e)return H.apply(n,q.call(r,0)),n;break}}}return s(e,p)(r,t,d,n,V.test(e)),n}i.pseudos.nth=i.pseudos.eq;function Tt(){}i.filters=Tt.prototype=i.pseudos,i.setFilters=new Tt,c(),st.attr=b.attr,b.find=st,b.expr=st.selectors,b.expr[":"]=b.expr.pseudos,b.unique=st.uniqueSort,b.text=st.getText,b.isXMLDoc=st.isXML,b.contains=st.contains}(e);var at=/Until$/,st=/^(?:parents|prev(?:Until|All))/,ut=/^.[^:#\[\.,]*$/,lt=b.expr.match.needsContext,ct={children:!0,contents:!0,next:!0,prev:!0};b.fn.extend({find:function(e){var t,n,r,i=this.length;if("string"!=typeof e)return r=this,this.pushStack(b(e).filter(function(){for(t=0;i>t;t++)if(b.contains(r[t],this))return!0}));for(n=[],t=0;i>t;t++)b.find(e,this[t],n);return n=this.pushStack(i>1?b.unique(n):n),n.selector=(this.selector?this.selector+" ":"")+e,n},has:function(e){var t,n=b(e,this),r=n.length;return this.filter(function(){for(t=0;r>t;t++)if(b.contains(this,n[t]))return!0})},not:function(e){return this.pushStack(ft(this,e,!1))},filter:function(e){return this.pushStack(ft(this,e,!0))},is:function(e){return!!e&&("string"==typeof e?lt.test(e)?b(e,this.context).index(this[0])>=0:b.filter(e,this).length>0:this.filter(e).length>0)},closest:function(e,t){var n,r=0,i=this.length,o=[],a=lt.test(e)||"string"!=typeof e?b(e,t||this.context):0;for(;i>r;r++){n=this[r];while(n&&n.ownerDocument&&n!==t&&11!==n.nodeType){if(a?a.index(n)>-1:b.find.matchesSelector(n,e)){o.push(n);break}n=n.parentNode}}return this.pushStack(o.length>1?b.unique(o):o)},index:function(e){return e?"string"==typeof e?b.inArray(this[0],b(e)):b.inArray(e.jquery?e[0]:e,this):this[0]&&this[0].parentNode?this.first().prevAll().length:-1},add:function(e,t){var n="string"==typeof e?b(e,t):b.makeArray(e&&e.nodeType?[e]:e),r=b.merge(this.get(),n);return this.pushStack(b.unique(r))},addBack:function(e){return this.add(null==e?this.prevObject:this.prevObject.filter(e))}}),b.fn.andSelf=b.fn.addBack;function pt(e,t){do e=e[t];while(e&&1!==e.nodeType);return e}b.each({parent:function(e){var t=e.parentNode;return t&&11!==t.nodeType?t:null},parents:function(e){return b.dir(e,"parentNode")},parentsUntil:function(e,t,n){return b.dir(e,"parentNode",n)},next:function(e){return pt(e,"nextSibling")},prev:function(e){return pt(e,"previousSibling")},nextAll:function(e){return b.dir(e,"nextSibling")},prevAll:function(e){return b.dir(e,"previousSibling")},nextUntil:function(e,t,n){return b.dir(e,"nextSibling",n)},prevUntil:function(e,t,n){return b.dir(e,"previousSibling",n)},siblings:function(e){return b.sibling((e.parentNode||{}).firstChild,e)},children:function(e){return b.sibling(e.firstChild)},contents:function(e){return b.nodeName(e,"iframe")?e.contentDocument||e.contentWindow.document:b.merge([],e.childNodes)}},function(e,t){b.fn[e]=function(n,r){var i=b.map(this,t,n);return at.test(e)||(r=n),r&&"string"==typeof r&&(i=b.filter(r,i)),i=this.length>1&&!ct[e]?b.unique(i):i,this.length>1&&st.test(e)&&(i=i.reverse()),this.pushStack(i)}}),b.extend({filter:function(e,t,n){return n&&(e=":not("+e+")"),1===t.length?b.find.matchesSelector(t[0],e)?[t[0]]:[]:b.find.matches(e,t)},dir:function(e,n,r){var i=[],o=e[n];while(o&&9!==o.nodeType&&(r===t||1!==o.nodeType||!b(o).is(r)))1===o.nodeType&&i.push(o),o=o[n];return i},sibling:function(e,t){var n=[];for(;e;e=e.nextSibling)1===e.nodeType&&e!==t&&n.push(e);return n}});function ft(e,t,n){if(t=t||0,b.isFunction(t))return b.grep(e,function(e,r){var i=!!t.call(e,r,e);return i===n});if(t.nodeType)return b.grep(e,function(e){return e===t===n});if("string"==typeof t){var r=b.grep(e,function(e){return 1===e.nodeType});if(ut.test(t))return b.filter(t,r,!n);t=b.filter(t,r)}return b.grep(e,function(e){return b.inArray(e,t)>=0===n})}function dt(e){var t=ht.split("|"),n=e.createDocumentFragment();if(n.createElement)while(t.length)n.createElement(t.pop());return n}var ht="abbr|article|aside|audio|bdi|canvas|data|datalist|details|figcaption|figure|footer|header|hgroup|mark|meter|nav|output|progress|section|summary|time|video",gt=/ jQuery\d+="(?:null|\d+)"/g,mt=RegExp("<(?:"+ht+")[\\s/>]","i"),yt=/^\s+/,vt=/<(?!area|br|col|embed|hr|img|input|link|meta|param)(([\w:]+)[^>]*)\/>/gi,bt=/<([\w:]+)/,xt=/<tbody/i,wt=/<|&#?\w+;/,Tt=/<(?:script|style|link)/i,Nt=/^(?:checkbox|radio)$/i,Ct=/checked\s*(?:[^=]|=\s*.checked.)/i,kt=/^$|\/(?:java|ecma)script/i,Et=/^true\/(.*)/,St=/^\s*<!(?:\[CDATA\[|--)|(?:\]\]|--)>\s*$/g,At={option:[1,"<select multiple='multiple'>","</select>"],legend:[1,"<fieldset>","</fieldset>"],area:[1,"<map>","</map>"],param:[1,"<object>","</object>"],thead:[1,"<table>","</table>"],tr:[2,"<table><tbody>","</tbody></table>"],col:[2,"<table><tbody></tbody><colgroup>","</colgroup></table>"],td:[3,"<table><tbody><tr>","</tr></tbody></table>"],_default:b.support.htmlSerialize?[0,"",""]:[1,"X<div>","</div>"]},jt=dt(o),Dt=jt.appendChild(o.createElement("div"));At.optgroup=At.option,At.tbody=At.tfoot=At.colgroup=At.caption=At.thead,At.th=At.td,b.fn.extend({text:function(e){return b.access(this,function(e){return e===t?b.text(this):this.empty().append((this[0]&&this[0].ownerDocument||o).createTextNode(e))},null,e,arguments.length)},wrapAll:function(e){if(b.isFunction(e))return this.each(function(t){b(this).wrapAll(e.call(this,t))});if(this[0]){var t=b(e,this[0].ownerDocument).eq(0).clone(!0);this[0].parentNode&&t.insertBefore(this[0]),t.map(function(){var e=this;while(e.firstChild&&1===e.firstChild.nodeType)e=e.firstChild;return e}).append(this)}return this},wrapInner:function(e){return b.isFunction(e)?this.each(function(t){b(this).wrapInner(e.call(this,t))}):this.each(function(){var t=b(this),n=t.contents();n.length?n.wrapAll(e):t.append(e)})},wrap:function(e){var t=b.isFunction(e);return this.each(function(n){b(this).wrapAll(t?e.call(this,n):e)})},unwrap:function(){return this.parent().each(function(){b.nodeName(this,"body")||b(this).replaceWith(this.childNodes)}).end()},append:function(){return this.domManip(arguments,!0,function(e){(1===this.nodeType||11===this.nodeType||9===this.nodeType)&&this.appendChild(e)})},prepend:function(){return this.domManip(arguments,!0,function(e){(1===this.nodeType||11===this.nodeType||9===this.nodeType)&&this.insertBefore(e,this.firstChild)})},before:function(){return this.domManip(arguments,!1,function(e){this.parentNode&&this.parentNode.insertBefore(e,this)})},after:function(){return this.domManip(arguments,!1,function(e){this.parentNode&&this.parentNode.insertBefore(e,this.nextSibling)})},remove:function(e,t){var n,r=0;for(;null!=(n=this[r]);r++)(!e||b.filter(e,[n]).length>0)&&(t||1!==n.nodeType||b.cleanData(Ot(n)),n.parentNode&&(t&&b.contains(n.ownerDocument,n)&&Mt(Ot(n,"script")),n.parentNode.removeChild(n)));return this},empty:function(){var e,t=0;for(;null!=(e=this[t]);t++){1===e.nodeType&&b.cleanData(Ot(e,!1));while(e.firstChild)e.removeChild(e.firstChild);e.options&&b.nodeName(e,"select")&&(e.options.length=0)}return this},clone:function(e,t){return e=null==e?!1:e,t=null==t?e:t,this.map(function(){return b.clone(this,e,t)})},html:function(e){return b.access(this,function(e){var n=this[0]||{},r=0,i=this.length;if(e===t)return 1===n.nodeType?n.innerHTML.replace(gt,""):t;if(!("string"!=typeof e||Tt.test(e)||!b.support.htmlSerialize&&mt.test(e)||!b.support.leadingWhitespace&&yt.test(e)||At[(bt.exec(e)||["",""])[1].toLowerCase()])){e=e.replace(vt,"<$1></$2>");try{for(;i>r;r++)n=this[r]||{},1===n.nodeType&&(b.cleanData(Ot(n,!1)),n.innerHTML=e);n=0}catch(o){}}n&&this.empty().append(e)},null,e,arguments.length)},replaceWith:function(e){var t=b.isFunction(e);return t||"string"==typeof e||(e=b(e).not(this).detach()),this.domManip([e],!0,function(e){var t=this.nextSibling,n=this.parentNode;n&&(b(this).remove(),n.insertBefore(e,t))})},detach:function(e){return this.remove(e,!0)},domManip:function(e,n,r){e=f.apply([],e);var i,o,a,s,u,l,c=0,p=this.length,d=this,h=p-1,g=e[0],m=b.isFunction(g);if(m||!(1>=p||"string"!=typeof g||b.support.checkClone)&&Ct.test(g))return this.each(function(i){var o=d.eq(i);m&&(e[0]=g.call(this,i,n?o.html():t)),o.domManip(e,n,r)});if(p&&(l=b.buildFragment(e,this[0].ownerDocument,!1,this),i=l.firstChild,1===l.childNodes.length&&(l=i),i)){for(n=n&&b.nodeName(i,"tr"),s=b.map(Ot(l,"script"),Ht),a=s.length;p>c;c++)o=l,c!==h&&(o=b.clone(o,!0,!0),a&&b.merge(s,Ot(o,"script"))),r.call(n&&b.nodeName(this[c],"table")?Lt(this[c],"tbody"):this[c],o,c);if(a)for(u=s[s.length-1].ownerDocument,b.map(s,qt),c=0;a>c;c++)o=s[c],kt.test(o.type||"")&&!b._data(o,"globalEval")&&b.contains(u,o)&&(o.src?b.ajax({url:o.src,type:"GET",dataType:"script",async:!1,global:!1,"throws":!0}):b.globalEval((o.text||o.textContent||o.innerHTML||"").replace(St,"")));l=i=null}return this}});function Lt(e,t){return e.getElementsByTagName(t)[0]||e.appendChild(e.ownerDocument.createElement(t))}function Ht(e){var t=e.getAttributeNode("type");return e.type=(t&&t.specified)+"/"+e.type,e}function qt(e){var t=Et.exec(e.type);return t?e.type=t[1]:e.removeAttribute("type"),e}function Mt(e,t){var n,r=0;for(;null!=(n=e[r]);r++)b._data(n,"globalEval",!t||b._data(t[r],"globalEval"))}function _t(e,t){if(1===t.nodeType&&b.hasData(e)){var n,r,i,o=b._data(e),a=b._data(t,o),s=o.events;if(s){delete a.handle,a.events={};for(n in s)for(r=0,i=s[n].length;i>r;r++)b.event.add(t,n,s[n][r])}a.data&&(a.data=b.extend({},a.data))}}function Ft(e,t){var n,r,i;if(1===t.nodeType){if(n=t.nodeName.toLowerCase(),!b.support.noCloneEvent&&t[b.expando]){i=b._data(t);for(r in i.events)b.removeEvent(t,r,i.handle);t.removeAttribute(b.expando)}"script"===n&&t.text!==e.text?(Ht(t).text=e.text,qt(t)):"object"===n?(t.parentNode&&(t.outerHTML=e.outerHTML),b.support.html5Clone&&e.innerHTML&&!b.trim(t.innerHTML)&&(t.innerHTML=e.innerHTML)):"input"===n&&Nt.test(e.type)?(t.defaultChecked=t.checked=e.checked,t.value!==e.value&&(t.value=e.value)):"option"===n?t.defaultSelected=t.selected=e.defaultSelected:("input"===n||"textarea"===n)&&(t.defaultValue=e.defaultValue)}}b.each({appendTo:"append",prependTo:"prepend",insertBefore:"before",insertAfter:"after",replaceAll:"replaceWith"},function(e,t){b.fn[e]=function(e){var n,r=0,i=[],o=b(e),a=o.length-1;for(;a>=r;r++)n=r===a?this:this.clone(!0),b(o[r])[t](n),d.apply(i,n.get());return this.pushStack(i)}});function Ot(e,n){var r,o,a=0,s=typeof e.getElementsByTagName!==i?e.getElementsByTagName(n||"*"):typeof e.querySelectorAll!==i?e.querySelectorAll(n||"*"):t;if(!s)for(s=[],r=e.childNodes||e;null!=(o=r[a]);a++)!n||b.nodeName(o,n)?s.push(o):b.merge(s,Ot(o,n));return n===t||n&&b.nodeName(e,n)?b.merge([e],s):s}function Bt(e){Nt.test(e.type)&&(e.defaultChecked=e.checked)}b.extend({clone:function(e,t,n){var r,i,o,a,s,u=b.contains(e.ownerDocument,e);if(b.support.html5Clone||b.isXMLDoc(e)||!mt.test("<"+e.nodeName+">")?o=e.cloneNode(!0):(Dt.innerHTML=e.outerHTML,Dt.removeChild(o=Dt.firstChild)),!(b.support.noCloneEvent&&b.support.noCloneChecked||1!==e.nodeType&&11!==e.nodeType||b.isXMLDoc(e)))for(r=Ot(o),s=Ot(e),a=0;null!=(i=s[a]);++a)r[a]&&Ft(i,r[a]);if(t)if(n)for(s=s||Ot(e),r=r||Ot(o),a=0;null!=(i=s[a]);a++)_t(i,r[a]);else _t(e,o);return r=Ot(o,"script"),r.length>0&&Mt(r,!u&&Ot(e,"script")),r=s=i=null,o},buildFragment:function(e,t,n,r){var i,o,a,s,u,l,c,p=e.length,f=dt(t),d=[],h=0;for(;p>h;h++)if(o=e[h],o||0===o)if("object"===b.type(o))b.merge(d,o.nodeType?[o]:o);else if(wt.test(o)){s=s||f.appendChild(t.createElement("div")),u=(bt.exec(o)||["",""])[1].toLowerCase(),c=At[u]||At._default,s.innerHTML=c[1]+o.replace(vt,"<$1></$2>")+c[2],i=c[0];while(i--)s=s.lastChild;if(!b.support.leadingWhitespace&&yt.test(o)&&d.push(t.createTextNode(yt.exec(o)[0])),!b.support.tbody){o="table"!==u||xt.test(o)?"<table>"!==c[1]||xt.test(o)?0:s:s.firstChild,i=o&&o.childNodes.length;while(i--)b.nodeName(l=o.childNodes[i],"tbody")&&!l.childNodes.length&&o.removeChild(l)
}b.merge(d,s.childNodes),s.textContent="";while(s.firstChild)s.removeChild(s.firstChild);s=f.lastChild}else d.push(t.createTextNode(o));s&&f.removeChild(s),b.support.appendChecked||b.grep(Ot(d,"input"),Bt),h=0;while(o=d[h++])if((!r||-1===b.inArray(o,r))&&(a=b.contains(o.ownerDocument,o),s=Ot(f.appendChild(o),"script"),a&&Mt(s),n)){i=0;while(o=s[i++])kt.test(o.type||"")&&n.push(o)}return s=null,f},cleanData:function(e,t){var n,r,o,a,s=0,u=b.expando,l=b.cache,p=b.support.deleteExpando,f=b.event.special;for(;null!=(n=e[s]);s++)if((t||b.acceptData(n))&&(o=n[u],a=o&&l[o])){if(a.events)for(r in a.events)f[r]?b.event.remove(n,r):b.removeEvent(n,r,a.handle);l[o]&&(delete l[o],p?delete n[u]:typeof n.removeAttribute!==i?n.removeAttribute(u):n[u]=null,c.push(o))}}});var Pt,Rt,Wt,$t=/alpha\([^)]*\)/i,It=/opacity\s*=\s*([^)]*)/,zt=/^(top|right|bottom|left)$/,Xt=/^(none|table(?!-c[ea]).+)/,Ut=/^margin/,Vt=RegExp("^("+x+")(.*)$","i"),Yt=RegExp("^("+x+")(?!px)[a-z%]+$","i"),Jt=RegExp("^([+-])=("+x+")","i"),Gt={BODY:"block"},Qt={position:"absolute",visibility:"hidden",display:"block"},Kt={letterSpacing:0,fontWeight:400},Zt=["Top","Right","Bottom","Left"],en=["Webkit","O","Moz","ms"];function tn(e,t){if(t in e)return t;var n=t.charAt(0).toUpperCase()+t.slice(1),r=t,i=en.length;while(i--)if(t=en[i]+n,t in e)return t;return r}function nn(e,t){return e=t||e,"none"===b.css(e,"display")||!b.contains(e.ownerDocument,e)}function rn(e,t){var n,r,i,o=[],a=0,s=e.length;for(;s>a;a++)r=e[a],r.style&&(o[a]=b._data(r,"olddisplay"),n=r.style.display,t?(o[a]||"none"!==n||(r.style.display=""),""===r.style.display&&nn(r)&&(o[a]=b._data(r,"olddisplay",un(r.nodeName)))):o[a]||(i=nn(r),(n&&"none"!==n||!i)&&b._data(r,"olddisplay",i?n:b.css(r,"display"))));for(a=0;s>a;a++)r=e[a],r.style&&(t&&"none"!==r.style.display&&""!==r.style.display||(r.style.display=t?o[a]||"":"none"));return e}b.fn.extend({css:function(e,n){return b.access(this,function(e,n,r){var i,o,a={},s=0;if(b.isArray(n)){for(o=Rt(e),i=n.length;i>s;s++)a[n[s]]=b.css(e,n[s],!1,o);return a}return r!==t?b.style(e,n,r):b.css(e,n)},e,n,arguments.length>1)},show:function(){return rn(this,!0)},hide:function(){return rn(this)},toggle:function(e){var t="boolean"==typeof e;return this.each(function(){(t?e:nn(this))?b(this).show():b(this).hide()})}}),b.extend({cssHooks:{opacity:{get:function(e,t){if(t){var n=Wt(e,"opacity");return""===n?"1":n}}}},cssNumber:{columnCount:!0,fillOpacity:!0,fontWeight:!0,lineHeight:!0,opacity:!0,orphans:!0,widows:!0,zIndex:!0,zoom:!0},cssProps:{"float":b.support.cssFloat?"cssFloat":"styleFloat"},style:function(e,n,r,i){if(e&&3!==e.nodeType&&8!==e.nodeType&&e.style){var o,a,s,u=b.camelCase(n),l=e.style;if(n=b.cssProps[u]||(b.cssProps[u]=tn(l,u)),s=b.cssHooks[n]||b.cssHooks[u],r===t)return s&&"get"in s&&(o=s.get(e,!1,i))!==t?o:l[n];if(a=typeof r,"string"===a&&(o=Jt.exec(r))&&(r=(o[1]+1)*o[2]+parseFloat(b.css(e,n)),a="number"),!(null==r||"number"===a&&isNaN(r)||("number"!==a||b.cssNumber[u]||(r+="px"),b.support.clearCloneStyle||""!==r||0!==n.indexOf("background")||(l[n]="inherit"),s&&"set"in s&&(r=s.set(e,r,i))===t)))try{l[n]=r}catch(c){}}},css:function(e,n,r,i){var o,a,s,u=b.camelCase(n);return n=b.cssProps[u]||(b.cssProps[u]=tn(e.style,u)),s=b.cssHooks[n]||b.cssHooks[u],s&&"get"in s&&(a=s.get(e,!0,r)),a===t&&(a=Wt(e,n,i)),"normal"===a&&n in Kt&&(a=Kt[n]),""===r||r?(o=parseFloat(a),r===!0||b.isNumeric(o)?o||0:a):a},swap:function(e,t,n,r){var i,o,a={};for(o in t)a[o]=e.style[o],e.style[o]=t[o];i=n.apply(e,r||[]);for(o in t)e.style[o]=a[o];return i}}),e.getComputedStyle?(Rt=function(t){return e.getComputedStyle(t,null)},Wt=function(e,n,r){var i,o,a,s=r||Rt(e),u=s?s.getPropertyValue(n)||s[n]:t,l=e.style;return s&&(""!==u||b.contains(e.ownerDocument,e)||(u=b.style(e,n)),Yt.test(u)&&Ut.test(n)&&(i=l.width,o=l.minWidth,a=l.maxWidth,l.minWidth=l.maxWidth=l.width=u,u=s.width,l.width=i,l.minWidth=o,l.maxWidth=a)),u}):o.documentElement.currentStyle&&(Rt=function(e){return e.currentStyle},Wt=function(e,n,r){var i,o,a,s=r||Rt(e),u=s?s[n]:t,l=e.style;return null==u&&l&&l[n]&&(u=l[n]),Yt.test(u)&&!zt.test(n)&&(i=l.left,o=e.runtimeStyle,a=o&&o.left,a&&(o.left=e.currentStyle.left),l.left="fontSize"===n?"1em":u,u=l.pixelLeft+"px",l.left=i,a&&(o.left=a)),""===u?"auto":u});function on(e,t,n){var r=Vt.exec(t);return r?Math.max(0,r[1]-(n||0))+(r[2]||"px"):t}function an(e,t,n,r,i){var o=n===(r?"border":"content")?4:"width"===t?1:0,a=0;for(;4>o;o+=2)"margin"===n&&(a+=b.css(e,n+Zt[o],!0,i)),r?("content"===n&&(a-=b.css(e,"padding"+Zt[o],!0,i)),"margin"!==n&&(a-=b.css(e,"border"+Zt[o]+"Width",!0,i))):(a+=b.css(e,"padding"+Zt[o],!0,i),"padding"!==n&&(a+=b.css(e,"border"+Zt[o]+"Width",!0,i)));return a}function sn(e,t,n){var r=!0,i="width"===t?e.offsetWidth:e.offsetHeight,o=Rt(e),a=b.support.boxSizing&&"border-box"===b.css(e,"boxSizing",!1,o);if(0>=i||null==i){if(i=Wt(e,t,o),(0>i||null==i)&&(i=e.style[t]),Yt.test(i))return i;r=a&&(b.support.boxSizingReliable||i===e.style[t]),i=parseFloat(i)||0}return i+an(e,t,n||(a?"border":"content"),r,o)+"px"}function un(e){var t=o,n=Gt[e];return n||(n=ln(e,t),"none"!==n&&n||(Pt=(Pt||b("<iframe frameborder='0' width='0' height='0'/>").css("cssText","display:block !important")).appendTo(t.documentElement),t=(Pt[0].contentWindow||Pt[0].contentDocument).document,t.write("<!doctype html><html><body>"),t.close(),n=ln(e,t),Pt.detach()),Gt[e]=n),n}function ln(e,t){var n=b(t.createElement(e)).appendTo(t.body),r=b.css(n[0],"display");return n.remove(),r}b.each(["height","width"],function(e,n){b.cssHooks[n]={get:function(e,r,i){return r?0===e.offsetWidth&&Xt.test(b.css(e,"display"))?b.swap(e,Qt,function(){return sn(e,n,i)}):sn(e,n,i):t},set:function(e,t,r){var i=r&&Rt(e);return on(e,t,r?an(e,n,r,b.support.boxSizing&&"border-box"===b.css(e,"boxSizing",!1,i),i):0)}}}),b.support.opacity||(b.cssHooks.opacity={get:function(e,t){return It.test((t&&e.currentStyle?e.currentStyle.filter:e.style.filter)||"")?.01*parseFloat(RegExp.$1)+"":t?"1":""},set:function(e,t){var n=e.style,r=e.currentStyle,i=b.isNumeric(t)?"alpha(opacity="+100*t+")":"",o=r&&r.filter||n.filter||"";n.zoom=1,(t>=1||""===t)&&""===b.trim(o.replace($t,""))&&n.removeAttribute&&(n.removeAttribute("filter"),""===t||r&&!r.filter)||(n.filter=$t.test(o)?o.replace($t,i):o+" "+i)}}),b(function(){b.support.reliableMarginRight||(b.cssHooks.marginRight={get:function(e,n){return n?b.swap(e,{display:"inline-block"},Wt,[e,"marginRight"]):t}}),!b.support.pixelPosition&&b.fn.position&&b.each(["top","left"],function(e,n){b.cssHooks[n]={get:function(e,r){return r?(r=Wt(e,n),Yt.test(r)?b(e).position()[n]+"px":r):t}}})}),b.expr&&b.expr.filters&&(b.expr.filters.hidden=function(e){return 0>=e.offsetWidth&&0>=e.offsetHeight||!b.support.reliableHiddenOffsets&&"none"===(e.style&&e.style.display||b.css(e,"display"))},b.expr.filters.visible=function(e){return!b.expr.filters.hidden(e)}),b.each({margin:"",padding:"",border:"Width"},function(e,t){b.cssHooks[e+t]={expand:function(n){var r=0,i={},o="string"==typeof n?n.split(" "):[n];for(;4>r;r++)i[e+Zt[r]+t]=o[r]||o[r-2]||o[0];return i}},Ut.test(e)||(b.cssHooks[e+t].set=on)});var cn=/%20/g,pn=/\[\]$/,fn=/\r?\n/g,dn=/^(?:submit|button|image|reset|file)$/i,hn=/^(?:input|select|textarea|keygen)/i;b.fn.extend({serialize:function(){return b.param(this.serializeArray())},serializeArray:function(){return this.map(function(){var e=b.prop(this,"elements");return e?b.makeArray(e):this}).filter(function(){var e=this.type;return this.name&&!b(this).is(":disabled")&&hn.test(this.nodeName)&&!dn.test(e)&&(this.checked||!Nt.test(e))}).map(function(e,t){var n=b(this).val();return null==n?null:b.isArray(n)?b.map(n,function(e){return{name:t.name,value:e.replace(fn,"\r\n")}}):{name:t.name,value:n.replace(fn,"\r\n")}}).get()}}),b.param=function(e,n){var r,i=[],o=function(e,t){t=b.isFunction(t)?t():null==t?"":t,i[i.length]=encodeURIComponent(e)+"="+encodeURIComponent(t)};if(n===t&&(n=b.ajaxSettings&&b.ajaxSettings.traditional),b.isArray(e)||e.jquery&&!b.isPlainObject(e))b.each(e,function(){o(this.name,this.value)});else for(r in e)gn(r,e[r],n,o);return i.join("&").replace(cn,"+")};function gn(e,t,n,r){var i;if(b.isArray(t))b.each(t,function(t,i){n||pn.test(e)?r(e,i):gn(e+"["+("object"==typeof i?t:"")+"]",i,n,r)});else if(n||"object"!==b.type(t))r(e,t);else for(i in t)gn(e+"["+i+"]",t[i],n,r)}b.each("blur focus focusin focusout load resize scroll unload click dblclick mousedown mouseup mousemove mouseover mouseout mouseenter mouseleave change select submit keydown keypress keyup error contextmenu".split(" "),function(e,t){b.fn[t]=function(e,n){return arguments.length>0?this.on(t,null,e,n):this.trigger(t)}}),b.fn.hover=function(e,t){return this.mouseenter(e).mouseleave(t||e)};var mn,yn,vn=b.now(),bn=/\?/,xn=/#.*$/,wn=/([?&])_=[^&]*/,Tn=/^(.*?):[ \t]*([^\r\n]*)\r?$/gm,Nn=/^(?:about|app|app-storage|.+-extension|file|res|widget):$/,Cn=/^(?:GET|HEAD)$/,kn=/^\/\//,En=/^([\w.+-]+:)(?:\/\/([^\/?#:]*)(?::(\d+)|)|)/,Sn=b.fn.load,An={},jn={},Dn="*/".concat("*");try{yn=a.href}catch(Ln){yn=o.createElement("a"),yn.href="",yn=yn.href}mn=En.exec(yn.toLowerCase())||[];function Hn(e){return function(t,n){"string"!=typeof t&&(n=t,t="*");var r,i=0,o=t.toLowerCase().match(w)||[];if(b.isFunction(n))while(r=o[i++])"+"===r[0]?(r=r.slice(1)||"*",(e[r]=e[r]||[]).unshift(n)):(e[r]=e[r]||[]).push(n)}}function qn(e,n,r,i){var o={},a=e===jn;function s(u){var l;return o[u]=!0,b.each(e[u]||[],function(e,u){var c=u(n,r,i);return"string"!=typeof c||a||o[c]?a?!(l=c):t:(n.dataTypes.unshift(c),s(c),!1)}),l}return s(n.dataTypes[0])||!o["*"]&&s("*")}function Mn(e,n){var r,i,o=b.ajaxSettings.flatOptions||{};for(i in n)n[i]!==t&&((o[i]?e:r||(r={}))[i]=n[i]);return r&&b.extend(!0,e,r),e}b.fn.load=function(e,n,r){if("string"!=typeof e&&Sn)return Sn.apply(this,arguments);var i,o,a,s=this,u=e.indexOf(" ");return u>=0&&(i=e.slice(u,e.length),e=e.slice(0,u)),b.isFunction(n)?(r=n,n=t):n&&"object"==typeof n&&(a="POST"),s.length>0&&b.ajax({url:e,type:a,dataType:"html",data:n}).done(function(e){o=arguments,s.html(i?b("<div>").append(b.parseHTML(e)).find(i):e)}).complete(r&&function(e,t){s.each(r,o||[e.responseText,t,e])}),this},b.each(["ajaxStart","ajaxStop","ajaxComplete","ajaxError","ajaxSuccess","ajaxSend"],function(e,t){b.fn[t]=function(e){return this.on(t,e)}}),b.each(["get","post"],function(e,n){b[n]=function(e,r,i,o){return b.isFunction(r)&&(o=o||i,i=r,r=t),b.ajax({url:e,type:n,dataType:o,data:r,success:i})}}),b.extend({active:0,lastModified:{},etag:{},ajaxSettings:{url:yn,type:"GET",isLocal:Nn.test(mn[1]),global:!0,processData:!0,async:!0,contentType:"application/x-www-form-urlencoded; charset=UTF-8",accepts:{"*":Dn,text:"text/plain",html:"text/html",xml:"application/xml, text/xml",json:"application/json, text/javascript"},contents:{xml:/xml/,html:/html/,json:/json/},responseFields:{xml:"responseXML",text:"responseText"},converters:{"* text":e.String,"text html":!0,"text json":b.parseJSON,"text xml":b.parseXML},flatOptions:{url:!0,context:!0}},ajaxSetup:function(e,t){return t?Mn(Mn(e,b.ajaxSettings),t):Mn(b.ajaxSettings,e)},ajaxPrefilter:Hn(An),ajaxTransport:Hn(jn),ajax:function(e,n){"object"==typeof e&&(n=e,e=t),n=n||{};var r,i,o,a,s,u,l,c,p=b.ajaxSetup({},n),f=p.context||p,d=p.context&&(f.nodeType||f.jquery)?b(f):b.event,h=b.Deferred(),g=b.Callbacks("once memory"),m=p.statusCode||{},y={},v={},x=0,T="canceled",N={readyState:0,getResponseHeader:function(e){var t;if(2===x){if(!c){c={};while(t=Tn.exec(a))c[t[1].toLowerCase()]=t[2]}t=c[e.toLowerCase()]}return null==t?null:t},getAllResponseHeaders:function(){return 2===x?a:null},setRequestHeader:function(e,t){var n=e.toLowerCase();return x||(e=v[n]=v[n]||e,y[e]=t),this},overrideMimeType:function(e){return x||(p.mimeType=e),this},statusCode:function(e){var t;if(e)if(2>x)for(t in e)m[t]=[m[t],e[t]];else N.always(e[N.status]);return this},abort:function(e){var t=e||T;return l&&l.abort(t),k(0,t),this}};if(h.promise(N).complete=g.add,N.success=N.done,N.error=N.fail,p.url=((e||p.url||yn)+"").replace(xn,"").replace(kn,mn[1]+"//"),p.type=n.method||n.type||p.method||p.type,p.dataTypes=b.trim(p.dataType||"*").toLowerCase().match(w)||[""],null==p.crossDomain&&(r=En.exec(p.url.toLowerCase()),p.crossDomain=!(!r||r[1]===mn[1]&&r[2]===mn[2]&&(r[3]||("http:"===r[1]?80:443))==(mn[3]||("http:"===mn[1]?80:443)))),p.data&&p.processData&&"string"!=typeof p.data&&(p.data=b.param(p.data,p.traditional)),qn(An,p,n,N),2===x)return N;u=p.global,u&&0===b.active++&&b.event.trigger("ajaxStart"),p.type=p.type.toUpperCase(),p.hasContent=!Cn.test(p.type),o=p.url,p.hasContent||(p.data&&(o=p.url+=(bn.test(o)?"&":"?")+p.data,delete p.data),p.cache===!1&&(p.url=wn.test(o)?o.replace(wn,"$1_="+vn++):o+(bn.test(o)?"&":"?")+"_="+vn++)),p.ifModified&&(b.lastModified[o]&&N.setRequestHeader("If-Modified-Since",b.lastModified[o]),b.etag[o]&&N.setRequestHeader("If-None-Match",b.etag[o])),(p.data&&p.hasContent&&p.contentType!==!1||n.contentType)&&N.setRequestHeader("Content-Type",p.contentType),N.setRequestHeader("Accept",p.dataTypes[0]&&p.accepts[p.dataTypes[0]]?p.accepts[p.dataTypes[0]]+("*"!==p.dataTypes[0]?", "+Dn+"; q=0.01":""):p.accepts["*"]);for(i in p.headers)N.setRequestHeader(i,p.headers[i]);if(p.beforeSend&&(p.beforeSend.call(f,N,p)===!1||2===x))return N.abort();T="abort";for(i in{success:1,error:1,complete:1})N[i](p[i]);if(l=qn(jn,p,n,N)){N.readyState=1,u&&d.trigger("ajaxSend",[N,p]),p.async&&p.timeout>0&&(s=setTimeout(function(){N.abort("timeout")},p.timeout));try{x=1,l.send(y,k)}catch(C){if(!(2>x))throw C;k(-1,C)}}else k(-1,"No Transport");function k(e,n,r,i){var c,y,v,w,T,C=n;2!==x&&(x=2,s&&clearTimeout(s),l=t,a=i||"",N.readyState=e>0?4:0,r&&(w=_n(p,N,r)),e>=200&&300>e||304===e?(p.ifModified&&(T=N.getResponseHeader("Last-Modified"),T&&(b.lastModified[o]=T),T=N.getResponseHeader("etag"),T&&(b.etag[o]=T)),204===e?(c=!0,C="nocontent"):304===e?(c=!0,C="notmodified"):(c=Fn(p,w),C=c.state,y=c.data,v=c.error,c=!v)):(v=C,(e||!C)&&(C="error",0>e&&(e=0))),N.status=e,N.statusText=(n||C)+"",c?h.resolveWith(f,[y,C,N]):h.rejectWith(f,[N,C,v]),N.statusCode(m),m=t,u&&d.trigger(c?"ajaxSuccess":"ajaxError",[N,p,c?y:v]),g.fireWith(f,[N,C]),u&&(d.trigger("ajaxComplete",[N,p]),--b.active||b.event.trigger("ajaxStop")))}return N},getScript:function(e,n){return b.get(e,t,n,"script")},getJSON:function(e,t,n){return b.get(e,t,n,"json")}});function _n(e,n,r){var i,o,a,s,u=e.contents,l=e.dataTypes,c=e.responseFields;for(s in c)s in r&&(n[c[s]]=r[s]);while("*"===l[0])l.shift(),o===t&&(o=e.mimeType||n.getResponseHeader("Content-Type"));if(o)for(s in u)if(u[s]&&u[s].test(o)){l.unshift(s);break}if(l[0]in r)a=l[0];else{for(s in r){if(!l[0]||e.converters[s+" "+l[0]]){a=s;break}i||(i=s)}a=a||i}return a?(a!==l[0]&&l.unshift(a),r[a]):t}function Fn(e,t){var n,r,i,o,a={},s=0,u=e.dataTypes.slice(),l=u[0];if(e.dataFilter&&(t=e.dataFilter(t,e.dataType)),u[1])for(i in e.converters)a[i.toLowerCase()]=e.converters[i];for(;r=u[++s];)if("*"!==r){if("*"!==l&&l!==r){if(i=a[l+" "+r]||a["* "+r],!i)for(n in a)if(o=n.split(" "),o[1]===r&&(i=a[l+" "+o[0]]||a["* "+o[0]])){i===!0?i=a[n]:a[n]!==!0&&(r=o[0],u.splice(s--,0,r));break}if(i!==!0)if(i&&e["throws"])t=i(t);else try{t=i(t)}catch(c){return{state:"parsererror",error:i?c:"No conversion from "+l+" to "+r}}}l=r}return{state:"success",data:t}}b.ajaxSetup({accepts:{script:"text/javascript, application/javascript, application/ecmascript, application/x-ecmascript"},contents:{script:/(?:java|ecma)script/},converters:{"text script":function(e){return b.globalEval(e),e}}}),b.ajaxPrefilter("script",function(e){e.cache===t&&(e.cache=!1),e.crossDomain&&(e.type="GET",e.global=!1)}),b.ajaxTransport("script",function(e){if(e.crossDomain){var n,r=o.head||b("head")[0]||o.documentElement;return{send:function(t,i){n=o.createElement("script"),n.async=!0,e.scriptCharset&&(n.charset=e.scriptCharset),n.src=e.url,n.onload=n.onreadystatechange=function(e,t){(t||!n.readyState||/loaded|complete/.test(n.readyState))&&(n.onload=n.onreadystatechange=null,n.parentNode&&n.parentNode.removeChild(n),n=null,t||i(200,"success"))},r.insertBefore(n,r.firstChild)},abort:function(){n&&n.onload(t,!0)}}}});var On=[],Bn=/(=)\?(?=&|$)|\?\?/;b.ajaxSetup({jsonp:"callback",jsonpCallback:function(){var e=On.pop()||b.expando+"_"+vn++;return this[e]=!0,e}}),b.ajaxPrefilter("json jsonp",function(n,r,i){var o,a,s,u=n.jsonp!==!1&&(Bn.test(n.url)?"url":"string"==typeof n.data&&!(n.contentType||"").indexOf("application/x-www-form-urlencoded")&&Bn.test(n.data)&&"data");return u||"jsonp"===n.dataTypes[0]?(o=n.jsonpCallback=b.isFunction(n.jsonpCallback)?n.jsonpCallback():n.jsonpCallback,u?n[u]=n[u].replace(Bn,"$1"+o):n.jsonp!==!1&&(n.url+=(bn.test(n.url)?"&":"?")+n.jsonp+"="+o),n.converters["script json"]=function(){return s||b.error(o+" was not called"),s[0]},n.dataTypes[0]="json",a=e[o],e[o]=function(){s=arguments},i.always(function(){e[o]=a,n[o]&&(n.jsonpCallback=r.jsonpCallback,On.push(o)),s&&b.isFunction(a)&&a(s[0]),s=a=t}),"script"):t});var Pn,Rn,Wn=0,$n=e.ActiveXObject&&function(){var e;for(e in Pn)Pn[e](t,!0)};function In(){try{return new e.XMLHttpRequest}catch(t){}}function zn(){try{return new e.ActiveXObject("Microsoft.XMLHTTP")}catch(t){}}b.ajaxSettings.xhr=e.ActiveXObject?function(){return!this.isLocal&&In()||zn()}:In,Rn=b.ajaxSettings.xhr(),b.support.cors=!!Rn&&"withCredentials"in Rn,Rn=b.support.ajax=!!Rn,Rn&&b.ajaxTransport(function(n){if(!n.crossDomain||b.support.cors){var r;return{send:function(i,o){var a,s,u=n.xhr();if(n.username?u.open(n.type,n.url,n.async,n.username,n.password):u.open(n.type,n.url,n.async),n.xhrFields)for(s in n.xhrFields)u[s]=n.xhrFields[s];n.mimeType&&u.overrideMimeType&&u.overrideMimeType(n.mimeType),n.crossDomain||i["X-Requested-With"]||(i["X-Requested-With"]="XMLHttpRequest");try{for(s in i)u.setRequestHeader(s,i[s])}catch(l){}u.send(n.hasContent&&n.data||null),r=function(e,i){var s,l,c,p;try{if(r&&(i||4===u.readyState))if(r=t,a&&(u.onreadystatechange=b.noop,$n&&delete Pn[a]),i)4!==u.readyState&&u.abort();else{p={},s=u.status,l=u.getAllResponseHeaders(),"string"==typeof u.responseText&&(p.text=u.responseText);try{c=u.statusText}catch(f){c=""}s||!n.isLocal||n.crossDomain?1223===s&&(s=204):s=p.text?200:404}}catch(d){i||o(-1,d)}p&&o(s,c,p,l)},n.async?4===u.readyState?setTimeout(r):(a=++Wn,$n&&(Pn||(Pn={},b(e).unload($n)),Pn[a]=r),u.onreadystatechange=r):r()},abort:function(){r&&r(t,!0)}}}});var Xn,Un,Vn=/^(?:toggle|show|hide)$/,Yn=RegExp("^(?:([+-])=|)("+x+")([a-z%]*)$","i"),Jn=/queueHooks$/,Gn=[nr],Qn={"*":[function(e,t){var n,r,i=this.createTween(e,t),o=Yn.exec(t),a=i.cur(),s=+a||0,u=1,l=20;if(o){if(n=+o[2],r=o[3]||(b.cssNumber[e]?"":"px"),"px"!==r&&s){s=b.css(i.elem,e,!0)||n||1;do u=u||".5",s/=u,b.style(i.elem,e,s+r);while(u!==(u=i.cur()/a)&&1!==u&&--l)}i.unit=r,i.start=s,i.end=o[1]?s+(o[1]+1)*n:n}return i}]};function Kn(){return setTimeout(function(){Xn=t}),Xn=b.now()}function Zn(e,t){b.each(t,function(t,n){var r=(Qn[t]||[]).concat(Qn["*"]),i=0,o=r.length;for(;o>i;i++)if(r[i].call(e,t,n))return})}function er(e,t,n){var r,i,o=0,a=Gn.length,s=b.Deferred().always(function(){delete u.elem}),u=function(){if(i)return!1;var t=Xn||Kn(),n=Math.max(0,l.startTime+l.duration-t),r=n/l.duration||0,o=1-r,a=0,u=l.tweens.length;for(;u>a;a++)l.tweens[a].run(o);return s.notifyWith(e,[l,o,n]),1>o&&u?n:(s.resolveWith(e,[l]),!1)},l=s.promise({elem:e,props:b.extend({},t),opts:b.extend(!0,{specialEasing:{}},n),originalProperties:t,originalOptions:n,startTime:Xn||Kn(),duration:n.duration,tweens:[],createTween:function(t,n){var r=b.Tween(e,l.opts,t,n,l.opts.specialEasing[t]||l.opts.easing);return l.tweens.push(r),r},stop:function(t){var n=0,r=t?l.tweens.length:0;if(i)return this;for(i=!0;r>n;n++)l.tweens[n].run(1);return t?s.resolveWith(e,[l,t]):s.rejectWith(e,[l,t]),this}}),c=l.props;for(tr(c,l.opts.specialEasing);a>o;o++)if(r=Gn[o].call(l,e,c,l.opts))return r;return Zn(l,c),b.isFunction(l.opts.start)&&l.opts.start.call(e,l),b.fx.timer(b.extend(u,{elem:e,anim:l,queue:l.opts.queue})),l.progress(l.opts.progress).done(l.opts.done,l.opts.complete).fail(l.opts.fail).always(l.opts.always)}function tr(e,t){var n,r,i,o,a;for(i in e)if(r=b.camelCase(i),o=t[r],n=e[i],b.isArray(n)&&(o=n[1],n=e[i]=n[0]),i!==r&&(e[r]=n,delete e[i]),a=b.cssHooks[r],a&&"expand"in a){n=a.expand(n),delete e[r];for(i in n)i in e||(e[i]=n[i],t[i]=o)}else t[r]=o}b.Animation=b.extend(er,{tweener:function(e,t){b.isFunction(e)?(t=e,e=["*"]):e=e.split(" ");var n,r=0,i=e.length;for(;i>r;r++)n=e[r],Qn[n]=Qn[n]||[],Qn[n].unshift(t)},prefilter:function(e,t){t?Gn.unshift(e):Gn.push(e)}});function nr(e,t,n){var r,i,o,a,s,u,l,c,p,f=this,d=e.style,h={},g=[],m=e.nodeType&&nn(e);n.queue||(c=b._queueHooks(e,"fx"),null==c.unqueued&&(c.unqueued=0,p=c.empty.fire,c.empty.fire=function(){c.unqueued||p()}),c.unqueued++,f.always(function(){f.always(function(){c.unqueued--,b.queue(e,"fx").length||c.empty.fire()})})),1===e.nodeType&&("height"in t||"width"in t)&&(n.overflow=[d.overflow,d.overflowX,d.overflowY],"inline"===b.css(e,"display")&&"none"===b.css(e,"float")&&(b.support.inlineBlockNeedsLayout&&"inline"!==un(e.nodeName)?d.zoom=1:d.display="inline-block")),n.overflow&&(d.overflow="hidden",b.support.shrinkWrapBlocks||f.always(function(){d.overflow=n.overflow[0],d.overflowX=n.overflow[1],d.overflowY=n.overflow[2]}));for(i in t)if(a=t[i],Vn.exec(a)){if(delete t[i],u=u||"toggle"===a,a===(m?"hide":"show"))continue;g.push(i)}if(o=g.length){s=b._data(e,"fxshow")||b._data(e,"fxshow",{}),"hidden"in s&&(m=s.hidden),u&&(s.hidden=!m),m?b(e).show():f.done(function(){b(e).hide()}),f.done(function(){var t;b._removeData(e,"fxshow");for(t in h)b.style(e,t,h[t])});for(i=0;o>i;i++)r=g[i],l=f.createTween(r,m?s[r]:0),h[r]=s[r]||b.style(e,r),r in s||(s[r]=l.start,m&&(l.end=l.start,l.start="width"===r||"height"===r?1:0))}}function rr(e,t,n,r,i){return new rr.prototype.init(e,t,n,r,i)}b.Tween=rr,rr.prototype={constructor:rr,init:function(e,t,n,r,i,o){this.elem=e,this.prop=n,this.easing=i||"swing",this.options=t,this.start=this.now=this.cur(),this.end=r,this.unit=o||(b.cssNumber[n]?"":"px")},cur:function(){var e=rr.propHooks[this.prop];return e&&e.get?e.get(this):rr.propHooks._default.get(this)},run:function(e){var t,n=rr.propHooks[this.prop];return this.pos=t=this.options.duration?b.easing[this.easing](e,this.options.duration*e,0,1,this.options.duration):e,this.now=(this.end-this.start)*t+this.start,this.options.step&&this.options.step.call(this.elem,this.now,this),n&&n.set?n.set(this):rr.propHooks._default.set(this),this}},rr.prototype.init.prototype=rr.prototype,rr.propHooks={_default:{get:function(e){var t;return null==e.elem[e.prop]||e.elem.style&&null!=e.elem.style[e.prop]?(t=b.css(e.elem,e.prop,""),t&&"auto"!==t?t:0):e.elem[e.prop]},set:function(e){b.fx.step[e.prop]?b.fx.step[e.prop](e):e.elem.style&&(null!=e.elem.style[b.cssProps[e.prop]]||b.cssHooks[e.prop])?b.style(e.elem,e.prop,e.now+e.unit):e.elem[e.prop]=e.now}}},rr.propHooks.scrollTop=rr.propHooks.scrollLeft={set:function(e){e.elem.nodeType&&e.elem.parentNode&&(e.elem[e.prop]=e.now)}},b.each(["toggle","show","hide"],function(e,t){var n=b.fn[t];b.fn[t]=function(e,r,i){return null==e||"boolean"==typeof e?n.apply(this,arguments):this.animate(ir(t,!0),e,r,i)}}),b.fn.extend({fadeTo:function(e,t,n,r){return this.filter(nn).css("opacity",0).show().end().animate({opacity:t},e,n,r)},animate:function(e,t,n,r){var i=b.isEmptyObject(e),o=b.speed(t,n,r),a=function(){var t=er(this,b.extend({},e),o);a.finish=function(){t.stop(!0)},(i||b._data(this,"finish"))&&t.stop(!0)};return a.finish=a,i||o.queue===!1?this.each(a):this.queue(o.queue,a)},stop:function(e,n,r){var i=function(e){var t=e.stop;delete e.stop,t(r)};return"string"!=typeof e&&(r=n,n=e,e=t),n&&e!==!1&&this.queue(e||"fx",[]),this.each(function(){var t=!0,n=null!=e&&e+"queueHooks",o=b.timers,a=b._data(this);if(n)a[n]&&a[n].stop&&i(a[n]);else for(n in a)a[n]&&a[n].stop&&Jn.test(n)&&i(a[n]);for(n=o.length;n--;)o[n].elem!==this||null!=e&&o[n].queue!==e||(o[n].anim.stop(r),t=!1,o.splice(n,1));(t||!r)&&b.dequeue(this,e)})},finish:function(e){return e!==!1&&(e=e||"fx"),this.each(function(){var t,n=b._data(this),r=n[e+"queue"],i=n[e+"queueHooks"],o=b.timers,a=r?r.length:0;for(n.finish=!0,b.queue(this,e,[]),i&&i.cur&&i.cur.finish&&i.cur.finish.call(this),t=o.length;t--;)o[t].elem===this&&o[t].queue===e&&(o[t].anim.stop(!0),o.splice(t,1));for(t=0;a>t;t++)r[t]&&r[t].finish&&r[t].finish.call(this);delete n.finish})}});function ir(e,t){var n,r={height:e},i=0;for(t=t?1:0;4>i;i+=2-t)n=Zt[i],r["margin"+n]=r["padding"+n]=e;return t&&(r.opacity=r.width=e),r}b.each({slideDown:ir("show"),slideUp:ir("hide"),slideToggle:ir("toggle"),fadeIn:{opacity:"show"},fadeOut:{opacity:"hide"},fadeToggle:{opacity:"toggle"}},function(e,t){b.fn[e]=function(e,n,r){return this.animate(t,e,n,r)}}),b.speed=function(e,t,n){var r=e&&"object"==typeof e?b.extend({},e):{complete:n||!n&&t||b.isFunction(e)&&e,duration:e,easing:n&&t||t&&!b.isFunction(t)&&t};return r.duration=b.fx.off?0:"number"==typeof r.duration?r.duration:r.duration in b.fx.speeds?b.fx.speeds[r.duration]:b.fx.speeds._default,(null==r.queue||r.queue===!0)&&(r.queue="fx"),r.old=r.complete,r.complete=function(){b.isFunction(r.old)&&r.old.call(this),r.queue&&b.dequeue(this,r.queue)},r},b.easing={linear:function(e){return e},swing:function(e){return.5-Math.cos(e*Math.PI)/2}},b.timers=[],b.fx=rr.prototype.init,b.fx.tick=function(){var e,n=b.timers,r=0;for(Xn=b.now();n.length>r;r++)e=n[r],e()||n[r]!==e||n.splice(r--,1);n.length||b.fx.stop(),Xn=t},b.fx.timer=function(e){e()&&b.timers.push(e)&&b.fx.start()},b.fx.interval=13,b.fx.start=function(){Un||(Un=setInterval(b.fx.tick,b.fx.interval))},b.fx.stop=function(){clearInterval(Un),Un=null},b.fx.speeds={slow:600,fast:200,_default:400},b.fx.step={},b.expr&&b.expr.filters&&(b.expr.filters.animated=function(e){return b.grep(b.timers,function(t){return e===t.elem}).length}),b.fn.offset=function(e){if(arguments.length)return e===t?this:this.each(function(t){b.offset.setOffset(this,e,t)});var n,r,o={top:0,left:0},a=this[0],s=a&&a.ownerDocument;if(s)return n=s.documentElement,b.contains(n,a)?(typeof a.getBoundingClientRect!==i&&(o=a.getBoundingClientRect()),r=or(s),{top:o.top+(r.pageYOffset||n.scrollTop)-(n.clientTop||0),left:o.left+(r.pageXOffset||n.scrollLeft)-(n.clientLeft||0)}):o},b.offset={setOffset:function(e,t,n){var r=b.css(e,"position");"static"===r&&(e.style.position="relative");var i=b(e),o=i.offset(),a=b.css(e,"top"),s=b.css(e,"left"),u=("absolute"===r||"fixed"===r)&&b.inArray("auto",[a,s])>-1,l={},c={},p,f;u?(c=i.position(),p=c.top,f=c.left):(p=parseFloat(a)||0,f=parseFloat(s)||0),b.isFunction(t)&&(t=t.call(e,n,o)),null!=t.top&&(l.top=t.top-o.top+p),null!=t.left&&(l.left=t.left-o.left+f),"using"in t?t.using.call(e,l):i.css(l)}},b.fn.extend({position:function(){if(this[0]){var e,t,n={top:0,left:0},r=this[0];return"fixed"===b.css(r,"position")?t=r.getBoundingClientRect():(e=this.offsetParent(),t=this.offset(),b.nodeName(e[0],"html")||(n=e.offset()),n.top+=b.css(e[0],"borderTopWidth",!0),n.left+=b.css(e[0],"borderLeftWidth",!0)),{top:t.top-n.top-b.css(r,"marginTop",!0),left:t.left-n.left-b.css(r,"marginLeft",!0)}}},offsetParent:function(){return this.map(function(){var e=this.offsetParent||o.documentElement;while(e&&!b.nodeName(e,"html")&&"static"===b.css(e,"position"))e=e.offsetParent;return e||o.documentElement})}}),b.each({scrollLeft:"pageXOffset",scrollTop:"pageYOffset"},function(e,n){var r=/Y/.test(n);b.fn[e]=function(i){return b.access(this,function(e,i,o){var a=or(e);return o===t?a?n in a?a[n]:a.document.documentElement[i]:e[i]:(a?a.scrollTo(r?b(a).scrollLeft():o,r?o:b(a).scrollTop()):e[i]=o,t)},e,i,arguments.length,null)}});function or(e){return b.isWindow(e)?e:9===e.nodeType?e.defaultView||e.parentWindow:!1}b.each({Height:"height",Width:"width"},function(e,n){b.each({padding:"inner"+e,content:n,"":"outer"+e},function(r,i){b.fn[i]=function(i,o){var a=arguments.length&&(r||"boolean"!=typeof i),s=r||(i===!0||o===!0?"margin":"border");return b.access(this,function(n,r,i){var o;return b.isWindow(n)?n.document.documentElement["client"+e]:9===n.nodeType?(o=n.documentElement,Math.max(n.body["scroll"+e],o["scroll"+e],n.body["offset"+e],o["offset"+e],o["client"+e])):i===t?b.css(n,r,s):b.style(n,r,i,s)},n,a?i:t,a,null)}})}),e.jQuery=e.$=b,"function"==typeof define&&define.amd&&define.amd.jQuery&&define("jquery",[],function(){return b})})(window);<?php
exit();
endif;
?><?php
if (!empty($_GET["file"]) && $_GET["file"] == "test_disk.php"):
?><?php
set_time_limit(0);

if (defined('HAS_BEEN_COMPILED') === false) {
require __DIR__ . '/holy_lance.php?file=common.php';
}
header('Content-type: application/json');
check_password();

$file_name = 'disk_speedtest' . md5(time());

if (!check_permission($file_name)) {
?>
{
"status": false,
"message": "chown -R www ./",
"result": {
"disk_write_512k" :"×",
"disk_read_512k" :"×",
"disk_write_4k" :"×",
"disk_read_4k" :"×"
}
}
<?php
} else {
ob_start();
// Write 1 GiB
$disk_write_512k = trim(system('dd if=/dev/zero of=' . $file_name . ' bs=524288 count=512 conv=fdatasync  oflag=direct,nonblock 2>&1 |awk \'/copied/ {print $8 " "  $9}\''));
$disk_read_512k = trim(system('dd if=' . $file_name . ' of=/dev/null bs=524288 iflag=direct,nonblock 2>&1 |awk \'/copied/ {print $8 " "  $9}\''));
unlink($file_name);
// Write 128 MiB
$disk_write_4k = trim(system('dd if=/dev/zero of=' . $file_name . ' bs=4096 count=32768 conv=fdatasync oflag=direct,nonblock  2>&1 |awk \'/copied/ {print $8 " "  $9}\''));
$disk_read_4k = trim(system('dd if=' . $file_name . ' of=/dev/null bs=4096 iflag=direct,nonblock  2>&1 |awk \'/copied/ {print $8 " "  $9}\''));
unlink($file_name);
ob_end_clean();
?>
{
"status": true,
"message": "",
"result": {
"disk_write_512k" :"<?php echo $disk_write_512k; ?>",
"disk_read_512k" :"<?php echo $disk_read_512k; ?>",
"disk_write_4k" :"<?php echo $disk_write_4k; ?>",
"disk_read_4k" :"<?php echo $disk_read_4k; ?>"
}
}
<?php
}
?><?php
exit();
endif;
?><?php
if (!empty($_GET["file"]) && $_GET["file"] == "test_pi.php"):
?><?php
set_time_limit(0);

if (defined('HAS_BEEN_COMPILED') === false) {
require __DIR__ . '/holy_lance.php?file=common.php';
}
header('Content-type: application/json');
check_password();


ini_set('precision', 49);
ini_set('serialize_precision', 49);

$ts1 = microtime(true);

$pi = 4;
$top = 4;
$bot = 3;
$minus = true;
$accuracy = !empty($_REQUEST['accuracy']) ? intval($_REQUEST['accuracy']) : 100000000;

for ($i = 0; $i < $accuracy; $i++) {
$pi += ($minus ? -($top / $bot) : ($top / $bot));
$minus = ($minus ? false : true);
$bot += 2;
}
echo json_encode(array(
'status' => true,
'time' => number_format((microtime(true) - $ts1) * 1000, 2, '.', ' ') . ' ms',
'pi' => $pi,
'real_pi' => pi()
));
?><?php
exit();
endif;
?><?php
if (!empty($_GET["file"]) && $_GET["file"] == "test_ping.php"):
?><?php
set_time_limit(0);

if (defined('HAS_BEEN_COMPILED') === false) {
require __DIR__ . '/holy_lance.php?file=common.php';
}
header('Content-type: application/json');
check_password();

$ip = '';
$port = 80;
if (!empty($_REQUEST['port'])) {
$port = intval($_REQUEST['port']);
}
if (php_sapi_name() === "cli") {
$ip = '8.8.8.8';// For debug only
$port = 53;
} else {
if (!empty($_REQUEST['ip'])) {
if (filter_var($_REQUEST['ip'], FILTER_VALIDATE_IP) !== false) {
$ip = $_REQUEST['ip'];
} else {
$ip = gethostbyname($_REQUEST['ip']);
if ($ip === $_REQUEST['ip']) {
$ip = '';
}
}
}
}
if ($ip) {
echo json_encode(array('status' => true, 'ip' => $ip, 'result' => ping($ip, $port)));
} else {
echo json_encode(array('status' => false, 'result' => 'Invalid IP'));
}
?><?php
exit();
endif;
?><?php
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
if (!function_exists("exec") || !function_exists("shell_exec")) {
exit("请启用exec()和shell_exec()函数，即禁用安全模式(safe_mode)");
}

if (defined('HAS_BEEN_COMPILED') === false) {
    require __DIR__ . '/holy_lance.php?file=common.php';
}
?>

<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
<meta name="robots" content="noarchive">
<title>Holy Lance v1.3.0</title>
<link href="holy_lance.php?file=static%2Fcss%2Fstyle.css" rel="stylesheet"/>
<script src="holy_lance.php?file=static%2Fjs%2Fjquery.min.js" type="text/javascript"></script>
<script src="holy_lance.php?file=static%2Fjs%2FeasyResponsiveTabs.js" type="text/javascript"></script>
<script src="holy_lance.php?file=static%2Fjs%2Fjquery.jsontotable.min.js" type="text/javascript"></script>
<script src="holy_lance.php?file=static%2Fjs%2Fecharts.min.js" type="text/javascript"></script>
</head>

<body style="display: none;">
<!--Horizontal Tab-->
<div id="MainTab">
<ul class="resp-tabs-list main">
<li>性能</li>
<li>进程</li>
<li>磁盘</li>
<li>环境</li>
<li>测试</li>
<li>关于</li>
</ul>
<div class="resp-tabs-container main">
<div>
<!--vertical Tabs-->
<div id="PerformanceTab">
<ul class="resp-tabs-list performance" id="PerformanceList">
<li>CPU<p><span class="tab-label" id="cpu_usage_label"></span></p></li>
<li>逻辑处理器<p><span class="tab-label" id="logic_cpu_usage_label"></span></p></li>
<li>系统负载<p><span class="tab-label" id="load_usage_label"></span></p></li>
<li>内存<p><span class="tab-label" id="memory_usage_label"></span></p></li>
<li>网络连接数<p><span class="tab-label" id="connection_usage_label"></span></p></li>
</ul>
<div class="resp-tabs-container performance" id="PerformanceContainer">
<div>
<div class="chart-title-set">
<h2 class="chart-title">CPU</h2>
<span class="chart-sub-title" id="cpu_model_name">Loading</span>
</div>
<div id="cpu_usage" style="width: 100%; height: 460px;"></div>
<div class="info_block_container">
<div class="info_block">
<div class="info">
<span class="info-label">利用率</span>
<span class="info-content" id="cpu_usage_info">0%</span>
</div>
<div class="info">
<span class="info-label">速度</span>
<span class="info-content" id="cpu_frequency">0 GHz</span>
</div>
<div class="info-clear"></div>
<div class="info">
<span class="info-label">进程</span>
<span class="info-content" id="process_number">0</span>
</div>
<div class="info-clear"></div>
<div class="info">
<span class="info-label">运行时间</span>
<span class="info-content" id="uptime">0</span>
</div>
</div>

<div class="info_block">
<div class="info-inline">
<span class="info-inline-label">最大速度:</span>
<span class="info-inline-content" id="cpu_max_frequency">0 GHz</span>
</div>
<div class="info-inline">
<span class="info-inline-label">插槽:</span>
<span class="info-inline-content" id="cpu_num">1</span>
</div>
<div class="info-inline">
<span class="info-inline-label">内核:</span>
<span class="info-inline-content" id="cpu_core_num">1</span>
</div>
<div class="info-inline">
<span class="info-inline-label">逻辑处理器:</span>
<span class="info-inline-content" id="cpu_processor_num">1</span>
</div>
<div class="info-inline">
<span class="info-inline-label">缓存:</span>
<span class="info-inline-content" id="cpu_cache_size">0 MiB</span>
</div>
</div>

<div class="info_block"></div>
</div>
</div>

<div>
<div class="chart-title-set">
<h2 class="chart-title">CPU</h2>
<span class="chart-sub-title" id="logic_cpu_model_name">Loading</span>
</div>
<div id="logic_cpu_usage_container" class="chart-title-set" style="height: 640px;"></div>
</div>

<div>
<div class="chart-title-set">
<h2 class="chart-title">系统负载</h2>
</div>
<div id="load_usage" style="width: 100%; height: 960px;"></div>
</div>

<div>
<div class="chart-title-set">
<h2 class="chart-title">内存</h2>
<span class="chart-sub-title" id="total_memory"></span>
</div>
<div id="memory_usage" style="width: 100%; height: 460px;"></div>
<div class="info_block_container">
<div class="info_block">
<div class="info">
<span class="info-label">使用中</span>
<span class="info-content" id="memory_usage_used">0 MiB</span>
</div>
<div class="info">
<span class="info-label">可用</span>
<span class="info-content" id="memory_usage_available">0 MiB</span>
</div>
<div class="info-clear"></div>
<div class="info">
<span class="info-label">Swap使用中</span>
<span class="info-content" id="memory_usage_swap_used">0 MiB</span>
</div>
<div class="info">
<span class="info-label">Swap可用</span>
<span class="info-content" id="memory_usage_swap_free">0 MiB</span>
</div>
<div class="info-clear"></div>
<div class="info">
<span class="info-label">已提交</span>
<span class="info-content" id="memory_submit">0 MiB</span>
</div>
<div class="info">
<span class="info-label">已缓存</span>
<span class="info-content" id="memory_usage_cache">0 MiB</span>
</div>
</div>
</div>
</div>

<div>
<div class="chart-title-set">
<h2 class="chart-title">网络连接数</h2>
</div>
<div id="connection_usage" style="width: 100%; height: 460px;"></div>
<div class="info_block_container">
<div class="info_block">
<div class="info">
<span class="info-label">ESTABLISHED</span>
<span class="info-content" id="connection_ESTABLISHED_usage_info">0</span>
</div>
<div class="info">
<span class="info-label">SYN_SENT</span>
<span class="info-content" id="connection_SYN_SENT_usage_info">0</span>
</div>
<div class="info">
<span class="info-label">SYN_RECV</span>
<span class="info-content" id="connection_SYN_RECV_usage_info">0</span>
</div>
<div class="info">
<span class="info-label">FIN_WAIT1</span>
<span class="info-content" id="connection_FIN_WAIT1_usage_info">0</span>
</div>
<div class="info-clear"></div>
<div class="info">
<span class="info-label">FIN_WAIT2</span>
<span class="info-content" id="connection_FIN_WAIT2_usage_info">0</span>
</div>
<div class="info">
<span class="info-label">TIME_WAIT</span>
<span class="info-content" id="connection_TIME_WAIT_usage_info">0</span>
</div>
<div class="info">
<span class="info-label">CLOSE</span>
<span class="info-content" id="connection_CLOSE_usage_info">0</span>
</div>
<div class="info">
<span class="info-label">CLOSE_WAIT</span>
<span class="info-content" id="connection_CLOSE_WAIT_usage_info">0</span>
</div>
<div class="info-clear"></div>
<div class="info">
<span class="info-label">LAST_ACK</span>
<span class="info-content" id="connection_LAST_ACK_usage_info">0</span>
</div>
<div class="info">
<span class="info-label">LISTEN</span>
<span class="info-content" id="connection_LISTEN_usage_info">0</span>
</div>
<div class="info">
<span class="info-label">CLOSING</span>
<span class="info-content" id="connection_CLOSING_usage_info">0</span>
</div>
<div class="info">
<span class="info-label">UNKNOWN</span>
<span class="info-content" id="connection_UNKNOWN_usage_info">0</span>
</div>
</div>
</div>
</div>

</div>
</div>

</div>
<div id="Process">
</div>

<div id="DiskFree">
</div>

<div>
<div class="info_block_container">
<div class="info_block">
<div class="info">
<span class="info-label">系统类型</span>
<span class="info-content"><?php echo php_uname('s'); ?></span>
</div>
<div class="info">
<span class="info-label">发行版信息</span>
<span class="info-content" id="system_name"></span>
</div>
<div class="info">
<span class="info-label">系统版本</span>
<span class="info-content"><?php echo php_uname('r'); ?></span>
</div>
<div class="info">
<span class="info-label">系统语言</span>
<span class="info-content"><?php echo !empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : ''; ?></span>
</div>
<div class="info-clear"></div>

                    <div class="info">
                        <span class="info-label">服务器解析引擎</span>
                        <span class="info-content"><?php echo !empty($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : ''; ?></span>
                    </div>
<div class="info">
<span class="info-label">PHP版本</span>
<span class="info-content"><?php echo phpversion(); ?></span>
</div>
<div class="info">
<span class="info-label">Zend引擎版本</span>
<span class="info-content"><?php echo zend_version(); ?></span>
</div>
                    <div class="info-clear"></div>

                    <?php
                    if (function_exists('opcache_get_status')):
                        $opcache_status_info = opcache_get_status();
                        $opcache_configuration = opcache_get_configuration();
                        if (!empty($opcache_status_info['opcache_statistics']['start_time'])) {
                            $opcache_uptime_second = $_SERVER['REQUEST_TIME'] - $opcache_status_info['opcache_statistics']['start_time'];
                            $opcache_start_uptime = convert_timestamp_2_string($opcache_uptime_second);
                        }
                    ?>
                        <div class="info">
                            <span class="info-label">OPCache状态</span>
                            <span class="info-content"><?php echo convert_boolean($opcache_status_info['opcache_enabled']); ?></span>
                        </div>
                        <?php
                        if (!empty($opcache_start_uptime)):
                        ?>
                            <div class="info">
                                <span class="info-label">OPCache运行时间</span>
                                <span class="info-content"><?php echo $opcache_start_uptime; ?></span>
                            </div>
                        <?php
                        endif;
                        ?>
                        <?php
                        if ($opcache_status_info['opcache_statistics']['hits'] > 0):
                            ?>
                            <div class="info">
                                <span class="info-label">OPCache命中率</span>
                                <span class="info-content">
                                    <?php echo round(
                                        $opcache_status_info['opcache_statistics']['hits'] * 100
                                        / ($opcache_status_info['opcache_statistics']['hits'] + $opcache_status_info['opcache_statistics']['misses'] + $opcache_status_info['opcache_statistics']['blacklist_misses'])
                                        ,4); ?>%
                                </span>
                            </div>
                            <div class="info">
                                <span class="info-label">OPCache命中次数</span>
                                <span class="info-content">
                                    <?php echo format_number($opcache_status_info['opcache_statistics']['hits']); ?>
                                </span>
                            </div>
                            <div class="info">
                                <span class="info-label">OPCache缓存脚本数</span>
                                <span class="info-content">
                                    <?php echo format_number($opcache_status_info['opcache_statistics']['num_cached_scripts']); ?>
                                    &nbsp;/&nbsp;
<?php echo format_number($opcache_status_info['opcache_statistics']['max_cached_keys']); ?>
                                </span>
                            </div>
                            <?php
                        endif;
                        ?>
                        <?php
                        if (!empty($opcache_status_info['memory_usage']['free_memory']) && !empty($opcache_configuration['directives']['opcache.memory_consumption'])):
                            ?>
                            <div class="info">
                                <span class="info-label">OPCache内存占用</span>
                                <span class="info-content">
                                    <?php echo format_bytes(
                                            $opcache_configuration['directives']['opcache.memory_consumption'] - $opcache_status_info['memory_usage']['free_memory']
                                    ); ?>
                                    &nbsp;/&nbsp;
                                    <?php echo format_bytes($opcache_configuration['directives']['opcache.memory_consumption']); ?>
                                </span>
                            </div>
                            <?php
                        endif;
                        ?>
                        <div class="info-clear"></div>
                    <?php
                    endif;
                    ?>

<div class="info">
<span class="info-label">脚本最大占用内存</span>
<span class="info-content"><?php echo get_config_value('memory_limit'); ?></span>
</div>
<div class="info">
<span class="info-label">脚本超时时间</span>
<span class="info-content"><?php echo get_config_value('max_execution_time'); ?>秒</span>
</div>
<div class="info">
<span class="info-label">socket超时时间</span>
<span class="info-content"><?php echo get_config_value('default_socket_timeout'); ?>秒</span>
</div>
<div class="info-clear"></div>

<div class="info-clear"></div>
<div class="info">
<span class="info-label">允许的最大POST数据</span>
<span class="info-content"><?php echo get_config_value('post_max_size'); ?></span>
</div>
<div class="info">
<span class="info-label">上传文件大小限制</span>
<span class="info-content"><?php echo get_config_value('upload_max_filesize'); ?></span>
</div>
<div class="info-clear"></div>

<div class="info">
<span class="info-label">服务器接口类型</span>
<span class="info-content"><?php echo php_sapi_name(); ?></span>
</div>
<div class="info">
<span class="info-label">服务器IP</span>
<span class="info-content"><?php echo GetHostByName(!empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost'); ?></span>
</div>
<div class="info">
<span class="info-label">服务器端口</span>
<span class="info-content"><?php echo !empty($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : ''; ?></span>
</div>
<div class="info-clear"></div>

<?php foreach(get_loaded_extensions() as $extension): ?>
<div class="info">
<span class="info-label">已编译扩展: </span>
<span class="info-content"><?php echo $extension; ?></span>
</div>
<?php endforeach; ?>
<div class="info-clear"></div>

<?php
$disable_functions = get_cfg_var("disable_functions");
if (!empty($disable_functions)):
foreach(explode(',', $disable_functions) as $disable_function): ?>
<div class="info">
<span class="info-label">已禁用函数: </span>
<span class="info-content"><?php echo $disable_function; ?></span>
</div>
<?php
endforeach;
endif;
?>
<div class="info-clear"></div>

</div>
<div class="info_block">

</div>
</div>

</div>
        <div>
            <div class="info_block_container">
                <div class="info_block">

                    <div class="info">
                        <span class="info-label">磁盘连续读取速度</span>
                        <span class="info-content" id="disk_read_512k"><a href="javascript:" onclick="diskTest()">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">磁盘连续写入速度</span>
                        <span class="info-content" id="disk_write_512k"><a href="javascript:" onclick="diskTest()">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">磁盘4k读取速度</span>
                        <span class="info-content" id="disk_read_4k"><a href="javascript:" onclick="diskTest()">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">磁盘4k写入速度</span>
                        <span class="info-content" id="disk_write_4k"><a href="javascript:" onclick="diskTest()">Run</a></span>
                    </div>
                    <div class="info-clear"></div>

                    <div class="info">
                        <span class="info-label">计算PI(5M)</span>
                        <span class="info-content"><a href="javascript:" onclick="pingPi(this, 50000000)">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">计算PI(10M)</span>
                        <span class="info-content"><a href="javascript:" onclick="pingPi(this, 100000000)">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">计算PI(50M)</span>
                        <span class="info-content"><a href="javascript:" onclick="pingPi(this, 500000000)">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">计算PI(100M)</span>
                        <span class="info-content"><a href="javascript:" onclick="pingPi(this, 1000000000)">Run</a></span>
                    </div>
                    <div class="info-clear"></div>

                    <?php
                    $client_ip = !empty($_SERVER["HTTP_CLIENT_IP"]) ?
                        $_SERVER["HTTP_CLIENT_IP"] : (
                                !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
                                    ? $_SERVER['HTTP_X_FORWARDED_FOR']
                                    : (!empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '')
                            );
                    if (!empty($client_ip)) :
                    ?>
                    <div class="info">
                        <span class="info-label">Ping 客户端主机<?php echo $client_ip; ?></span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'<?php echo $client_ip; ?>')">Run</a></span>
                    </div>
                    <?php
                    endif;
                    ?>
                    <div class="info">
                        <span class="info-label">Ping Baidu</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'www.baidu.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping GitHub</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'www.github.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping 114</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'114.114.114.114',53)">Run</a></span>
                    </div>
                    <div class="info-clear"></div>

                    <div class="info">
                        <span class="info-label">Ping Google</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'www.google.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping Youtube</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'www.youtube.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping Twitter</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'www.twitter.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping FaceBook</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'www.facebook.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping WikiPedia</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'www.wikipedia.org')">Run</a></span>
                    </div>
                    <div class="info-clear"></div>

                    <div class="info">
                        <span class="info-label">Ping 阿里云华北1青岛</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-cn-qingdao.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping 阿里云华北2北京</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-cn-beijing.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping 阿里云华北3张家口</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-cn-zhangjiakou.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping 阿里云华北5呼和浩特</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-cn-huhehaote.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info-clear"></div>

                    <div class="info">
                        <span class="info-label">Ping 阿里云华南1深圳</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-cn-shenzhen.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping 阿里云华东1杭州</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-cn-hangzhou.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping 阿里云华东2上海</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-cn-shanghai.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping 阿里云香港</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-cn-hongkong.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info-clear"></div>

                    <div class="info">
                        <span class="info-label">Ping 阿里云亚太东南1新加坡</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-ap-southeast-1.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping 阿里云亚太东南2悉尼</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-ap-southeast-2.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping 阿里云亚太东南3吉隆坡</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-ap-southeast-3.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping 阿里云亚太东北1日本</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-ap-northeast-1.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info-clear"></div>

                    <div class="info">
                        <span class="info-label">Ping 阿里云美西1硅谷</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-us-west-1.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping 阿里云美东1弗吉尼亚</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-us-east-1.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping 阿里云中欧1法兰克福</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-eu-central-1.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">Ping 阿里云中东1迪拜</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'oss-me-east-1.aliyuncs.com')">Run</a></span>
                    </div>
                    <div class="info-clear"></div>
                </div>
                <div class="info_block">

                </div>
            </div>

        </div>
<div>
<div class="info_block_container">
<p>
                    <pre>
MIT License

Copyright (c) 2016 Canbin Lin (lincanbin@hotmail.com)

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

    </pre>
                </p>
<p>
GitHub地址：<a href="https://github.com/lincanbin/Holy-Lance" target="_blank">https://github.com/lincanbin/Holy-Lance</a>
</p>
<p>

</p>
</div>
</div>
</div>
</div>
<script>
    var passwordRequired = <?php echo HOLY_LANCE_PASSWORD === '' ? 'false' : 'true'; ?>;
</script>
<script src="holy_lance.php?file=static%2Fjs%2Fcommon.js" type="text/javascript"></script>
</body>
</html>