<?php
set_time_limit(0);

if (defined('HAS_BEEN_COMPILED') === false) {
	require __DIR__ . '/common.php';
}
header('Content-type: application/json');
check_password();

$file_name = 'disk_speedtest' . md5(time());

if (!check_permission($file_name)) {
?>
{
	"status": false,
	"message": "chown -R www ./",
	"result": {
		"disk_write_512k" :"×",
		"disk_read_512k" :"×",
		"disk_write_4k" :"×",
		"disk_read_4k" :"×"
	}
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
	"result": {
		"disk_write_512k" :"<?php echo $disk_write_512k; ?>",
		"disk_read_512k" :"<?php echo $disk_read_512k; ?>",
		"disk_write_4k" :"<?php echo $disk_write_4k; ?>",
		"disk_read_4k" :"<?php echo $disk_read_4k; ?>"
	}
}
<?php
}
?>