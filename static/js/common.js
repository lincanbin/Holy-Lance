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

function kibiBytesToSize(bytes) {
	if (bytes == 0) return '0 B';
	var kibi = 1024, // or 1000
		sizes = ['KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'],
		i = Math.floor(Math.log(bytes) / Math.log(kibi));
	sizes[-1] = 'B';
	return (bytes / Math.pow(kibi, i)).toPrecision(3) + ' ' + sizes[i];
}

function resizeChart() {
	window.cpuUsageChart.resize();
	window.memoryUsageChart.resize();
	window.diskUsageChart.resize();
	window.diskSpeedChart.resize();
	for (var eth in window.env.network) {
		window.networkUsageChart[window.env.network[eth]].resize();
	}
}

function init(data) {
	window.env = data;
	window.processSortedBy = 2;
	window.processOrder = 'desc';
	console.log(data);
	for (var eth in data.network) {
		$("#PerformanceList").append('<li>网卡' + data.network[eth] + '<p><span class="tab-label" id="network_' + data.network[eth] + '_usage_label"></span></p></li>');
		$("#PerformanceContainer").append('<div><div id="network_' + data.network[eth] + '_usage" style="width: 100%; height:100%; min-height: 760px;"></div></div>');
	}
	$('#MainTab').easyResponsiveTabs({
		type: 'default', //Types: default, vertical, accordion
		width: 'auto', //auto or any width like 600px
		fit: true, // 100% fit in a container
		closed: 'accordion', // Start closed if in accordion view
		tabidentify: 'main', // The tab groups identifier
		activate: function() {
			resizeChart();
		}
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

	window.memoryUsageChart = echarts.init(document.getElementById('memory_usage'));
	window.memoryUsageChartoption = cloneObject(window.cpuUsageChartoption);
	memoryUsageChartoption.yAxis.name = '内存使用量 MiB';
	memoryUsageChartoption.color = ['#8B12AE'];
	memoryUsageChartoption.series[0].name = 'Memory Usage';


	window.diskUsageChart = echarts.init(document.getElementById('disk_usage'));
	window.diskUsageChartoption = cloneObject(window.cpuUsageChartoption);
	diskUsageChartoption.yAxis.name = '活动时间 %';
	diskUsageChartoption.color = ['#4DA60C'];
	diskUsageChartoption.series[0].name = 'Disk Usage';

	window.diskSpeedChart = echarts.init(document.getElementById('disk_speed'));
	window.diskSpeedChartoption = cloneObject(window.cpuUsageChartoption);
	diskSpeedChartoption.yAxis.name = '磁盘传输速率  read(+) / write(-) KiB/s';
	diskSpeedChartoption.yAxis.max = null;
	diskSpeedChartoption.yAxis.min = null;
	diskSpeedChartoption.color = ['#4DA60C'];
	diskSpeedChartoption.series[0].name = 'Disk Speed';
	diskSpeedChartoption.series[1] = cloneObject(diskSpeedChartoption.series[0]);

	window.networkUsageChart = [];
	window.networkUsageChartoption = [];
	for (var eth in data.network) {
		window.networkUsageChart[data.network[eth]] = echarts.init(document.getElementById('network_' + data.network[eth] + '_usage'));
		window.networkUsageChartoption[data.network[eth]] = cloneObject(window.cpuUsageChartoption);
		networkUsageChartoption[data.network[eth]].yAxis.name = '吞吐量 out(+) / in(-) KiB/s';
		networkUsageChartoption[data.network[eth]].yAxis.max = null;
		networkUsageChartoption[data.network[eth]].yAxis.min = null;
		networkUsageChartoption[data.network[eth]].color = ['#A74F01'];
		networkUsageChartoption[data.network[eth]].series[0].name = 'Network Usage';
		networkUsageChartoption[data.network[eth]].series[1] = cloneObject(networkUsageChartoption[data.network[eth]].series[0]);
	}

	refreshChart();
}

function drawProcessTable(processData, formatData) {
	// Process
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
			$("#memory_usage_label").text(kibiBytesToSize(data.memory_usage_used) + " / " + kibiBytesToSize(data.memory_usage_total) + " (" + Math.round(data.memory_usage_used * 100 / data.memory_usage_total) + "%)");
			memoryUsageChartoption.yAxis.max = Math.round(data.memory_usage_total / 1024);
			memoryUsageChartoption.series[0].data.shift();
			memoryUsageChartoption.series[0].data.push(Math.round(data.memory_usage_used / 1024));
			memoryUsageChartoption.xAxis.data.shift();
			memoryUsageChartoption.xAxis.data.push(axisData);
			memoryUsageChart.setOption(memoryUsageChartoption);
			// Disk Usage
			var disk_usage_percent = Math.min((data.disk_read_active_time + data.disk_write_active_time) / 10, 100);
			$("#disk_usage_label").text(disk_usage_percent + "%");
			diskUsageChartoption.series[0].data.shift();
			diskUsageChartoption.series[0].data.push(disk_usage_percent);
			diskUsageChartoption.xAxis.data.shift();
			diskUsageChartoption.xAxis.data.push(axisData);
			diskUsageChart.setOption(diskUsageChartoption);
			// Disk Speed
			diskSpeedChartoption.series[0].data.shift();
			diskSpeedChartoption.series[0].data.push(data.disk_read_speed);
			diskSpeedChartoption.series[1].data.shift();
			diskSpeedChartoption.series[1].data.push(-data.disk_write_speed);
			diskSpeedChartoption.xAxis.data.shift();
			diskSpeedChartoption.xAxis.data.push(axisData);
			diskSpeedChart.setOption(diskSpeedChartoption);
			// Network
			for (var eth in window.env.network) {
				$("#network_" + window.env.network[eth] + "_usage_label").text("发送：" + kibiBytesToSize(data.network[window.env.network[eth]].transmit_speed / 1024) + "/s 接收：" + kibiBytesToSize(data.network[window.env.network[eth]].receive_speed / 1024) + "/s");
				// networkUsageChartoption[window.env.network[eth]].yAxis.max = Math.max(data.network[window.env.network[eth]].transmit_speed, data.network[window.env.network[eth]].receive_speed / 1024);
				networkUsageChartoption[window.env.network[eth]].series[0].data.shift();
				networkUsageChartoption[window.env.network[eth]].series[0].data.push(-Math.round(data.network[window.env.network[eth]].receive_speed / 1024));
				networkUsageChartoption[window.env.network[eth]].series[1].data.shift();
				networkUsageChartoption[window.env.network[eth]].series[1].data.push(Math.round(data.network[window.env.network[eth]].transmit_speed / 1024));
				networkUsageChartoption[window.env.network[eth]].xAxis.data.shift();
				networkUsageChartoption[window.env.network[eth]].xAxis.data.push(axisData);
				window.networkUsageChart[window.env.network[eth]].setOption(networkUsageChartoption[window.env.network[eth]]);
			}
			// Process
			drawProcessTable(data.process, true);
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