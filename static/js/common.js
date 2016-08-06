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

function KibibytesToSize(bytes) {
	if (bytes === 0) return '0 B';
	var k = 1024, // or 1000
		sizes = ['KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'],
		i = Math.floor(Math.log(bytes) / Math.log(k));
	return (bytes / Math.pow(k, i)).toPrecision(3) + ' ' + sizes[i];
}

function refreshChart() {
	$.ajax({
		type: "POST",
		url: "api.php",
		data: {
			password: '12345678'
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
			$("#memory_usage_label").text(KibibytesToSize(data.memory_usage_used) + "/" + KibibytesToSize(data.memory_usage_total));
			memoryUsageChartoption.yAxis.max = Math.round(data.memory_usage_total/1024);
			memoryUsageChartoption.series[0].data.shift();
			memoryUsageChartoption.series[0].data.push(Math.round(data.memory_usage_used/1024));
			memoryUsageChartoption.xAxis.data.shift();
			memoryUsageChartoption.xAxis.data.push(axisData);
			memoryUsageChart.setOption(memoryUsageChartoption);
			// Callback
			setTimeout("refreshChart()",2000);
		},
		error: function (data, e) {
			// Callback
			setTimeout("refreshChart()",2000);
		}
	});
}

$(document).ready(function () {
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
		active_content_border_color: '#5AB1D0' // border color for active tabs contect in this group so that it matches the tab head border
	});

	window.cpuUsageChart = echarts.init(document.getElementById('cpu_usage'));
	window.memoryUsageChart = echarts.init(document.getElementById('memory_usage'));

	window.cpuUsageChartoption = {
		title: {},
		tooltip: {},
		xAxis: {
			data: (function (){
					var res = [];
					var len = 1;
					while (len <= 60) {
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
					while (len <= 60) {
						res.push(0);
						len++;
					}
					return res;
				})()
			}
		]
	};

	window.memoryUsageChartoption = {
		title: {},
		tooltip: {},
		xAxis: {
			data: (function (){
					var res = [];
					var len = 1;
					while (len <= 60) {
						res.push((new Date()).toLocaleTimeString().replace(/^\D*/,''));
						len++;
					}
					return res;
				})()
		},
		yAxis: {
			type: 'value',
			name: '内存使用量',
			max: 100,
			min: 0
		},
		color: ['#8B12AE'],
		series: [
			{
				name:'Memory Usage',
				type:'line',
				areaStyle: {normal: {}},
				data:(function (){
					var res = [];
					var len = 1;
					while (len <= 60) {
						res.push(0);
						len++;
					}
					return res;
				})()
			}
		]
	};

	refreshChart();
});