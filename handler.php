<?php

/**
 * PHP port of ext-error-observer-sqlite.
 *
 * Stores errors, notices, warnings and deprecations in an SQLite database.
 * Identical errors (same type + message + call stack) are deduplicated: a
 * second occurrence only increments a counter.
 *
 * Activation, for example with auto_prepend_file:
 *
 *   auto_prepend_file=/path/to/handler.php
 *   error_observer.sqlite=/var/log/php-errors.sqlite
 *
 * or the environment variable ERROR_OBSERVER_SQLITE. Without a path nothing
 * is registered. Alternatively include the file and call
 * SqliteErrorHandler::register($path) yourself.
 *
 * Differences to the C extension, which observes errors outside of the
 * error handler chain:
 *
 * - An error handler that is registered later without chaining to the
 *   previous handler disables recording.
 * - The previous handler is called for all error types, because PHP does
 *   not expose the error level mask it was registered with.
 * - Fatal errors that never reach error handlers (E_ERROR, E_PARSE, ...)
 *   are recorded at shutdown from error_get_last(), without a stack trace.
 * - Fingerprints are computed from debug_backtrace() frames and are not
 *   identical to the fingerprints of the C extension.
 */

namespace Tideways\ErrorHandlerSqlite;

final class SqliteErrorHandler
{
    private const int UNHANDLEABLE_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING;

    private const string SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS errors (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            fingerprint TEXT    NOT NULL UNIQUE,
            type        INTEGER NOT NULL,
            message     TEXT    NOT NULL,
            file        TEXT    NOT NULL,
            line        INTEGER NOT NULL,
            stacktrace  TEXT    NOT NULL DEFAULT '',
            first_seen  INTEGER NOT NULL,
            last_seen   INTEGER NOT NULL,
            count       INTEGER NOT NULL DEFAULT 1
        );
        CREATE INDEX IF NOT EXISTS idx_errors_last_seen ON errors (last_seen);
        CREATE INDEX IF NOT EXISTS idx_errors_type      ON errors (type);
        SQL;

    // The stacktrace of the first occurrence is kept on conflict, so the original call site is preserved.
    private const string UPSERT = <<<'SQL'
        INSERT INTO errors (fingerprint, type, message, file, line, stacktrace, first_seen, last_seen, count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON CONFLICT(fingerprint) DO UPDATE SET last_seen = excluded.last_seen, count = count + 1
        SQL;

    private static ?self $instance = null;

    private ?\PDO $db = null;
    private ?\PDOStatement $statement = null;
    private bool $disabled = false;
    private bool $recording = false;

    /** @var callable|null */
    private $previousHandler = null;

    private function __construct(private readonly string $path)
    {
    }

    public static function register(string $path): ?self
    {
        if (strlen($path) === 0 || self::$instance !== null) {
            return self::$instance;
        }

        $handler = new self($path);
        $handler->previousHandler = set_error_handler($handler->handleError(...));
        register_shutdown_function($handler->handleShutdown(...));

        return self::$instance = $handler;
    }

    public function handleError(int $type, string $message, string $file, int $line): bool
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        array_shift($trace);

        $this->record($type, $message, $file, $line, $trace);

        if ($this->previousHandler !== null) {
            return (bool) ($this->previousHandler)($type, $message, $file, $line);
        }

        return false;
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && ($error['type'] & self::UNHANDLEABLE_ERRORS) !== 0) {
            $this->record($error['type'], $error['message'], $error['file'], $error['line'], []);
        }
    }

    /** @param list<array{function?: string, class?: string, type?: string, file?: string, line?: int}> $frames */
    private function record(int $type, string $message, string $file, int $line, array $frames): void
    {
        if ($this->disabled || $this->recording) {
            return;
        }

        $this->recording = true;

        try {
            $statement = $this->statement ??= $this->connect()->prepare(self::UPSERT);

            [$stacktrace, $fingerprint] = $this->buildTrace($type, $message, $file, $line, $frames);
            $now = time();

            $statement->execute([$fingerprint, $type, $message, $file, $line, $stacktrace, $now, $now]);
        } catch (\Throwable $e) {
            $this->disabled = true;
            error_log(sprintf('error_handler_sqlite: cannot write to "%s": %s', $this->path, $e->getMessage()));
        } finally {
            $this->recording = false;
        }
    }

    private function connect(): \PDO
    {
        $this->db = new \PDO('sqlite:' . $this->path, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 5,
        ]);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');
        $this->db->exec(self::SCHEMA);

        return $this->db;
    }

    /**
     * @param list<array{function?: string, class?: string, type?: string, file?: string, line?: int}> $frames
     * @return array{string, string}
     */
    private function buildTrace(int $type, string $message, string $file, int $line, array $frames): array
    {
        $hash = hash_init('fnv1a64');
        hash_update($hash, pack('V', $type));
        hash_update($hash, $message);
        hash_update($hash, $file . pack('V', $line));

        $trace = '';
        foreach ($frames as $number => $frame) {
            if (isset($frame['file'])) {
                $location = sprintf('%s(%d): ', $frame['file'], $frame['line'] ?? 0);
                hash_update($hash, $frame['file'] . pack('V', $frame['line'] ?? 0));
            } else {
                $location = '[internal]: ';
            }

            $trace .= sprintf(
                "#%d %s%s%s%s()\n",
                $number,
                $location,
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? '',
            );
        }
        $trace .= sprintf("#%d {main}\n", count($frames));

        return [$trace, hash_final($hash)];
    }
}

$path = getenv('ERROR_OBSERVER_SQLITE');
if ($path === false || strlen($path) === 0) {
    $path = get_cfg_var('error_observer.sqlite');
}

if (is_string($path)) {
    SqliteErrorHandler::register($path);
}

unset($path);
