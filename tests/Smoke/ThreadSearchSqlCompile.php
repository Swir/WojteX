<?php
/** Compile the production forum-search predicate without PHP PDO. */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ThreadSearchQuery;
use Illuminate\Support\Facades\DB;

if (DB::connection()->getDriverName() !== 'sqlite') throw new RuntimeException('SQLite grammar required');
$terms = [
    'percent' => '100%',
    'zero' => '0',
    'underscore' => 'nick_',
    'escape' => 'hello!',
    'body' => 'body*mark',
    'unicode' => 'ŻÓŁW',
    'injection' => "' OR 1=1 --",
];
if (!ThreadSearchQuery::shouldSearch('0') || ThreadSearchQuery::shouldSearch('  ')
    || ThreadSearchQuery::shouldSearch(null) || ThreadSearchQuery::shouldSearch('')) {
    throw new RuntimeException('Search-presence guard failed for 0/blank/null');
}
$queries = [];
foreach ($terms as $key => $term) {
    // Explicit visible-category scope as in ForumService::threadQuery().
    // The test must never match category 2, regardless of the search term.
    $query = DB::table('threads as t')->select('t.id')->whereIn('t.category_id', [1]);
    $query = ThreadSearchQuery::apply($query, $term)->orderBy('t.id');
    $queries[$key] = ['sql' => $query->toSql(), 'bindings' => $query->getBindings()];
}
// Grammar-only cross-check: no MySQL service and NO PDO connection is opened.
$mysql = new \Illuminate\Database\MySqlConnection(static function () {
    throw new RuntimeException('Unexpected live MySQL PDO connection.');
}, 'veyra_test');
$mysqlQuery = ThreadSearchQuery::apply(
    $mysql->table('threads as t')->select('t.id')->whereIn('t.category_id', [1]),
    '100%'
);
if (substr_count($mysqlQuery->toSql(), "ESCAPE '!'") !== 2
    || $mysqlQuery->getBindings() !== [1, '%100!%%', '%100!%%']) {
    throw new RuntimeException('MySQL grammar failed literal search compilation');
}

echo json_encode($queries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
