--TEST--
error_handler_sqlite: errors are handled normally when no database is configured
--INI--
error_observer.sqlite=
error_reporting=E_ALL
--FILE--
<?php
require __DIR__ . '/../handler.php';

var_dump(set_error_handler(null));

set_error_handler(function(int $errno, string $errstr): bool {
    echo "caught\n";
    return true;
});
trigger_error("test warning", E_USER_WARNING);
restore_error_handler();
echo "alive\n";
?>
--EXPECT--
NULL
caught
alive
