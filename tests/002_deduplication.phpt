--TEST--
error_handler_sqlite: identical errors are deduplicated by fingerprint
--INI--
error_observer.sqlite=/tmp/test_ehs_002.sqlite
error_reporting=E_ALL
display_errors=0
--FILE--
<?php
require __DIR__ . '/../handler.php';

function trigger_warning(): void {
    trigger_error("test warning", E_USER_WARNING);
}

for ($i = 0; $i < 2; $i++) {
    trigger_warning();
}
trigger_warning();

$db = new SQLite3('/tmp/test_ehs_002.sqlite', SQLITE3_OPEN_READONLY);
var_dump($db->querySingle("SELECT COUNT(*) FROM errors"));
var_dump($db->querySingle("SELECT GROUP_CONCAT(count) FROM errors ORDER BY id"));
$db->close();
?>
--CLEAN--
<?php @unlink('/tmp/test_ehs_002.sqlite'); @unlink('/tmp/test_ehs_002.sqlite-wal'); @unlink('/tmp/test_ehs_002.sqlite-shm'); ?>
--EXPECT--
int(2)
string(3) "2,1"
