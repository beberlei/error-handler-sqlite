--TEST--
dispatch_top10_deprecations: builds repository_dispatch payload from top deprecations
--INI--
error_observer.sqlite=/tmp/test_ehs_007.sqlite
error_reporting=E_ALL
display_errors=0
--FILE--
<?php
require __DIR__ . '/../handler.php';

function deprecated_call(string $message): void {
    trigger_error($message, E_USER_DEPRECATED);
}

for ($i = 0; $i < 3; $i++) {
    deprecated_call('frequent deprecation in ' . __DIR__ . '/');
}
for ($i = 0; $i < 12; $i++) {
    deprecated_call('rare deprecation ' . $i);
}
trigger_error('not a deprecation', E_USER_WARNING);

$command = sprintf(
    '%s %s --dry-run --limit=2 --strip-prefix=%s %s owner/repo 2>&1',
    escapeshellarg(PHP_BINARY),
    escapeshellarg(__DIR__ . '/../dispatch_top10_deprecations.php'),
    escapeshellarg(__DIR__ . '/'),
    escapeshellarg('/tmp/test_ehs_007.sqlite'),
);
$payload = json_decode(shell_exec($command), true, flags: JSON_THROW_ON_ERROR);

var_dump($payload['event_type']);
var_dump(array_keys($payload['client_payload']));
$deprecations = $payload['client_payload']['deprecations'];
var_dump(count($deprecations));
var_dump($deprecations[0]['type'], $deprecations[0]['message'], $deprecations[0]['file'], $deprecations[0]['count']);
var_dump($deprecations[1]['count']);
echo $deprecations[0]['stacktrace'];

echo shell_exec(sprintf('%s %s %s owner/repo 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/../dispatch_top10_deprecations.php'), escapeshellarg('/tmp/test_ehs_007.sqlite')));
echo shell_exec(sprintf('%s %s --dry-run /tmp/test_ehs_007.sqlite invalid 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/../dispatch_top10_deprecations.php')));
?>
--CLEAN--
<?php @unlink('/tmp/test_ehs_007.sqlite'); @unlink('/tmp/test_ehs_007.sqlite-wal'); @unlink('/tmp/test_ehs_007.sqlite-shm'); ?>
--ENV--
GITHUB_TOKEN=
--EXPECTF--
string(18) "top10_deprecations"
array(3) {
  [0]=>
  string(12) "generated_at"
  [1]=>
  string(5) "since"
  [2]=>
  string(12) "deprecations"
}
int(2)
string(17) "E_USER_DEPRECATED"
string(24) "frequent deprecation in "
string(24) "007_dispatch_payload.php"
int(3)
int(1)
#0 007_dispatch_payload.php(5): trigger_error()
#1 007_dispatch_payload.php(9): deprecated_call()
#2 {main}
GITHUB_TOKEN environment variable is required.
Usage: %s
