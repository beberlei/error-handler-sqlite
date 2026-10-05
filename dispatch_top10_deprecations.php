<?php

/**
 * Reads the most frequent deprecations from the error-handler-sqlite database
 * and sends them as "top10_deprecations" repository_dispatch event to GitHub.
 *
 * Usage:
 *   GITHUB_TOKEN=... php dispatch_top10_deprecations.php <database> <owner/repo> [options]
 *
 * Options:
 *   --since=<strtotime>       Only errors seen since then (default: "-7 days")
 *   --limit=<n>               Number of deprecations (default: 10)
 *   --strip-prefix=<path>     Remove this deployment path prefix from files and traces,
 *                             so paths are relative to the repository root
 *   --dry-run                 Print the payload instead of sending it
 *
 * The token needs "Contents: read and write" permission on the repository
 * (fine-grained token) or the "repo" scope (classic token).
 */

namespace Tideways\ErrorHandlerSqlite;

const EVENT_TYPE = 'top10_deprecations';
const MAX_MESSAGE_LENGTH = 1000;
const MAX_STACKTRACE_LENGTH = 3000;

/** @return list<array{fingerprint: string, type: string, message: string, file: string, line: int, count: int, first_seen: string, last_seen: string, stacktrace: string}> */
function fetchTopDeprecations(\PDO $db, int $since, int $limit, string $stripPrefix): array
{
    $statement = $db->prepare(
        'SELECT fingerprint, type, message, file, line, stacktrace, first_seen, last_seen, count
         FROM errors
         WHERE type IN (:deprecated, :userDeprecated) AND last_seen >= :since
         ORDER BY count DESC, last_seen DESC
         LIMIT :limit',
    );
    $statement->bindValue('deprecated', E_DEPRECATED, \PDO::PARAM_INT);
    $statement->bindValue('userDeprecated', E_USER_DEPRECATED, \PDO::PARAM_INT);
    $statement->bindValue('since', $since, \PDO::PARAM_INT);
    $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
    $statement->execute();

    $deprecations = [];
    foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $deprecations[] = [
            'fingerprint' => $row['fingerprint'],
            'type' => (int) $row['type'] === E_USER_DEPRECATED ? 'E_USER_DEPRECATED' : 'E_DEPRECATED',
            'message' => truncate(stripPrefix($row['message'], $stripPrefix), MAX_MESSAGE_LENGTH),
            'file' => stripPrefix($row['file'], $stripPrefix),
            'line' => (int) $row['line'],
            'count' => (int) $row['count'],
            'first_seen' => gmdate(DATE_ATOM, (int) $row['first_seen']),
            'last_seen' => gmdate(DATE_ATOM, (int) $row['last_seen']),
            'stacktrace' => truncate(stripPrefix($row['stacktrace'], $stripPrefix), MAX_STACKTRACE_LENGTH),
        ];
    }

    return $deprecations;
}

/**
 * GitHub allows at most 10 top-level properties in client_payload, so the
 * deprecations are nested in one property.
 *
 * @param list<array<string, mixed>> $deprecations
 * @return array{event_type: string, client_payload: array{generated_at: string, since: string, deprecations: list<array<string, mixed>>}}
 */
function buildPayload(array $deprecations, int $since, int $now): array
{
    return [
        'event_type' => EVENT_TYPE,
        'client_payload' => [
            'generated_at' => gmdate(DATE_ATOM, $now),
            'since' => gmdate(DATE_ATOM, $since),
            'deprecations' => $deprecations,
        ],
    ];
}

function sendDispatch(string $repository, string $token, string $body): void
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $token,
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: error-handler-sqlite',
                'Content-Type: application/json',
            ]),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 30,
        ],
    ]);

    $response = file_get_contents(sprintf('https://api.github.com/repos/%s/dispatches', $repository), false, $context);
    $status = $http_response_header[0] ?? 'no response';

    if (!str_contains($status, ' 204')) {
        throw new \RuntimeException(sprintf('GitHub dispatch failed: %s %s', $status, (string) $response));
    }
}

function stripPrefix(string $value, string $prefix): string
{
    return strlen($prefix) === 0 ? $value : str_replace($prefix, '', $value);
}

function truncate(string $value, int $length): string
{
    return strlen($value) > $length ? substr($value, 0, $length) . "\n[truncated]" : $value;
}

/** @param list<string> $argv */
function main(array $argv): int
{
    $options = getopt('', ['since:', 'limit:', 'strip-prefix:', 'dry-run'], $restIndex);
    $arguments = array_slice($argv, $restIndex);

    if (count($arguments) !== 2 || preg_match('(^[\w.-]+/[\w.-]+$)', $arguments[1]) !== 1) {
        fwrite(STDERR, "Usage: php dispatch_top10_deprecations.php <database> <owner/repo> [--since=-7days] [--limit=10] [--strip-prefix=/path/] [--dry-run]\n");

        return 1;
    }

    [$database, $repository] = $arguments;
    $dryRun = isset($options['dry-run']);
    $token = getenv('GITHUB_TOKEN');

    if (!$dryRun && ($token === false || strlen($token) === 0)) {
        fwrite(STDERR, "GITHUB_TOKEN environment variable is required.\n");

        return 1;
    }

    if (!is_file($database)) {
        fwrite(STDERR, sprintf("Database %s does not exist.\n", $database));

        return 1;
    }

    $now = time();
    $since = strtotime(is_string($options['since'] ?? null) ? $options['since'] : '-7 days', $now);
    $limit = (int) ($options['limit'] ?? 10);

    if ($since === false || $limit < 1) {
        fwrite(STDERR, "Invalid --since or --limit option.\n");

        return 1;
    }

    $db = new \Pdo\Sqlite('sqlite:' . $database, options: [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY,
    ]);

    $stripPrefix = is_string($options['strip-prefix'] ?? null) ? $options['strip-prefix'] : '';
    $deprecations = fetchTopDeprecations($db, $since, $limit, $stripPrefix);

    if (count($deprecations) === 0) {
        fwrite(STDERR, "No deprecations found, nothing to dispatch.\n");

        return 0;
    }

    $body = json_encode(buildPayload($deprecations, $since, $now), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($dryRun) {
        fwrite(STDOUT, $body . "\n");

        return 0;
    }

    sendDispatch($repository, (string) $token, $body);
    fwrite(STDERR, sprintf("Dispatched %d deprecations to %s.\n", count($deprecations), $repository));

    return 0;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main($_SERVER['argv']));
}
