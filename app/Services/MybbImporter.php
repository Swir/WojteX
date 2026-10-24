<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Conservative, transactional importer for the versioned WojteX MyBB export.
 * No SQL dump execution, network requests, permission promotion or source writes.
 */
class MybbImporter
{
    public const FORMAT = 'veyra.mybb.v1';
    public const MAX_BYTES = 16 * 1024 * 1024;
    public const LIMITS = ['users' => 10000, 'forums' => 2000, 'threads' => 20000, 'posts' => 50000];

    public function readFile(string $path): array
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size < 2 || $size > self::MAX_BYTES) {
            $this->fail(__('server.import.file_size'));
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->fail(__('server.import.file_unreadable'));
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            $this->fail(__('server.import.invalid_json'));
        }
        if (!is_array($data) || array_is_list($data)) {
            $this->fail(__('server.import.object_required', ['format' => self::FORMAT]));
        }
        return $data;
    }

    public function preview(array $data): array
    {
        $prepared = $this->prepare($data);
        $existing = $this->existingReport($prepared);
        if ($existing !== null) {
            return $existing;
        }
        $this->checkEmailConflicts($prepared['users']);
        return $prepared['report'];
    }

    public function import(array $data, ?int $actorId = null): array
    {
        // All input, references, cycles and supported field sizes are validated
        // before acquiring the write transaction.
        $prepared = $this->prepare($data);
        return DB::transaction(function () use ($prepared, $actorId) {
            // A durable per-source row serializes imports even when the two
            // payloads have different fingerprints. No dependence on cache mode.
            $lockKey = 'mybb_import_lock_'.hash('sha256', $prepared['source']);
            DB::table('settings')->insertOrIgnore(['key' => $lockKey, 'value' => 'true']);
            DB::table('settings')->where('key', $lockKey)->lockForUpdate()->first();

            $existing = $this->existingReport($prepared);
            if ($existing !== null) {
                return $existing;
            }
            $this->checkEmailConflicts($prepared['users']);
            $now = now()->utc()->format('Y-m-d H:i:s');
            // This unguessable password is never returned or logged. Supported
            // legacy accounts upgrade to an individual modern hash at login.
            $unusablePassword = Hash::make(Str::random(80));
            $userMap = $forumMap = $threadMap = [];
            foreach ($prepared['users'] as $sourceId => $user) {
                $id = DB::table('users')->insertGetId([
                    'name' => $user['name'], 'email' => $user['email'],
                    'password' => $unusablePassword, 'email_verified_at' => null,
                    'role' => 'member', 'bio' => null, 'color' => 'violet',
                    'is_banned' => $user['is_banned'],
                    'legacy_password_hash' => $user['legacy_password_hash'],
                    'legacy_password_salt' => $user['legacy_password_salt'],
                    'created_at' => $user['created_at'], 'updated_at' => $now,
                ]);
                $userMap[(string) $sourceId] = $id;
                $this->map($prepared['source'], 'user', (string) $sourceId, $id);
            }
            foreach ($prepared['forums'] as $sourceId => $forum) {
                $id = DB::table('categories')->insertGetId([
                    'name' => $forum['name'], 'description' => $forum['description'],
                    'slug' => 'mybb-'.substr(hash('sha256', $prepared['source']), 0, 12).'-'.$sourceId.'-'.Str::slug($forum['name']),
                    'parent_id' => null, 'sort_order' => $forum['sort_order'],
                    'color' => 'violet', 'icon' => 'folder', 'is_private' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $forumMap[(string) $sourceId] = $id;
                $this->map($prepared['source'], 'forum', (string) $sourceId, $id);
            }
            foreach ($prepared['forums'] as $sourceId => $forum) {
                if ($forum['parent'] !== '0') {
                    DB::table('categories')->where('id', $forumMap[(string) $sourceId])
                        ->update(['parent_id' => $forumMap[$forum['parent']]]);
                }
            }
            foreach ($prepared['threads'] as $sourceId => $thread) {
                $id = DB::table('threads')->insertGetId([
                    'category_id' => $forumMap[$thread['forum']], 'user_id' => $userMap[$thread['author']],
                    'title' => $thread['title'], 'slug' => Str::slug($thread['title']) ?: 'temat',
                    'is_pinned' => $thread['is_pinned'], 'is_locked' => $thread['is_locked'],
                    'views' => $thread['views'], 'created_at' => $thread['created_at'], 'updated_at' => $thread['updated_at'],
                ]);
                $threadMap[(string) $sourceId] = $id;
                $this->map($prepared['source'], 'thread', (string) $sourceId, $id);
            }
            // Fingerprint keys stay lexically canonical for compatibility with
            // imports from older releases, but MyBB pid values must be inserted
            // in unsigned numeric order. Otherwise pid=10 precedes pid=2,
            // corrupting the displayed first post / reply order.
            foreach (MybbPostOrder::bySourceId($prepared['posts']) as $sourceId => $post) {
                $id = DB::table('posts')->insertGetId([
                    'thread_id' => $threadMap[$post['thread']], 'user_id' => $userMap[$post['author']],
                    'body' => $post['body'], 'body_format' => 'bbcode',
                    'created_at' => $post['created_at'], 'updated_at' => $post['updated_at'],
                ]);
                $this->map($prepared['source'], 'post', (string) $sourceId, $id);
            }
            $report = array_merge($prepared['report'], [
                'status' => 'completed', 'ready' => false, 'imported_at' => $now,
                'message' => __('server.import.completed'),
            ]);
            DB::table('import_runs')->insert([
                'source' => $prepared['source'], 'fingerprint' => $prepared['fingerprint'],
                'status' => 'completed', 'report' => json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('audit_logs')->insert([
                'user_id' => $actorId, 'action' => 'mybb.import.completed',
                'details' => json_encode(['source' => $prepared['source'], 'counts' => $report['counts']], JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return $report;
        }, 3);
    }

    private function prepare(array $data): array
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            $this->fail(__('server.import.unsupported_format', ['format' => self::FORMAT]));
        }
        $source = $data['source_id'] ?? null;
        if (!is_string($source) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{2,63}\z/D', $source)) {
            $this->fail(__('server.import.invalid_source_id'));
        }
        $version = $data['mybb_version'] ?? null;
        if (!is_string($version) || !preg_match('/\A1\.8\.\d{1,3}\z/D', $version)) {
            $this->fail(__('server.import.unsupported_version'));
        }
        if (isset($data['source_url'])) {
            $url = is_string($data['source_url']) ? parse_url($data['source_url']) : false;
            if (!$url || !in_array($url['scheme'] ?? '', ['https', 'http'], true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || strlen($data['source_url']) > 2048) {
                $this->fail(__('server.import.invalid_source_url'));
            }
        }
        $total = 0;
        foreach (self::LIMITS as $table => $limit) {
            if (!isset($data[$table]) || !is_array($data[$table]) || !array_is_list($data[$table]) || count($data[$table]) > $limit) {
                $this->fail(__('server.import.invalid_table', ['table' => $table, 'limit' => $limit]));
            }
            $total += count($data[$table]);
        }
        if ($total > 60000) {
            $this->fail(__('server.import.record_limit'));
        }
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            $this->fail(__('server.import.export_size'));
        }
        $users = $forums = $threads = $posts = $emails = [];
        $unsupportedPasswords = $guestCount = 0;
        foreach ($data['users'] as $row) {
            $this->row($row, 'users');
            $id = $this->id($row['uid'] ?? null, 'uid');
            $this->unique($users, $id, 'uid');
            $name = $this->string($row['username'] ?? null, 'username', 100);
            $email = strtolower($this->string($row['email'] ?? null, 'email', 254));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->fail(__('server.import.invalid_email', ['id' => $id]));
            }
            if (isset($emails[$email])) {
                $this->fail(__('server.import.duplicate_email', ['id' => $id]));
            }
            $emails[$email] = true;
            $hash = $row['password'] ?? null;
            $salt = $row['salt'] ?? null;
            $supported = is_string($hash) && preg_match('/\A[0-9a-fA-F]{32}\z/D', $hash)
                && is_string($salt) && strlen($salt) >= 1 && strlen($salt) <= 32
                && !str_contains($salt, "\0") && (($row['password_scheme'] ?? 'mybb-md5') === 'mybb-md5');
            if (!$supported) {
                $unsupportedPasswords++;
            }
            $banned = $this->flag($row['is_banned'] ?? 0, 'is_banned');
            $users[$id] = [
                'name' => $name, 'email' => $email, 'created_at' => $this->date($row['regdate'] ?? null, 'regdate'),
                'legacy_password_hash' => $supported ? strtolower($hash) : null,
                'legacy_password_salt' => $supported ? $salt : null,
                'is_banned' => $banned || !$supported,
            ];
        }
        foreach ($data['forums'] as $row) {
            $this->row($row, 'forums');
            $id = $this->id($row['fid'] ?? null, 'fid');
            $this->unique($forums, $id, 'fid');
            $forums[$id] = [
                'name' => $this->string($row['name'] ?? null, 'forum.name', 120),
                'description' => $this->string($row['description'] ?? '', 'forum.description', 10000, true),
                'parent' => $this->id($row['pid'] ?? 0, 'forum.pid', true),
                'sort_order' => $this->integer($row['disporder'] ?? 0, 'disporder', 0, 1000000),
            ];
        }
        foreach ($forums as $id => $forum) {
            $seen = [(string) $id => true];
            $parent = $forum['parent'];
            while ($parent !== '0') {
                if (!isset($forums[$parent])) {
                    $this->fail(__('server.import.missing_parent', ['id' => $id]));
                }
                if (isset($seen[$parent])) {
                    $this->fail(__('server.import.category_cycle'));
                }
                $seen[$parent] = true;
                $parent = $forums[$parent]['parent'];
            }
        }
        foreach ($data['threads'] as $row) {
            $this->row($row, 'threads');
            $id = $this->id($row['tid'] ?? null, 'tid');
            $this->unique($threads, $id, 'tid');
            $forum = $this->id($row['fid'] ?? null, 'thread.fid');
            if (!isset($forums[$forum])) {
                $this->fail(__('server.import.missing_category', ['id' => $id]));
            }
            $this->published($row, 'tid='.$id);
            $author = $this->author($row, $users, $source, $guestCount);
            $date = $this->date($row['dateline'] ?? null, 'thread.dateline');
            $updated = $this->date($row['lastpost'] ?? $row['dateline'], 'thread.lastpost');
            $threads[$id] = [
                'forum' => $forum, 'author' => $author, 'title' => $this->string($row['subject'] ?? null, 'subject', 180),
                'created_at' => $date, 'updated_at' => max($date, $updated),
                'is_pinned' => $this->flag($row['sticky'] ?? 0, 'sticky'),
                'is_locked' => $this->flag($row['closed'] ?? 0, 'closed'),
                'views' => $this->integer($row['views'] ?? 0, 'views', 0, 4294967295),
            ];
        }
        $threadPostCounts = [];
        foreach ($data['posts'] as $row) {
            $this->row($row, 'posts');
            $id = $this->id($row['pid'] ?? null, 'post.pid');
            $this->unique($posts, $id, 'post.pid');
            $thread = $this->id($row['tid'] ?? null, 'post.tid');
            if (!isset($threads[$thread])) {
                $this->fail(__('server.import.missing_topic', ['id' => $id]));
            }
            if (isset($row['fid']) && $this->id($row['fid'], 'post.fid') !== $threads[$thread]['forum']) {
                $this->fail(__('server.import.category_mismatch', ['id' => $id]));
            }
            $this->published($row, 'pid='.$id);
            $date = $this->date($row['dateline'] ?? null, 'post.dateline');
            $edited = empty($row['edittime']) ? $date : $this->date($row['edittime'], 'post.edittime');
            $posts[$id] = [
                'thread' => $thread, 'author' => $this->author($row, $users, $source, $guestCount),
                'body' => $this->string($row['message'] ?? null, 'message', 100000, true),
                'created_at' => $date, 'updated_at' => max($date, $edited),
            ];
            $threadPostCounts[$thread] = ($threadPostCounts[$thread] ?? 0) + 1;
        }
        foreach ($threads as $id => $thread) {
            if (empty($threadPostCounts[(string) $id])) {
                $this->fail(__('server.import.empty_topic', ['id' => $id]));
            }
        }
        if (count($users) > self::LIMITS['users'] || count($users) + count($forums) + count($threads) + count($posts) > 60000) {
            $this->fail(__('server.import.archive_limit'));
        }
        $allEmails = [];
        foreach ($users as $user) {
            if (isset($allEmails[$user['email']])) {
                $this->fail(__('server.import.archive_email_conflict'));
            }
            $allEmails[$user['email']] = true;
        }
        $omitted = $data['omitted'] ?? [];
        if (!is_array($omitted)) {
            $this->fail(__('server.import.invalid_omitted'));
        }
        $safeOmitted = [];
        foreach ($omitted as $key => $value) {
            if (!is_string($key) || !preg_match('/\A[a-z_]{1,40}\z/D', $key)) {
                $this->fail(__('server.import.invalid_omitted_key'));
            }
            $safeOmitted[$key] = $this->integer($value, 'omitted.'.$key, 0, PHP_INT_MAX);
        }
        foreach (['users', 'forums', 'threads', 'posts'] as $table) {
            ksort($$table, SORT_STRING);
        }
        ksort($safeOmitted);
        $fingerprint = hash('sha256', json_encode([$source, $version, $users, $forums, $threads, $posts, $safeOmitted], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $warnings = [
            __('server.import.warning_private'),
            __('server.import.warning_roles'),
            __('server.import.warning_unsupported'),
            __('server.import.warning_bbcode'),
            __('server.import.warning_one_time'),
        ];
        if ($unsupportedPasswords) {
            $warnings[] = __('server.import.warning_passwords', ['count' => $unsupportedPasswords]);
        }
        if ($guestCount) {
            $warnings[] = __('server.import.warning_guests', ['count' => $guestCount]);
        }
        foreach ($safeOmitted as $key => $count) {
            if ($count > 0) {
                $warnings[] = __('server.import.warning_omitted', ['count' => $count, 'key' => $key]);
            }
        }
        $report = [
            'status' => 'ready', 'ready' => true, 'source_id' => $source, 'mybb_version' => $version,
            'counts' => ['users' => count($users), 'forums' => count($forums), 'threads' => count($threads), 'posts' => count($posts)],
            'guest_accounts' => $guestCount, 'unsupported_passwords' => $unsupportedPasswords,
            'omitted' => $safeOmitted, 'warnings' => $warnings, 'errors' => [],
            'private_quarantine' => true, 'fingerprint' => $fingerprint,
        ];
        return compact('source', 'fingerprint', 'users', 'forums', 'threads', 'posts', 'report');
    }

    private function existingReport(array $prepared): ?array
    {
        $runs = DB::table('import_runs')->where('source', $prepared['source'])->get();
        foreach ($runs as $run) {
            if ($run->status === 'completed' && hash_equals($run->fingerprint, $prepared['fingerprint'])) {
                $report = json_decode($run->report, true, 64, JSON_THROW_ON_ERROR);
                return array_merge($report, [
                    'status' => 'already_imported', 'ready' => false,
                    'warnings' => $prepared['report']['warnings'],
                    'message' => __('server.import.already_imported'),
                ]);
            }
        }
        if ($runs->isNotEmpty() || DB::table('import_maps')->where('source', $prepared['source'])->exists()) {
            $this->fail(__('server.import.source_already_used'));
        }
        return null;
    }

    private function checkEmailConflicts(array $users): void
    {
        foreach (array_chunk(array_column($users, 'email'), 400) as $emails) {
            if (DB::table('users')->whereIn(DB::raw('LOWER(email)'), $emails)->exists()) {
                $this->fail(__('server.import.email_conflict'));
            }
        }
    }

    private function author(array $row, array &$users, string $source, int &$guestCount): string
    {
        $id = $this->id($row['uid'] ?? null, 'author.uid', true);
        if ($id !== '0') {
            if (!isset($users[$id])) {
                $this->fail(__('server.import.missing_author', ['id' => $id]));
            }
            return $id;
        }
        $name = $this->string($row['username'] ?? 'Gość', 'guest.username', 100, true) ?: 'Gość';
        $key = 'guest:'.substr(hash('sha256', $name), 0, 32);
        if (!isset($users[$key])) {
            $users[$key] = [
                'name' => $name, 'email' => 'guest.'.substr(hash('sha256', $source.'|'.$name), 0, 40).'@imports.invalid',
                'created_at' => $this->date($row['dateline'] ?? null, 'guest.dateline'),
                'legacy_password_hash' => null, 'legacy_password_salt' => null, 'is_banned' => true,
            ];
            $guestCount++;
        } else {
            $users[$key]['created_at'] = min($users[$key]['created_at'], $this->date($row['dateline'] ?? null, 'guest.dateline'));
        }
        return $key;
    }

    private function published(array $row, string $label): void
    {
        if ((string) ($row['visible'] ?? '1') !== '1') {
            $this->fail(__('server.import.unpublished_content', ['label' => $label]));
        }
    }

    private function row(mixed $row, string $table): void
    {
        if (!is_array($row) || array_is_list($row)) {
            $this->fail(__('server.import.invalid_row', ['table' => $table]));
        }
    }

    private function unique(array $items, string $id, string $field): void
    {
        if (isset($items[$id])) {
            $this->fail(__('server.import.duplicate_id', ['field' => $field, 'id' => $id]));
        }
    }

    private function id(mixed $value, string $field, bool $zero = false): string
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string) $value)
            || (int) $value > 4294967295 || (!$zero && (int) $value === 0)) {
            $this->fail(__('server.import.invalid_id', ['field' => $field]));
        }
        return (string) $value;
    }

    private function string(mixed $value, string $field, int $max, bool $empty = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")
            || mb_strlen($value) > $max || (!$empty && trim($value) === '')) {
            $this->fail(__('server.import.invalid_string', ['field' => $field, 'max' => $max]));
        }
        return $value;
    }

    private function integer(mixed $value, string $field, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string) $value)
            || strlen((string) $value) > strlen((string) $max)
            || (strlen((string) $value) === strlen((string) $max) && strcmp((string) $value, (string) $max) > 0)
            || (int) $value < $min || (int) $value > $max) {
            $this->fail(__('server.import.invalid_integer', ['field' => $field]));
        }
        return (int) $value;
    }

    private function flag(mixed $value, string $field): bool
    {
        if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
            $this->fail(__('server.import.invalid_flag', ['field' => $field]));
        }
        return (bool) $value;
    }

    private function date(mixed $value, string $field): string
    {
        $timestamp = $this->integer($value, $field, 0, 4102444800);
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function map(string $source, string $entity, string $sourceId, int $targetId): void
    {
        DB::table('import_maps')->insert(['source' => $source, 'entity' => $entity, 'source_id' => $sourceId, 'target_id' => $targetId]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }
}
