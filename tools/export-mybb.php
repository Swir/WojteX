#!/usr/bin/env php
<?php
/**
 * Read-only MyBB 1.8 -> WojteX JSON exporter. Requires PHP 8.3+, pdo_mysql.
 * Run against a consistent backup; write output OUTSIDE the web root.
 * Credentials are read only from environment, never CLI args or reports.
 */
declare(strict_types=1);

require_once __DIR__.'/lib/MybbExportSelection.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const MAX_BYTES = 16 * 1024 * 1024;
const LIMITS = ['users' => 10000, 'forums' => 2000, 'threads' => 20000, 'posts' => 50000];

function envRequired(string $key): string
{
    $value = getenv($key);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException('Missing environment setting: '.$key);
    }
    return $value;
}

function boundedRows(PDO $db, string $query, int $limit): array
{
    $rows = $db->query($query.' LIMIT '.($limit + 1))->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > $limit) {
        throw new RuntimeException('An alpha import record limit was exceeded; no partial export was produced.');
    }
    return $rows;
}

$output = $argv[1] ?? '';
if ($output === '' || count($argv) !== 2 || in_array($output, ['--help', '-h'], true)) {
    fwrite(STDERR, "Usage: php tools/export-mybb.php /private/path/mybb-export.json\nRequired env: MYBB_DB_HOST, MYBB_DB_NAME, MYBB_DB_USER, MYBB_DB_PASSWORD, MYBB_SOURCE_ID, MYBB_VERSION\nOptional env: MYBB_DB_PORT (3306), MYBB_TABLE_PREFIX (mybb_), MYBB_SOURCE_URL\nUse a SELECT-only database account and a consistent backup. The output contains account hashes: keep it private.\n");
    exit($output === '--help' || $output === '-h' ? 0 : 2);
}

