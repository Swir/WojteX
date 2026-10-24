<?php
/** Emit actual Laravel SQLite grammar SQL without opening a missing PHP PDO connection. */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PrivateMessageContactQuery as Contact;
use App\Services\PrivateMessageRecipientSearch as Pattern;
use Illuminate\Support\Facades\DB;

$database = DB::connection();
if ($database->getDriverName() !== 'sqlite') {
    throw new RuntimeException('SQLite query compilation requested');
}
$columns = ['sender_id', 'recipient_id', 'body', 'read_at', 'created_at', 'updated_at'];
$insert = static function (int $sender, int $recipient) use ($database, $columns): array {
    $select = Contact::eligibleMessage($sender, $recipient, 'sample', '2026-10-22 12:00:00');
    return [
        'sql' => $database->getQueryGrammar()->compileInsertUsing(DB::table('private_messages'), $columns, $select->toSql()),
        'bindings' => $select->getBindings(),
    ];
};
$search = static function (int $viewer, string $term): array {
    $query = Contact::availableRecipients($viewer)
        ->where('is_banned', false)
        ->where('users.id', '!=', $viewer)
        ->whereRaw("name LIKE ? ESCAPE '!'", [Pattern::likePattern($term)])
        ->orderBy('name')->orderBy('id')->limit(10)->select(['users.id']);
    return ['sql' => $query->toSql(), 'bindings' => $query->getBindings()];
};
echo json_encode([
    'insert_forward' => $insert(1, 2),
    'insert_reverse' => $insert(2, 1),
    'search_alice' => $search(1, 'Bo'),
    'search_bob' => $search(2, 'Ali'),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
