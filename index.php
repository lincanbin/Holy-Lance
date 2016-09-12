<?php
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
?>

<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	<meta name="robots" content="noarchive">
	<title>Holy Lance</title>
	<link href="static/css/style.css" rel="stylesheet"/>
	<script src="static/js/jquery.min.js" type="text/javascript"></script>
	<script src="static/js/easyResponsiveTabs.js" type="text/javascript"></script>
	<script src="static/js/jquery.jsontotable.min.js" type="text/javascript"></script>
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
				<ul class="resp-tabs-list performance" id="PerformanceList">
					<li>CPU<p><span class="tab-label" id="cpu_usage_label"></span></p></li>
					<li>内存<p><span class="tab-label" id="memory_usage_label"></span></p></li>
					<li>磁盘<p><span class="tab-label" id="disk_usage_label"></span></p></li>
				</ul>
				<div class="resp-tabs-container performance" id="PerformanceContainer">
					<div>
						<div class="chart-title-set">
							<h2 class="chart-title">CPU</h2>
							<span class="chart-sub-title" id="cpu_model_name">Loading</span>
						</div>
						<div id="cpu_usage" style="width: 100%; height:100%; min-height: 460px;"></div>
						<div class="info_block_container">
							<div class="info_block">
								<div class="info">
									<span class="info-label">利用率</span>
									<span class="info-content" id="cpu_usage_info_label">0%</span>
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
							<h2 class="chart-title">内存</h2>
							<span class="chart-sub-title" id="total_memory"></span>
						</div>
						<div id="memory_usage" style="width: 100%; height:100%; min-height: 460px;"></div>
					</div>
					<div>
						<div class="chart-title-set">
							<h2 class="chart-title">磁盘</h2>
							<span class="chart-sub-title" id="disk_size"></span>
						</div>
						<div id="disk_usage" style="width: 100%; height:100%; min-height: 460px;"></div>
						<div id="disk_speed" style="width: 100%; height:100%; min-height: 360px;"></div>
					</div>
				</div>
			</div>
			<p>
			<br /><a href="https://github.com/lincanbin/Holy-Lance" target="_blank">https://github.com/lincanbin/Holy-Lance</a></p>
		</div>
		<div id="Process"></div>
		<div>
			 TODO.<br><br>
			<p>Child 3 Container</p>
		</div>
	</div>
</div>
<script src="static/js/common.js" type="text/javascript"></script>
</body>
</html>