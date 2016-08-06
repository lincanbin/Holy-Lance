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
	<script src="static/js/jquery.min.js"></script>
	<script src="static/js/easyResponsiveTabs.js" type="text/javascript"></script>
	<script src="static/js/echarts.min.js"></script>
<!-- 	<script src="http://webthemez.com/demo/easy-responsive-tabs/js/jquery-1.9.1.min.js"></script>
		<script src="http://webthemez.com/demo/easy-responsive-tabs/js/easyResponsiveTabs.js"></script>
-->
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
						<p>Suspendisse blandit velit Integer laoreet placerat suscipit. Sed sodales scelerisque commodo. Nam porta cursus lectus. Proin nunc erat, gravida a facilisis quis, ornare id lectus. Proin consectetur nibh quis Integer laoreet placerat suscipit. Sed sodales scelerisque commodo. Nam porta cursus lectus. Proin nunc erat, gravida a facilisis quis, ornare id lectus. Proin consectetur nibh quis urna gravid urna gravid eget erat suscipit in malesuada odio venenatis.</p>
					</div>
				</div>
			</div>
			</p>
			<p>https://github.com/lincanbin/Carbon-Probe</p>
		</div>
		<div>
			 Lorem ipsum dolor sit amet, consectetur adipiscing elit. Vestibulum nibh urna, euismod ut ornare non, volutpat vel tortor. Integer laoreet placerat suscipit. Sed sodales scelerisque commodo. Nam porta cursus lectus. Proin nunc erat, gravida a facilisis quis, ornare id lectus. Proin consectetur nibh quis urna gravida mollis.<br><br>
			<p>Child 2 Container</p>
		</div>
		<div>
			 Lorem ipsum dolor sit amet, consectetur adipiscing elit. Vestibulum nibh urna, euismod ut ornare non, volutpat vel tortor. Integer laoreet placerat suscipit. Sed sodales scelerisque commodo. Nam porta cursus lectus. Proin nunc erat, gravida a facilisis quis, ornare id lectus. Proin consectetur nibh quis urna gravida mollis.<br><br>
			<p>Child 3 Container</p>
		</div>
	</div>
</div>
</body>
<script src="static/js/common.js"></script>
</html>