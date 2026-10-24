<?php
namespace App\Http\Controllers;
use App\Services\ForumService as Forum;
use App\Services\ExtensionRegistry;
use App\Services\ShoutboxWriteQuery;
use App\Services\ShoutboxReadQuery;
use App\Services\ShoutboxDeleteQuery;
use App\Services\RankingsReadQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunityController extends Controller
{
    private function shoutData(object $s, object $author): array {return ['id'=>(int)$s->id,'body'=>$s->body,'created_at'=>$s->created_at,'author'=>Forum::publicUser($author)];}
    public function shouts(Request $request) {
        abort_unless(ExtensionRegistry::enabled('shoutbox'),404,__('server.shoutbox_disabled'));
        $data=$request->validate(['after'=>'nullable|integer|min:0']);
        // The module can be disabled after the preflight. The same SQL
        // fetching persisted content must require the CURRENT plugin state.
        $q=ShoutboxReadQuery::eligible();
        $rows=isset($data['after'])?$q->where('id','>',$data['after'])->orderBy('id')->limit(100)->get():$q->orderByDesc('id')->limit(30)->get()->reverse()->values();
        // Polling every five seconds previously fetched each author separately:
        // 30/100 shout rows generated 30/100 extra SELECTs on users. Fetch
        // only the distinct authors for this bounded page in one query.
        $authors = $rows->isEmpty() ? collect() : DB::table('users')
            ->whereIn('id', $rows->pluck('user_id')->unique()->all())
            ->get(['id','name','role','color','bio','created_at'])->keyBy('id');
        // A concurrently removed account may disappear between these SELECTs.
        // Omit such an orphaned row rather than serializing an absent user.
        $data = $rows->filter(fn($s) => $authors->has($s->user_id))
            ->map(fn($s) => $this->shoutData($s, $authors->get($s->user_id)))->values();
        // A disable while resolving authors must not yield a successful
        // response carrying content fetched before revocation.
        abort_unless(ExtensionRegistry::enabled('shoutbox'),404,__('server.shoutbox_disabled'));
        return response()->json(['data'=>$data,'enabled'=>true]);
    }
    public function shout(Request $request) {
        abort_unless(ExtensionRegistry::enabled('shoutbox'),404);
        // Existing authenticated sessions must not retain shoutbox write access
        // after an administrator bans an account. Other forum writes already
        // enforce this rule; the shoutbox needs the same server-side guard.
        abort_if((bool) $request->user()->is_banned, 403, __('server.account_blocked'));
        $data=$request->validate(['body'=>'required|string|min:1|max:1000']);
        // The authenticated User object was loaded before this statement.
        // A concurrent admin ban can commit after that read but before INSERT.
        // A guarded single SQL statement rechecks the CURRENT account ban at
        // write time, rather than relying only on the stale session snapshot.
        $viewerId = (int) $request->user()->id;
        $inserted = DB::table('shouts')->insertUsing(
            ['user_id', 'body', 'created_at', 'updated_at'],
            ShoutboxWriteQuery::eligible($viewerId, $data['body'], now()->toDateTimeString())
        );
        if ($inserted !== 1) {
            // Disabled between the preflight and INSERT: do not misreport a
            // revoked plugin as an account ban. No shout was persisted.
            abort_unless(ExtensionRegistry::enabled('shoutbox'),404,__('server.shoutbox_disabled'));
            abort(403, __('server.account_blocked'));
        }
        $id = (int) DB::connection()->getPdo()->lastInsertId();
        $created = DB::table('shouts')->find($id) ?? abort(404);
        $author = DB::table('users')->find($created->user_id) ?? abort(404);
        return response()->json(['shout'=>$this->shoutData($created, $author)],201);
    }
    public function deleteShout(Request $request,int $id) {
        abort_unless(ExtensionRegistry::enabled('shoutbox'),404);
        // A ban also revokes the right to DELETE an earlier shout through a
        // still-authenticated session. Check before looking up or deleting it.
        abort_if((bool) $request->user()->is_banned, 403, __('server.account_blocked'));
        $s=DB::table('shouts')->find($id)??abort(404);
        abort_unless($s->user_id===$request->user()->id||Forum::isModerator($request->user()),403);
        // Authorization from the request's User model can become stale here:
        // a ban or role revocation may commit after find()/isModerator().
        // Check the current DB identity, ban and staff role in the DELETE.
        $deleted = ShoutboxDeleteQuery::eligible($id, (int) $request->user()->id)->delete();
        if ($deleted !== 1) {
            abort_unless(ExtensionRegistry::enabled('shoutbox'),404,__('server.shoutbox_disabled'));
            abort(403);
        }
        Forum::audit('shout.deleted',['shout_id'=>$id]);
        return response()->json(['ok'=>true]);
    }
    public function rankings(Request $request) {
        // The route's fresh_forum_reader already holds a transaction on its
        // authenticated viewer. Keep this gate in an explicit transaction as
        // well, so direct invocations cannot accidentally drop the lock.
        return DB::transaction(static function () use ($request) {
            // Ordinary SELECTs inside an InnoDB REPEATABLE READ transaction
            // can keep seeing a pre-disable plugin snapshot, even if we check
            // twice. A CURRENT, locking read must happen BEFORE scores are
            // loaded. Admin plugin updates then wait for this transaction,
            // while a disable committed first is immediately rejected.
            // SQLite ignores FOR UPDATE; native SQLite races remain unproven.
            $enabled = DB::table('plugin_states')->where('key', 'rankings')
                ->lockForUpdate()->value('enabled');
            abort_unless((bool) $enabled,404,__('server.rankings_disabled'));
            // Scores include only categories visible to this viewer. No private-content counts leak.
            $visible=Forum::visibleCategoryIds($request->user());
            $users=DB::table('users')->where('is_banned',false)->select('users.*')
                ->selectSub(DB::table('posts')->join('threads','threads.id','=','posts.thread_id')->selectRaw('COUNT(*)')->whereColumn('posts.user_id','users.id')->whereIn('threads.category_id',$visible),'posts_count')
                ->selectSub(DB::table('reactions')->join('posts','posts.id','=','reactions.post_id')->join('threads','threads.id','=','posts.thread_id')->selectRaw('COUNT(*)')->whereColumn('posts.user_id','users.id')->whereIn('threads.category_id',$visible),'reactions_count');
            $ranked=RankingsReadQuery::eligible(DB::query()->fromSub($users,'ranked'))->select('ranked.*')
                ->selectRaw('(CASE WHEN posts_count > 100 THEN 100 ELSE posts_count END) * 2 + reactions_count * 10 AS score')
                ->orderByDesc('score')->orderBy('id')->limit(50)->get();
            $rows=$ranked->map(fn($u)=>Forum::publicUser($u)+['posts_count'=>(int)$u->posts_count,'reactions_count'=>(int)$u->reactions_count,'score'=>(int)$u->score])
                ->values()->map(fn($u,$i)=>$u+['rank'=>$i+1,'badge'=>$u['score']>=500?__('server.rank_expert'):($u['score']>=100?__('server.rank_creator'):__('server.rank_explorer'))]);
            // Retain the old defense-in-depth gate, but do not mistake
            // snapshot reads alone for live revocation serialization.
            abort_unless(ExtensionRegistry::enabled('rankings'),404,__('server.rankings_disabled'));
            return response()->json(['data'=>$rows,'enabled'=>true]);
        });
    }
}
