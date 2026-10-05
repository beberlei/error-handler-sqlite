--TEST--
error_handler_sqlite: warning is stored in the database
--INI--
error_observer.sqlite=/tmp/test_ehs_001.sqlite
error_reporting=E_ALL
display_errors=0
--FILE--
<?php
require __DIR__ . '/../handler.php';

trigger_error("test warning", E_USER_WARNING);

$db = new SQLite3('/tmp/test_ehs_001.sqlite', SQLITE3_OPEN_READONLY);
$row = $db->querySingle("SELECT type, message, file, line, stacktrace, count FROM errors", true);
$db->close();

var_dump($row['type'] === E_USER_WARNING);
var_dump($row['message']);
var_dump($row['file'] === __FILE__);
var_dump($row['line']);
var_dump($row['count']);
echo $row['stacktrace'];
?>
--CLEAN--
<?php @unlink('/tmp/test_ehs_001.sqlite'); @unlink('/tmp/test_ehs_001.sqlite-wal'); @unlink('/tmp/test_ehs_001.sqlite-shm'); ?>
--EXPECTF--
bool(true)
string(12) "test warning"
bool(true)
int(4)
int(1)
#0 %s001_basic_warning.php(4): trigger_error()
#1 {main}