$db = null;
$handle = null;
$created = false;
try {
    $host = envRequired('MYBB_DB_HOST');
    $name = envRequired('MYBB_DB_NAME');
    $username = envRequired('MYBB_DB_USER');
    $password = envRequired('MYBB_DB_PASSWORD');
    $source = envRequired('MYBB_SOURCE_ID');
    $version = envRequired('MYBB_VERSION');
    $port = getenv('MYBB_DB_PORT') ?: '3306';
    $prefix = getenv('MYBB_TABLE_PREFIX') !== false ? getenv('MYBB_TABLE_PREFIX') : 'mybb_';
    if (preg_match('/[;\x00-\x20]/', $host) || !preg_match('/\A[a-zA-Z0-9_-]+\z/D', $name)
        || !preg_match('/\A[0-9]{1,5}\z/D', $port) || (int) $port < 1 || (int) $port > 65535
        || !preg_match('/\A[a-zA-Z0-9_]*\z/D', $prefix)
        || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{2,63}\z/D', $source)
        || !preg_match('/\A1\.8\.\d{1,3}\z/D', $version)) {
        throw new RuntimeException('Invalid database/source configuration. Use MyBB 1.8.x and a safe table prefix.');
    }
    $sourceUrl = getenv('MYBB_SOURCE_URL') ?: null;
    if ($sourceUrl !== null) {
        $url = parse_url($sourceUrl);
        if (!$url || !in_array($url['scheme'] ?? '', ['https', 'http'], true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || strlen($sourceUrl) > 2048) {
            throw new RuntimeException('MYBB_SOURCE_URL must be an HTTP(S) URL without credentials.');
        }
    }
    $db = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    unset($password);
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();

    $users = boundedRows($db, "SELECT u.uid,u.username,u.email,u.password,u.salt,u.usergroup,u.regdate,u.suspendposting,u.suspensiontime,g.isbannedgroup,g.canview FROM `{$prefix}users` u LEFT JOIN `{$prefix}usergroups` g ON g.gid=u.usergroup ORDER BY u.uid", LIMITS['users']);
    foreach ($users as &$user) {
        $user['password_scheme'] = 'mybb-md5';
        $suspended = (int) $user['suspendposting'] === 1 && ((int) $user['suspensiontime'] === 0 || (int) $user['suspensiontime'] > time());
        $user['is_banned'] = (int) $user['isbannedgroup'] === 1 || (string) $user['canview'] !== '1' || in_array((int) $user['usergroup'], [1, 5], true) || $suspended ? 1 : 0;
        unset($user['suspendposting'], $user['suspensiontime'], $user['isbannedgroup'], $user['canview']);
    }
    unset($user);
    $forums = boundedRows($db, "SELECT fid,pid,name,description,disporder FROM `{$prefix}forums` ORDER BY fid", LIMITS['forums']);
    // Redirect placeholders, unapproved and deleted threads are excluded and counted.
    // Do not silently turn an approved reply into a topic starter. Exclude
    // entire topics whose original opening post is missing/unapproved; their
    // replies must be omitted by the very same eligibility condition.
    $threads = boundedRows($db, MybbExportSelection::threads($prefix), LIMITS['threads']);
    $posts = boundedRows($db, MybbExportSelection::posts($prefix), LIMITS['posts']);
    $omitted = [];
    foreach (['attachments', 'privatemessages', 'polls', 'reputation', 'userfields'] as $table) {
        $omitted[$table] = (int) $db->query("SELECT COUNT(*) FROM `{$prefix}{$table}`")->fetchColumn();
    }
    $candidateThreads = (int) $db->query(MybbExportSelection::candidateThreadCount($prefix))->fetchColumn();
    $omitted['unpublished_or_redirect_threads'] =
        (int) $db->query("SELECT COUNT(*) FROM `{$prefix}threads`")->fetchColumn() - $candidateThreads;
    $missingStarters = $candidateThreads - count($threads);
    // Keep the established omitted keys/fingerprint for healthy sources.
    // A new key exists only when topics genuinely lack an approved opener.
    if ($missingStarters > 0) {
        $omitted['missing_or_unapproved_starter_threads'] = $missingStarters;
    }
    $omitted['unpublished_or_excluded_posts'] = (int) $db->query("SELECT COUNT(*) FROM `{$prefix}posts`")->fetchColumn() - count($posts);
    $db->commit();
    if (count($users) + count($forums) + count($threads) + count($posts) > 60000) {
        throw new RuntimeException('The 60,000 total record alpha import limit was exceeded.');
    }
    $payload = [
        'format' => 'veyra.mybb.v1', 'source_id' => $source, 'mybb_version' => $version,
        'exported_at' => gmdate('c'), 'users' => $users, 'forums' => $forums,
        'threads' => $threads, 'posts' => $posts, 'omitted' => $omitted,
    ];
    if ($sourceUrl !== null) {
        $payload['source_url'] = $sourceUrl;
    }
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($json) > MAX_BYTES) {
        throw new RuntimeException('The 16 MiB export size limit was exceeded; no partial export was produced.');
    }
    umask(0077);
    $handle = @fopen($output, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Cannot create output file. Choose a new path outside the web root; existing files are never overwritten.');
    }
    $created = true;
    $offset = 0;
    while ($offset < strlen($json)) {
        $written = fwrite($handle, substr($json, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Writing the export failed.');
        }
        $offset += $written;
    }
    fflush($handle);
    fclose($handle);
    $handle = null;
    fwrite(STDOUT, 'Export completed: '.count($users).' accounts, '.count($forums).' forums, '.count($threads).' threads, '.count($posts)." posts.\n");
    fwrite(STDOUT, "The source database was read only. Upload the private JSON to WojteX's import preview, then review every warning.\n");
} catch (Throwable $error) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    if (is_resource($handle)) {
        fclose($handle);
    }
    if ($created) {
        @unlink($output);
    }
    // Database exceptions can contain host/account values. Never dump them.
    $message = $error instanceof PDOException ? 'Database read failed. Check the SELECT-only account, MyBB 1.8 schema and backup connection.' : ($error instanceof JsonException ? 'Source text is not valid UTF-8. Convert and verify a backup first.' : $error->getMessage());
    fwrite(STDERR, 'Export failed: '.$message."\n");
    exit(1);
}
