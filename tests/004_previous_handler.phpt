--TEST--
error_handler_sqlite: previous error handler is called and its result is respected
--INI--
error_observer.sqlite=/tmp/test_ehs_004.sqlite
error_reporting=E_ALL
display_errors=1
html_errors=0
--FILE--
<?php
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    echo "previous: $errstr\n";
    return $errstr === 'handled';
});

require __DIR__ . '/../handler.php';

trigger_error("handled", E_USER_NOTICE);
trigger_error("not handled", E_USER_NOTICE);

$db = new SQLite3('/tmp/test_ehs_004.sqlite', SQLITE3_OPEN_READONLY);
var_dump($db->querySingle("SELECT COUNT(*) FROM errors"));
$db->close();
?>
--CLEAN--
<?php @unlink('/tmp/test_ehs_004.sqlite'); @unlink('/tmp/test_ehs_004.sqlite-wal'); @unlink('/tmp/test_ehs_004.sqlite-shm'); ?>
--EXPECTF--
previous: handled
previous: not handled

Notice: not handled in %s on line %d
int(2)
