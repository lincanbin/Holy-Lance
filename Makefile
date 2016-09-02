<?php
function listDir($dir, $ignore_dir)
{
	if (is_dir($dir)) {
		if ($dh = opendir($dir)) {
			while (($file = readdir($dh)) !== false) {
				if ((is_dir($dir . "/" . $file)) && $file != "." && $file != "..") {
					if (!in_array($file, $ignore_dir)) {
						// echo $file . "\n";
						listDir($dir . $file . "/", $ignore_dir);
					}
				} else {
					if ($file != "." && $file != "..") {
						echo str_replace("./", "", $dir . $file) . "\n";
					}
				}
			}
			closedir($dh);
		}
	}
}
$ignore_dir = [".git"];
//开始运行
listDir("./", $ignore_dir);