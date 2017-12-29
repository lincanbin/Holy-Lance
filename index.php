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
if (!function_exists("exec") || !function_exists("shell_exec")) {
	exit("请启用exec()和shell_exec()函数，即禁用安全模式(safe_mode)");
}

if (defined('HAS_BEEN_COMPILED') === false) {
    require __DIR__ . '/common.php';
}
?>

<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	<meta name="robots" content="noarchive">
	<title>Holy Lance v1.3.0</title>
	<link href="static/css/style.css" rel="stylesheet"/>
	<script src="static/js/jquery.min.js" type="text/javascript"></script>
	<script src="static/js/easyResponsiveTabs.js" type="text/javascript"></script>
	<script src="static/js/jquery.jsontotable.min.js" type="text/javascript"></script>
	<script src="static/js/echarts.min.js" type="text/javascript"></script>
</head>

<body style="display: none;">
<!--Horizontal Tab-->
<div id="MainTab">
	<ul class="resp-tabs-list main">
		<li>性能</li>
		<li>进程</li>
		<li>环境</li>
		<li>测试</li>
		<li>关于</li>
	</ul>
	<div class="resp-tabs-container main">
		<div>
			<!--vertical Tabs-->
			<div id="PerformanceTab">
				<ul class="resp-tabs-list performance" id="PerformanceList">
					<li>CPU<p><span class="tab-label" id="cpu_usage_label"></span></p></li>
					<li>逻辑处理器<p><span class="tab-label" id="logic_cpu_usage_label"></span></p></li>
					<li>系统负载<p><span class="tab-label" id="load_usage_label"></span></p></li>
					<li>内存<p><span class="tab-label" id="memory_usage_label"></span></p></li>
					<li>网络连接数<p><span class="tab-label" id="connection_usage_label"></span></p></li>
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
							<h2 class="chart-title">CPU</h2>
							<span class="chart-sub-title" id="logic_cpu_model_name">Loading</span>
						</div>
						<div id="logic_cpu_usage_container" class="chart-title-set" style="height: 640px;"></div>
					</div>

					<div>
						<div class="chart-title-set">
							<h2 class="chart-title">系统负载</h2>
						</div>
						<div id="load_usage" style="width: 100%; height: 960px;"></div>
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

					<div>
						<div class="chart-title-set">
							<h2 class="chart-title">网络连接数</h2>
						</div>
						<div id="connection_usage" style="width: 100%; height: 460px;"></div>
						<div class="info_block_container">
							<div class="info_block">
								<div class="info">
									<span class="info-label">ESTABLISHED</span>
									<span class="info-content" id="connection_ESTABLISHED_usage_info">0</span>
								</div>
								<div class="info">
									<span class="info-label">SYN_SENT</span>
									<span class="info-content" id="connection_SYN_SENT_usage_info">0</span>
								</div>
								<div class="info">
									<span class="info-label">SYN_RECV</span>
									<span class="info-content" id="connection_SYN_RECV_usage_info">0</span>
								</div>
								<div class="info">
									<span class="info-label">FIN_WAIT1</span>
									<span class="info-content" id="connection_FIN_WAIT1_usage_info">0</span>
								</div>
								<div class="info-clear"></div>
								<div class="info">
									<span class="info-label">FIN_WAIT2</span>
									<span class="info-content" id="connection_FIN_WAIT2_usage_info">0</span>
								</div>
								<div class="info">
									<span class="info-label">TIME_WAIT</span>
									<span class="info-content" id="connection_TIME_WAIT_usage_info">0</span>
								</div>
								<div class="info">
									<span class="info-label">CLOSE</span>
									<span class="info-content" id="connection_CLOSE_usage_info">0</span>
								</div>
								<div class="info">
									<span class="info-label">CLOSE_WAIT</span>
									<span class="info-content" id="connection_CLOSE_WAIT_usage_info">0</span>
								</div>
								<div class="info-clear"></div>
								<div class="info">
									<span class="info-label">LAST_ACK</span>
									<span class="info-content" id="connection_LAST_ACK_usage_info">0</span>
								</div>
								<div class="info">
									<span class="info-label">LISTEN</span>
									<span class="info-content" id="connection_LISTEN_usage_info">0</span>
								</div>
								<div class="info">
									<span class="info-label">CLOSING</span>
									<span class="info-content" id="connection_CLOSING_usage_info">0</span>
								</div>
								<div class="info">
									<span class="info-label">UNKNOWN</span>
									<span class="info-content" id="connection_UNKNOWN_usage_info">0</span>
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
						<span class="info-label">发行版信息</span>
						<span class="info-content" id="system_name"></span>
					</div>
					<div class="info">
						<span class="info-label">系统版本</span>
						<span class="info-content"><?php echo php_uname('r'); ?></span>
					</div>
					<div class="info">
						<span class="info-label">系统语言</span>
						<span class="info-content"><?php echo !empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : ''; ?></span>
					</div>
					<div class="info-clear"></div>

                    <div class="info">
                        <span class="info-label">服务器解析引擎</span>
                        <span class="info-content"><?php echo !empty($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : ''; ?></span>
                    </div>
					<div class="info">
						<span class="info-label">PHP版本</span>
						<span class="info-content"><?php echo phpversion(); ?></span>
					</div>
					<div class="info">
						<span class="info-label">Zend引擎版本</span>
						<span class="info-content"><?php echo zend_version(); ?></span>
					</div>
                    <div class="info-clear"></div>

                    <?php
                    if (function_exists('opcache_get_status')):
                        $opcache_status_info = opcache_get_status();
                        $opcache_configuration = opcache_get_configuration();
                        if (!empty($opcache_status_info['opcache_statistics']['start_time'])) {
                            $opcache_uptime_second = $_SERVER['REQUEST_TIME'] - $opcache_status_info['opcache_statistics']['start_time'];
                            $opcache_start_uptime = convert_timestamp_2_string($opcache_uptime_second);
                        }
                    ?>
                        <div class="info">
                            <span class="info-label">OPCache状态</span>
                            <span class="info-content"><?php echo convert_boolean($opcache_status_info['opcache_enabled']); ?></span>
                        </div>
                        <?php
                        if (!empty($opcache_start_uptime)):
                        ?>
                            <div class="info">
                                <span class="info-label">OPCache运行时间</span>
                                <span class="info-content"><?php echo $opcache_start_uptime; ?></span>
                            </div>
                        <?php
                        endif;
                        ?>
                        <?php
                        if ($opcache_status_info['opcache_statistics']['hits'] > 0):
                            ?>
                            <div class="info">
                                <span class="info-label">OPCache命中率</span>
                                <span class="info-content">
                                    <?php echo round(
                                        $opcache_status_info['opcache_statistics']['hits'] * 100
                                        / ($opcache_status_info['opcache_statistics']['hits'] + $opcache_status_info['opcache_statistics']['misses'] + $opcache_status_info['opcache_statistics']['blacklist_misses'])
                                        ,4); ?>%
                                </span>
                            </div>
                            <div class="info">
                                <span class="info-label">OPCache命中次数</span>
                                <span class="info-content">
                                    <?php echo format_number($opcache_status_info['opcache_statistics']['hits']); ?>
                                </span>
                            </div>
                            <div class="info">
                                <span class="info-label">OPCache缓存脚本数</span>
                                <span class="info-content">
                                    <?php echo format_number($opcache_status_info['opcache_statistics']['num_cached_scripts']); ?>
                                    &nbsp;/&nbsp;
									<?php echo format_number($opcache_status_info['opcache_statistics']['max_cached_keys']); ?>
                                </span>
                            </div>
                            <?php
                        endif;
                        ?>
                        <?php
                        if (!empty($opcache_status_info['memory_usage']['free_memory']) && !empty($opcache_configuration['directives']['opcache.memory_consumption'])):
                            ?>
                            <div class="info">
                                <span class="info-label">OPCache内存占用</span>
                                <span class="info-content">
                                    <?php echo format_bytes(
                                            $opcache_configuration['directives']['opcache.memory_consumption'] - $opcache_status_info['memory_usage']['free_memory']
                                    ); ?>
                                    &nbsp;/&nbsp;
                                    <?php echo format_bytes($opcache_configuration['directives']['opcache.memory_consumption']); ?>
                                </span>
                            </div>
                            <?php
                        endif;
                        ?>
                        <div class="info-clear"></div>
                    <?php
                    endif;
                    ?>

					<div class="info">
						<span class="info-label">脚本最大占用内存</span>
						<span class="info-content"><?php echo get_config_value('memory_limit'); ?></span>
					</div>
					<div class="info">
						<span class="info-label">脚本超时时间</span>
						<span class="info-content"><?php echo get_config_value('max_execution_time'); ?>秒</span>
					</div>
					<div class="info">
						<span class="info-label">socket超时时间</span>
						<span class="info-content"><?php echo get_config_value('default_socket_timeout'); ?>秒</span>
					</div>
					<div class="info-clear"></div>

					<div class="info-clear"></div>
					<div class="info">
						<span class="info-label">允许的最大POST数据</span>
						<span class="info-content"><?php echo get_config_value('post_max_size'); ?></span>
					</div>
					<div class="info">
						<span class="info-label">上传文件大小限制</span>
						<span class="info-content"><?php echo get_config_value('upload_max_filesize'); ?></span>
					</div>
					<div class="info-clear"></div>

					<div class="info">
						<span class="info-label">服务器接口类型</span>
						<span class="info-content"><?php echo php_sapi_name(); ?></span>
					</div>
					<div class="info">
						<span class="info-label">服务器IP</span>
						<span class="info-content"><?php echo GetHostByName(!empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost'); ?></span>
					</div>
					<div class="info">
						<span class="info-label">服务器端口</span>
						<span class="info-content"><?php echo !empty($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : ''; ?></span>
					</div>
					<div class="info-clear"></div>

				<?php foreach(get_loaded_extensions() as $extension): ?>
					<div class="info">
						<span class="info-label">已编译扩展: </span>
						<span class="info-content"><?php echo $extension; ?></span>
					</div>
				<?php endforeach; ?>
					<div class="info-clear"></div>

				<?php
				$disable_functions = get_cfg_var("disable_functions");
				if (!empty($disable_functions)):
					foreach(explode(',', $disable_functions) as $disable_function): ?>
						<div class="info">
							<span class="info-label">已禁用函数: </span>
							<span class="info-content"><?php echo $disable_function; ?></span>
						</div>
					<?php
					endforeach;
				endif;
				?>
					<div class="info-clear"></div>

				</div>
				<div class="info_block">

				</div>
			</div>

		</div>
        <div>
            <div class="info_block_container">
                <div class="info_block">

                    <div class="info">
                        <span class="info-label">磁盘连续读取速度</span>
                        <span class="info-content" id="disk_read_512k"><a href="javascript:" onclick="diskTest()">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">磁盘连续写入速度</span>
                        <span class="info-content" id="disk_write_512k"><a href="javascript:" onclick="diskTest()">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">磁盘4k读取速度</span>
                        <span class="info-content" id="disk_read_4k"><a href="javascript:" onclick="diskTest()">Run</a></span>
                    </div>
                    <div class="info">
                        <span class="info-label">磁盘4k写入速度</span>
                        <span class="info-content" id="disk_write_4k"><a href="javascript:" onclick="diskTest()">Run</a></span>
                    </div>

                    <div class="info-clear"></div>

                    <div class="info">
                        <span class="info-label">Ping Baidu</span>
                        <span class="info-content"><a href="javascript:" onclick="pingTest(this,'111.13.101.208')">Run</a></span>
                    </div>

                    <div class="info-clear"></div>
                </div>
                <div class="info_block">

                </div>
            </div>

        </div>
		<div>
			<div class="info_block_container">
				<p>
                    <pre>
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

				    </pre>
                </p>
				<p>
				GitHub地址：<a href="https://github.com/lincanbin/Holy-Lance" target="_blank">https://github.com/lincanbin/Holy-Lance</a>
				</p>
				<p>

				</p>
			</div>
		</div>
	</div>
</div>
<script>
    var passwordRequired = <?php echo HOLY_LANCE_PASSWORD === '' ? 'false' : 'true'; ?>;
</script>
<script src="static/js/common.js" type="text/javascript"></script>
</body>
</html>