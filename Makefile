<?php
define("BUILD_FILE_NAME", "tz.php");
$file_buffer = [];
$ignore_dir = [".git"];

function add_file_to_buffer($file_name, $old_file_name, $new_file_name)
{
	global $file_buffer;
	$file_buffer[] = str_replace($old_file_name, $new_file_name, file_get_contents($file_name));
}

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
						$file_name = $dir . $file;
						$old_file_name = str_replace("./", "", $file_name);
						$new_file_name = BUILD_FILE_NAME . "?file=" . urlencode($old_file_name);
						add_file_to_buffer($file_name, $old_file_name, $new_file_name);
						echo $old_file_name . "\n";
					}
				}
			}
			closedir($dh);
		}
	}
}

//开始运行
if (!is_dir("./build")) {
	mkdir("./build");
}
listDir("./", $ignore_dir);