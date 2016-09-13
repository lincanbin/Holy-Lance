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
	for (var offset in window.env.network) {
		window.networkUsageChart[window.env.network[offset]].resize();
	}
	for (var offset in window.env.disk) {
		window.diskUsageChart[window.env.disk[offset]].resize();
		window.diskSpeedChart[window.env.disk[offset]].resize();
	}
}

function init(data) {
	window.env = data;
	window.processSortedBy = 2;
	window.processOrder = 'desc';
	console.log(data);
	for (var offset in data.disk) {
		$("#PerformanceList").append('<li>磁盘' + 
			data.disk[offset] + 
			'<p><span class="tab-label" id="disk_' + data.disk[offset] + '_usage_label"></span></p></li>');
		$("#PerformanceContainer").append('<div><div class="chart-title-set"><h2 class="chart-title">磁盘' + 
			data.disk[offset] + 
			'</h2><span class="chart-sub-title" id="disk_' + data.disk[offset] + '_size"></span></div>' + 
			'<div id="disk_' + data.disk[offset] + '_usage" style="width: 100%; height: 460px;"></div>' +
			'<div id="disk_' + data.disk[offset] + '_speed" style="width: 100%; height: 360px;"></div>' +
			'</div>');
	}

	for (var offset in data.network) {
		$("#PerformanceList").append('<li>网卡' + 
			data.network[offset] + 
			'<p><span class="tab-label" id="network_' + data.network[offset] + '_usage_label"></span></p></li>');
		$("#PerformanceContainer").append('<div><div class="chart-title-set"><h2 class="chart-title">网卡' + 
			data.network[offset] + 
			'</h2><span class="chart-sub-title" id="eth_name_' + data.network[offset] + '"></span></div>' + 
			'<div id="network_' + data.network[offset] + '_usage" style="width: 100%; height: 460px;"></div>' +

			'</div>');
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
		// activetab_bg: '#FFFFFF', // background color for active tabs in this group
		// inactive_bg: '#F5F5F5', // background color for inactive tabs in this group
		// active_border_color: '#C1C1C1', // border color for active tabs heads in this group
		// active_content_border_color: '#5AB1D0', // border color for active tabs contect in this group so that it matches the tab head border
		activate: function() {
			resizeChart();
		}  // Callback function, gets called if tab is switched
	});
	$('#cpu_model_name').text(data.cpu_info.cpu_name);
	$('#total_memory').text(kibiBytesToSize(data.memory.MemTotal));
	$('#cpu_max_frequency').text((data.cpu_info.cpu_frequency / 1000).toFixed(2) + " GHz");
	$('#cpu_frequency').text((data.cpu_info.cpu_frequency / 1000).toFixed(2) + " GHz");
	$('#cpu_num').text(data.cpu_info.cpu_num);
	$('#cpu_processor_num').text(data.cpu_info.cpu_processor_num);
	$('#cpu_core_num').text(data.cpu_info.cpu_core_num);
	$('#cpu_cache_size').text(data.cpu[0].cache_size.replace("KB", "KiB"));

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
		diskSpeedChartoption[data.disk[offset]].series[0].name = 'Disk Speed';
		diskSpeedChartoption[data.disk[offset]].series[1] = cloneObject(diskSpeedChartoption[data.disk[offset]].series[0]);
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
		networkUsageChartoption[data.network[offset]].series[0].name = 'Network Usage';
		networkUsageChartoption[data.network[offset]].series[1] = cloneObject(networkUsageChartoption[data.network[offset]].series[0]);
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
			$("#cpu_usage_info").text(data.cpu_usage + "%");
			$("#process_number").text(data.process_number);
			$("#uptime").text(data.uptime);

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
			cpuUsageChart.setOption(cpuUsageChartoption);
			// Memory
			$("#memory_usage_label").text(kibiBytesToSize(data.memory_usage_used) + " / " + kibiBytesToSize(data.memory_usage_total) + " (" + Math.round(data.memory_usage_used * 100 / data.memory_usage_total) + "%)");
			memoryUsageChartoption.yAxis.max = Math.round(data.memory_usage_total / 1024);
			memoryUsageChartoption.series[0].data.shift();
			memoryUsageChartoption.series[0].data.push(Math.round(data.memory_usage_used / 1024));
			memoryUsageChartoption.xAxis.data.shift();
			memoryUsageChartoption.xAxis.data.push(axisData);
			memoryUsageChart.setOption(memoryUsageChartoption);
			// Disk
			for (var offset in window.env.disk) {
				// console.log(window.env.disk[offset]);
				// console.log(data.disk[window.env.disk[offset]]);
				// Disk Usage
				var disk_usage_percent = Math.min((data.disk[window.env.disk[offset]].disk_read_active_time + data.disk[window.env.disk[offset]].disk_write_active_time) / 10, 100);
				$("#disk_" + window.env.disk[offset] + "_usage_label").text(disk_usage_percent + "%");
				diskUsageChartoption[window.env.disk[offset]].series[0].data.shift();
				diskUsageChartoption[window.env.disk[offset]].series[0].data.push(disk_usage_percent);
				diskUsageChartoption[window.env.disk[offset]].xAxis.data.shift();
				diskUsageChartoption[window.env.disk[offset]].xAxis.data.push(axisData);
				window.diskUsageChart[window.env.disk[offset]].setOption(diskUsageChartoption[window.env.disk[offset]]);
				// console.log(window.diskUsageChart[window.env.disk[offset]].isDisposed);
				// Disk Speed
				diskSpeedChartoption[window.env.disk[offset]].series[0].data.shift();
				diskSpeedChartoption[window.env.disk[offset]].series[0].data.push(data.disk[window.env.disk[offset]].disk_read_speed);
				diskSpeedChartoption[window.env.disk[offset]].series[1].data.shift();
				diskSpeedChartoption[window.env.disk[offset]].series[1].data.push(-data.disk[window.env.disk[offset]].disk_write_speed);
				diskSpeedChartoption[window.env.disk[offset]].xAxis.data.shift();
				diskSpeedChartoption[window.env.disk[offset]].xAxis.data.push(axisData);
				window.diskSpeedChart[window.env.disk[offset]].setOption(diskSpeedChartoption[window.env.disk[offset]]);
			}
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