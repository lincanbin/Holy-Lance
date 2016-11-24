<?php

function check_permission($file_name)
{
	$fp = @fopen($file_name, 'w');
	if(!$fp) {
		return false;
	}
	else {
		fclose($fp);
		$rs = @unlink($file_name);
		return true;
	}
}

$file_name = 'disk_speedtest' . md5(time());

if (!check_permission($file_name)) {
?>
	{
		"status": false,
		"message": "chown -R www ./",
		"disk_write_512k" :"",
		"disk_read_512k" :"",
		"disk_write_4k" :"",
		"disk_read_4k" :""
	}
<?php
} else {
	ob_start();
	$disk_write_512k = trim(system('dd if=/dev/zero of=' . $file_name . ' bs=524288 count=512 conv=fdatasync 2>&1 |awk \'/copied/ {print $8 " "  $9}\''));
	$disk_read_512k = trim(system('dd if=' . $file_name . ' of=/dev/null ibs=524288 2>&1 |awk \'/copied/ {print $8 " "  $9}\''));
	unlink($file_name);
	$disk_write_4k = trim(system('dd if=/dev/zero of=' . $file_name . ' bs=4096 count=262144 conv=fdatasync 2>&1 |awk \'/copied/ {print $8 " "  $9}\''));
	$disk_read_4k = trim(system('dd if=' . $file_name . ' of=/dev/null ibs=4096 2>&1 |awk \'/copied/ {print $8 " "  $9}\''));
	unlink($file_name);
	ob_end_clean();
?>
	{
		"status": true,
		"message": "",
		"disk_write_512k" :"<?php echo $disk_write_512k; ?>",
		"disk_read_512k" :"<?php echo $disk_read_512k; ?>",
		"disk_write_4k" :"<?php echo $disk_write_4k; ?>",
		"disk_read_4k" :"<?php echo $disk_read_4k; ?>"
	}
<?php
}
?>