--TEST--
error_handler_sqlite: unwritable database disables recording without breaking error handling
--INI--
error_observer.sqlite=/nonexistent/dir/errors.sqlite
error_reporting=E_ALL
display_errors=1
html_errors=0
log_errors=1
error_log=
--FILE--
<?php
require __DIR__ . '/../handler.php';

trigger_error("first", E_USER_NOTICE);
trigger_error("second", E_USER_NOTICE);
echo "alive\n";
?>
--EXPECTF--
%Serror_handler_sqlite: cannot write to "/nonexistent/dir/errors.sqlite": %s
%A
Notice: first in %s on line 4
%A
Notice: second in %s on line 5
alive
