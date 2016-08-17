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
?>

<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	<meta name="robots" content="noarchive">
	<title>Carbon Probe</title>
	<link href="static/css/style.css" rel="stylesheet"/>
	<script src="static/js/jquery.min.js" type="text/javascript"></script>
	<script src="static/js/easyResponsiveTabs.js" type="text/javascript"></script>
	<script src="static/js/echarts.min.js" type="text/javascript"></script>
</head>

<body>
<!--Horizontal Tab-->
<div id="MainTab">
	<ul class="resp-tabs-list main">
		<li>性能</li>
		<li>进程</li>
		<li>环境</li>
	</ul>
	<div class="resp-tabs-container main">
		<div>
			<p>
			<!--vertical Tabs-->
			<div id="PerformanceTab">
				<ul class="resp-tabs-list performance">
					<li>CPU<p><span class="tab-label" id="cpu_usage_label"></span></p></li>
					<li>内存<p><span class="tab-label" id="memory_usage_label"></span></p></li>
					<li>磁盘<p><span class="tab-label" id="disk_usage_label"></span></p></li>
					<li>网络</li>
				</ul>
				<div class="resp-tabs-container performance">
					<div>
						<div id="cpu_usage" style="width: 100%; height:100%; min-height: 460px;"></div>
					</div>
					<div>

						<div id="memory_usage" style="width: 100%; height:100%; min-height: 460px;"></div>
					</div>
					<div>

						<div id="disk_usage" style="width: 100%; height:100%; min-height: 460px;"></div>
					</div>
					<div>
						<p>TODO.</p>
					</div>
				</div>
			</div>
			<p>https://github.com/lincanbin/Carbon-Probe</p>
		</div>
		<div>
			 TODO.<br><br>
			<p>Child 2 Container</p>
		</div>
		<div>
			 TODO.<br><br>
			<p>Child 3 Container</p>
		</div>
	</div>
</div>
<script src="static/js/common.js" type="text/javascript"></script>
</body>
</html>