$(document).ready(function () {

	$('#MainTab').easyResponsiveTabs({
		type: 'default', //Types: default, vertical, accordion
		width: 'auto', //auto or any width like 600px
		fit: true, // 100% fit in a container
		closed: 'accordion', // Start closed if in accordion view
		tabidentify: 'main', // The tab groups identifier
		activate: function (event) { // Callback function if tab is switched
			console.log(event);
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
		active_content_border_color: '#5AB1D0' // border color for active tabs contect in this group so that it matches the tab head border
	});

	var cpuUsageChart = echarts.init(document.getElementById('cpu_usage'));
	var cpuUsageChartoption = {
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
			min: 0,
			boundaryGap: [0.1, 0.1]
		},
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
	setInterval(function (){
		axisData = (new Date()).toLocaleTimeString().replace(/^\D*/,'');

		var data = cpuUsageChartoption.series[0].data;
		data.shift();
		data.push(Math.round(Math.random() * 100));

		cpuUsageChartoption.xAxis.data.shift();
		cpuUsageChartoption.xAxis.data.push(axisData);

		cpuUsageChart.setOption(cpuUsageChartoption);
	}, 2100);

});