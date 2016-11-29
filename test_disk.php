<?php
set_time_limit(0);
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
	// Write 1 GiB
	$disk_write_512k = trim(system('dd if=/dev/zero of=' . $file_name . ' bs=524288 count=512 conv=fdatasync  oflag=direct,nonblock 2>&1 |awk \'/copied/ {print $8 " "  $9}\''));
	$disk_read_512k = trim(system('dd if=' . $file_name . ' of=/dev/null bs=524288 iflag=direct,nonblock 2>&1 |awk \'/copied/ {print $8 " "  $9}\''));
	unlink($file_name);
	// Write 128 MiB
	$disk_write_4k = trim(system('dd if=/dev/zero of=' . $file_name . ' bs=4096 count=32768 conv=fdatasync oflag=direct,nonblock  2>&1 |awk \'/copied/ {print $8 " "  $9}\''));
	$disk_read_4k = trim(system('dd if=' . $file_name . ' of=/dev/null bs=4096 iflag=direct,nonblock  2>&1 |awk \'/copied/ {print $8 " "  $9}\''));
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