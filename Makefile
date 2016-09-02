<?php
define("BUILD_FILE_NAME", "tz.php");
define("ENTER_FILE_NAME", "index.php");
$file_buffer = [];
$ignore_dir = [".git"];
$build_extension = ["php", "css", "js"];

function add_file_to_buffer($file_name, $old_file_name, $new_file_name)
{
	global $file_buffer, $build_extension;
	if (in_array(end(explode('.', $file_name)), $build_extension)){
		$file_buffer[$old_file_name] = str_replace($old_file_name, $new_file_name, file_get_contents($file_name));
	}
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
$fp = fopen("./build/" . BUILD_FILE_NAME, 'w');
$entry_file = !empty($file_buffer[ENTER_FILE_NAME]) ? $file_buffer[ENTER_FILE_NAME] : '';
if (!$entry_file) {
	echo 'Entry file not found! ';
	exit(1);
}
unset($file_buffer[ENTER_FILE_NAME]);
foreach ($file_buffer as $file_name => $content) {
	fwrite($fp, '<?php
if (!empty($_POST["file"]) && $_POST["file"] == "' . $file_name . '"):
?>');
	fwrite($fp, $content);
	fwrite($fp, '<?php
exit();
endif;
?>');
}
fwrite($fp, $entry_file);
fclose($fp);