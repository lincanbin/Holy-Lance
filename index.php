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
		<li>关于</li>
	</ul>
	<div class="resp-tabs-container main">
		<div>
			<p>
			<!--vertical Tabs-->
			<div id="PerformanceTab">
				<ul class="resp-tabs-list performance" id="PerformanceList">
					<li>CPU<p><span class="tab-label" id="cpu_usage_label"></span></p></li>
					<li>内存<p><span class="tab-label" id="memory_usage_label"></span></p></li>
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
				</div>
			</div>
			
		</div>
		<div id="Process">
		</div>
		<div>
			<div class="info_block_container">
				<div class="info_block">
					<div class="info">
						<span class="info-label">系统类型</span>
						<span class="info-content"><?php echo php_uname('s'); ?></span>
					</div>
					<div class="info">
						<span class="info-label">系统版本</span>
						<span class="info-content"><?php echo php_uname('r'); ?></span>
					</div>
					<div class="info">
						<span class="info-label">系统语言</span>
						<span class="info-content"><?php echo $_SERVER['HTTP_ACCEPT_LANGUAGE']; ?></span>
					</div>
					<div class="info-clear"></div>

					<div class="info">
						<span class="info-label">PHP版本</span>
						<span class="info-content"><?php echo phpversion(); ?></span>
					</div>
					<div class="info">
						<span class="info-label">MySQL客户端版本</span>
						<span class="info-content"><?php echo mysqli_get_client_version(); ?></span>
					</div>
					<div class="info">
						<span class="info-label">Zend引擎版本</span>
						<span class="info-content"><?php echo zend_version(); ?></span>
					</div>
					<div class="info">
						<span class="info-label">服务器解析引擎</span>
						<span class="info-content"><?php echo $_SERVER['SERVER_SOFTWARE']; ?></span>
					</div>
					<div class="info-clear"></div>

					<div class="info">
						<span class="info-label">服务器接口类型</span>
						<span class="info-content"><?php echo php_sapi_name(); ?></span>
					</div>
					<div class="info">
						<span class="info-label">服务器IP</span>
						<span class="info-content"><?php echo GetHostByName($_SERVER['SERVER_NAME']); ?></span>
					</div>
					<div class="info">
						<span class="info-label">服务器端口</span>
						<span class="info-content"><?php echo $_SERVER['SERVER_PORT']; ?></span>
					</div>
				
					<div class="info-clear"></div>
				<?php foreach(get_loaded_extensions() as $extension): ?>
					<div class="info">
						<span class="info-label">已编译扩展: </span>
						<span class="info-content" id="cpu_max_frequency"><?php echo $extension; ?></span>
					</div>
				<?php endforeach; ?>
				</div>
				<div class="info_block">

				</div>
			</div>

		</div>
		<div>
			<div class="info_block_container">
				<p><pre>
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

				</pre></p>
				<p>
				GitHub地址：<a href="https://github.com/lincanbin/Holy-Lance" target="_blank">https://github.com/lincanbin/Holy-Lance</a>
				</p>
			</div>
		</div>
	</div>
</div>
<script src="static/js/common.js" type="text/javascript"></script>
</body>
</html>