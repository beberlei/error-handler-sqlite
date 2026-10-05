--TEST--
error_handler_sqlite: fatal errors are stored on shutdown
--INI--
error_observer.sqlite=/tmp/test_ehs_005.sqlite
error_reporting=E_ALL
display_errors=0
--FILE--
<?php
require __DIR__ . '/../handler.php';

register_shutdown_function(function (): void {
    $db = new SQLite3('/tmp/test_ehs_005.sqlite', SQLITE3_OPEN_READONLY);
    $row = $db->querySingle("SELECT type, message FROM errors", true);
    $db->close();

    var_dump($row['type'] === E_ERROR);
    var_dump(str_starts_with($row['message'], 'Uncaught RuntimeException: boom'));
});

throw new RuntimeException('boom');
?>
--CLEAN--
<?php @unlink('/tmp/test_ehs_005.sqlite'); @unlink('/tmp/test_ehs_005.sqlite-wal'); @unlink('/tmp/test_ehs_005.sqlite-shm'); ?>
--EXPECT--
bool(true)
bool(true)
