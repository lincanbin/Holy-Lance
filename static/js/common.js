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
	window.cpuUsageChart.resize();
	window.loadUsageChart.resize();
	window.memoryUsageChart.resize();
	window.connectionUsageChart.resize();
	for (var offset in window.env.network) {
		window.networkUsageChart[window.env.network[offset]].resize();
	}
	for (var i = 0; i < window.env.cpu.length; i++) {
		window.logicCpuUsageChart[i].resize();
	}
	for (var offset in window.env.disk) {
		window.diskUsageChart[window.env.disk[offset]].resize();
		window.diskSpeedChart[window.env.disk[offset]].resize();
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
			'	<div id="disk_' + data.disk[offset] + '_usage" style="width: 100%; height: 460px;"></div>' +
			'	<div id="disk_' + data.disk[offset] + '_speed" style="width: 100%; height: 360px;"></div>' +
			'	<div class="info_block_container">' +
			'		<div class="info_block">' +
			'			<div class="info">' +
			'				<span class="info-label">活动时间</span>' +
			'				<span class="info-content" id="disk_' + data.disk[offset] + '_usage_info">0 %</span>' +
			'			</div>' +
			'			<div class="info">' +
			'				<span class="info-label">平均读取响应时间</span>' +
			'				<span class="info-content" id="disk_' + data.disk[offset] + '_read_active_time">0 毫秒</span>' +
			'			</div>' +
			'			<div class="info">' +
			'				<span class="info-label">平均写入响应时间</span>' +
			'				<span class="info-content" id="disk_' + data.disk[offset] + '_write_active_time">0 毫秒</span>' +
			'			</div>' +
			'			<div class="info-clear"></div>' +
			'			<div class="info">' +
			'				<span class="info-label">总读取字节</span>' +
			'				<span class="info-content" id="disk_' + data.disk[offset] + '_read_kibibytes">0 KiB</span>' +
			'			</div>' +
			'			<div class="info">' +
			'				<span class="info-label">总写入字节</span>' +
			'				<span class="info-content" id="disk_' + data.disk[offset] + '_write_kibibytes">0 KiB</span>' +
			'			</div>' +
			'			<div class="info-clear"></div>' +
			'			<div class="info">' +
			'				<span class="info-label">读取速度</span>' +
			'				<span class="info-content" id="disk_' + data.disk[offset] + '_read_speed">0 KiB / 秒</span>' +
			'			</div>' +
			'			<div class="info">' +
			'				<span class="info-label">写入速度</span>' +
			'				<span class="info-content" id="disk_' + data.disk[offset] + '_write_speed">0 KiB / 秒</span>' +
			'			</div>' +
			'		</div>' +
			'	</div>' +
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
			'	<div class="info_block_container">' +
			'		<div class="info_block">' +
			'			<div class="info">' +
			'				<span class="info-label">发送速率</span>' +
			'				<span class="info-content" id="eth_' + data.network[offset] + '_transmit_speed">0 KiB / 秒</span>' +
			'			</div>' +
			'			<div class="info">' +
			'				<span class="info-label">接收速率</span>' +
			'				<span class="info-content" id="eth_' + data.network[offset] + '_receive_speed">0 KiB / 秒</span>' +
			'			</div>' +
			'			<div class="info-clear"></div>' +
			'			<div class="info">' +
			'				<span class="info-label">已发送字节</span>' +
			'				<span class="info-content" id="eth_' + data.network[offset] + '_transmit_bytes">0 KiB</span>' +
			'			</div>' +
			'			<div class="info">' +
			'				<span class="info-label">已接收字节</span>' +
			'				<span class="info-content" id="eth_' + data.network[offset] + '_receive_bytes">0 KiB</span>' +
			'			</div>' +
			'			<div class="info-clear"></div>' +
			'			<div class="info">' +
			'				<span class="info-label">已发送包</span>' +
			'				<span class="info-content" id="eth_' + data.network[offset] + '_transmit_packets">0</span>' +
			'			</div>' +
			'			<div class="info">' +
			'				<span class="info-label">已接收包</span>' +
			'				<span class="info-content" id="eth_' + data.network[offset] + '_receive_packets">0</span>' +
			'			</div>' +
			'		</div>' +
			'		<div class="info_block" id="eth_' + data.network[offset] + '_info">';
		for (var offset2 in data.network_info[data.network[offset]].ip) {
			var ip = data.network_info[data.network[offset]].ip[offset2];
			var ip_version = ip.indexOf(":") !== -1 ? "6" : "4";
			temp += '' +
				'			<div class="info-inline">' +
				'				<span class="info-inline-label">IPV' + ip_version + ' 地址:</span>' +
				'				<span class="info-inline-content">' + ip + '</span>' +
				'			</div>';
		}
		temp += '' +
			'		</div>' +
			'	</div>' +
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
	if ($("#Process").is(":visible")) {
		drawProcessTable(data.process, true);
	}
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