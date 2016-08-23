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
var numberOfRecords  = 360; // points
var intervalTime = 3000; // ms
var password = 12345678; // ms


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
    for(i=0; i<arr.length; i++){ 
        refer[i] = arr[i][field]+':'+i; 
    } 
    refer.sort(); 
    if(order=='desc') refer.reverse(); 
    for(i=0;i<refer.length;i++){ 
        index = refer[i].split(':')[1]; 
        result[i] = arr[index]; 
    } 
    return result; 
}

function kibiBytesToSize(bytes) {
	if (bytes === 0) return '0 B';
	var kibi = 1024, // or 1000
		sizes = ['KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'],
		i = Math.floor(Math.log(bytes) / Math.log(kibi));
	return (bytes / Math.pow(kibi, i)).toPrecision(3) + ' ' + sizes[i];
}

function resizeChart() {
	window.cpuUsageChart.resize();
	window.memoryUsageChart.resize();
	window.diskUsageChart.resize();
}

function init(data) {
	window.env = data;
	for (var eth in data.network) {
		$(".resp-tabs-list .performance").append('<li>' + eth + '<p><span class="tab-label" id="network_' + eth + '_usage_label"></span></p></li>');
		$(".resp-tabs-container .performance").append('<div><div id="network_' + eth + '_usage" style="width: 100%; height:100%; min-height: 460px;"></div></div>');
	}
	$('#MainTab').easyResponsiveTabs({
		type: 'default', //Types: default, vertical, accordion
		width: 'auto', //auto or any width like 600px
		fit: true, // 100% fit in a container
		closed: 'accordion', // Start closed if in accordion view
		tabidentify: 'main' // The tab groups identifier
	});

	$('#PerformanceTab').easyResponsiveTabs({
		type: 'vertical',
		width: 'auto',
		fit: true,
		tabidentify: 'performance', // The tab groups identifier
		activetab_bg: '#fff', // background color for active tabs in this group
		inactive_bg: '#F5F5F5', // background color for inactive tabs in this group
		active_border_color: '#c1c1c1', // border color for active tabs heads in this group
		active_content_border_color: '#5AB1D0', // border color for active tabs contect in this group so that it matches the tab head border
		activate: function() {
			resizeChart();
		}  // Callback function, gets called if tab is switched
	});

	window.cpuUsageChart = echarts.init(document.getElementById('cpu_usage'));
	window.memoryUsageChart = echarts.init(document.getElementById('memory_usage'));
	window.diskUsageChart = echarts.init(document.getElementById('disk_usage'));
	window.cpuUsageChartoption = {
		title: {},
		tooltip: {},
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

	window.memoryUsageChartoption = cloneObject(window.cpuUsageChartoption);
	memoryUsageChartoption.yAxis.name = '内存使用量 MiB';
	memoryUsageChartoption.color = ['#8B12AE'];
	memoryUsageChartoption.series[0].name = 'Memory Usage';

	window.diskUsageChartoption = cloneObject(window.cpuUsageChartoption);
	diskUsageChartoption.yAxis.name = '活动时间 %';
	diskUsageChartoption.color = ['#4DA60C'];
	diskUsageChartoption.series[0].name = 'Disk Usage';

	refreshChart();
}


function refreshChart() {
	$.ajax({
		type: "POST",
		url: "api.php",
		data: {
			password: password
		},
		dataType: "json",
		success: function(data){
			axisData = (new Date()).toLocaleTimeString().replace(/^\D*/,'');
			// CPU
			$("#cpu_usage_label").text(data.cpu_usage + "%");
			cpuUsageChartoption.series[0].data.shift();
			cpuUsageChartoption.series[0].data.push(data.cpu_usage);
			cpuUsageChartoption.xAxis.data.shift();
			cpuUsageChartoption.xAxis.data.push(axisData);
			cpuUsageChart.setOption(cpuUsageChartoption);
			// Memory
			$("#memory_usage_label").text(kibiBytesToSize(data.memory_usage_used) + "/" + kibiBytesToSize(data.memory_usage_total));
			memoryUsageChartoption.yAxis.max = Math.round(data.memory_usage_total / 1024);
			memoryUsageChartoption.series[0].data.shift();
			memoryUsageChartoption.series[0].data.push(Math.round(data.memory_usage_used / 1024));
			memoryUsageChartoption.xAxis.data.shift();
			memoryUsageChartoption.xAxis.data.push(axisData);
			memoryUsageChart.setOption(memoryUsageChartoption);
			// Disk
			var disk_usage_percent = Math.min((data.disk_read_active_time + data.disk_write_active_time) / 10, 100);
			$("#disk_usage_label").text(disk_usage_percent);
			diskUsageChartoption.series[0].data.shift();
			diskUsageChartoption.series[0].data.push(disk_usage_percent);
			diskUsageChartoption.xAxis.data.shift();
			diskUsageChartoption.xAxis.data.push(axisData);
			diskUsageChart.setOption(diskUsageChartoption);
			// Callback
			setTimeout(function(){refreshChart();}, intervalTime);
		},
		error: function (data, e) {
			// Callback
			setTimeout(function(){refreshChart();}, intervalTime);
		}
	});
}

$(document).ready(function () {
	$.ajax({
		type: "POST",
		url: "init.php",
		data: {
			password: password
		},
		dataType: "json",
		success: function(data){
			init(data);
		}
	});
});