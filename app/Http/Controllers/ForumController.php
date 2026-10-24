<?php
namespace App\Http\Controllers;

use App\Services\ForumService as Forum;
use App\Services\ExtensionRegistry;
use App\Services\LanguageRegistry;
use App\Services\ThreadSearchQuery;
use App\Services\ThreadDeleteQuery;
use App\Services\ThreadModerateQuery;
use App\Services\ThreadCreateQuery;
use App\Services\ThreadReplyQuery;
use App\Services\PostEditQuery;
use App\Services\PostDeleteQuery;
use App\Services\ReactionToggleQuery;
use App\Services\BookmarkToggleQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ForumController extends Controller
{
    public function bootstrap(Request $request)
    {
        return response()->json(['user'=>$request->user()?Forum::selfUser($request->user()):null,'csrf_token'=>csrf_token(),'settings'=>Forum::settings(),'categories'=>Forum::categories($request->user()),'stats'=>Forum::stats($request->user()),'plugins'=>ExtensionRegistry::catalog(),'version'=>Forum::VERSION,'i18n'=>app(LanguageRegistry::class)->payload()]);
    }

    public function threads(Request $request)
    {
        $data=$request->validate(['category'=>'nullable|integer|min:1','q'=>'nullable|string|max:150','sort'=>'nullable|in:latest,popular,unanswered','page'=>'nullable|integer|min:1|max:100000','bookmarked'=>'nullable|boolean']);
        $query=Forum::threadQuery($request->user());
        if (!empty($data['category'])) $query->where('t.category_id',$data['category']);
        if (ThreadSearchQuery::shouldSearch($data['q'] ?? null)) {
            // Search literal text within visible threads; SQL wildcard characters
            // supplied by visitors must not expand into accidental full scans.
            $query = ThreadSearchQuery::apply($query, $data['q']);
        }
        if ($request->boolean('bookmarked')) {
            abort_unless($request->user(),401,__('server.bookmarks_login'));
            $query->whereExists(fn($q)=>$q->selectRaw('1')->from('bookmarks')->whereColumn('thread_id','t.id')->where('user_id',$request->user()->id));
        }
        if (($data['sort']??'latest')==='unanswered') $query->whereRaw('(SELECT COUNT(*) FROM posts WHERE posts.thread_id=t.id) = 1');
        $query->orderByDesc('t.is_pinned');
        if (($data['sort']??'latest')==='popular') $query->orderByDesc('reactions_count')->orderByDesc('posts_count');
        $page=$query->orderByDesc('t.updated_at')->orderByDesc('t.id')->paginate(15);
        return response()->json(['data'=>Forum::threadsData($page->getCollection()),'current_page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'total'=>$page->total()]);
    }

    public function create(Request $request)
    {
        $data=$request->validate(['category_id'=>'required|integer','title'=>'required|string|min:5|max:180','body'=>'required|string|min:3|max:50000']);
        $category=DB::table('categories')->find($data['category_id']);
        abort_unless($category && Forum::canSeeCategory($category,$request->user()),404,__('server.category_not_found'));
        abort_unless(Forum::canCreateCategory($category,$request->user()),403,__('server.category_post_forbidden'));
        $id=DB::transaction(function() use($data,$request) {
            // Account bans can commit after category/preflight checks while the
            // request still holds an old User object. Require a live, unbanned
            // creator in the same INSERT statement; zero rows means no thread,
            // post, event or audit entry is created.
            $actorId=(int) $request->user()->id;
            $time=now()->toDateTimeString();
            $eligible=ThreadCreateQuery::eligible($actorId,(int) $data['category_id'],
                $data['title'],Str::slug($data['title'])?:'temat',$time);
            $inserted=DB::table('threads')->insertUsing(
                ['category_id','user_id','title','slug','created_at','updated_at'], $eligible
            );
            abort_unless($inserted === 1,403,__('server.account_blocked'));
            $id=(int) DB::connection()->getPdo()->lastInsertId();
            DB::table('posts')->insert(['thread_id'=>$id,'user_id'=>$actorId,'body'=>$data['body'],'body_format'=>'markdown','created_at'=>now(),'updated_at'=>now()]);
            return $id;
        });
        Event::dispatch('veyra.thread.created',[$id,$request->user()->id]);
        return response()->json(['thread'=>Forum::threadData(Forum::thread($id,$request->user()))],201);
    }

    public function show(Request $request,int $id)
    {
        $request->validate(['page'=>'nullable|integer|min:1|max:100000']);
        $thread=Forum::thread($id,$request->user());
        // Count one view per topic per session, bounded to the latest 100 topic ids.
        $seen=$request->session()->get('viewed_threads',[]);
        if (!in_array($id,$seen,true)) {
            DB::table('threads')->where('id',$id)->increment('views'); ++$thread->views;
            $seen[]=$id; $request->session()->put('viewed_threads',array_slice($seen,-100));
        }
        $posts=DB::table('posts')->where('thread_id',$id)->orderBy('id')->paginate(40);
        return response()->json(['thread'=>Forum::threadData($thread),'posts'=>Forum::postsData($posts->getCollection(),$request->user()),'page'=>$posts->currentPage(),'last_page'=>$posts->lastPage(),'total_posts'=>$posts->total(),'can_reply'=>Forum::canReplyCategory(DB::table('categories')->find($thread->category_id), $request->user())]);
    }

    public function reply(Request $request,int $id)
    {
        $data=$request->validate(['body'=>'required|string|min:1|max:50000']);
        $thread=Forum::thread($id,$request->user());
        abort_unless(Forum::canReplyCategory(DB::table('categories')->find($thread->category_id), $request->user()), 403, __('server.category_post_forbidden'));
        abort_if($thread->is_locked && !Forum::isModerator($request->user()),403,__('server.thread_locked'));
        $postId=DB::transaction(function()use($id,$request,$data){
            // A ban, thread lock, or staff demotion can commit after preflight.
            // Require the current account and lock/role in the same INSERT.
            $actorId=(int)$request->user()->id;
            $time=now()->toDateTimeString();
            $eligible=ThreadReplyQuery::eligible($actorId,$id,$data['body'],$time);
            $inserted=DB::table('posts')->insertUsing(
                ['thread_id','user_id','body','body_format','created_at','updated_at'], $eligible
            );
            abort_unless($inserted === 1,403,__('server.category_post_forbidden'));
            $postId=(int) DB::connection()->getPdo()->lastInsertId();
            DB::table('threads')->where('id',$id)->update(['updated_at'=>now()]);
            return $postId;
        });
        Event::dispatch('veyra.post.created',[$postId,$request->user()->id]);
        return response()->json(['post'=>Forum::postData(DB::table('posts')->find($postId),$request->user())],201);
    }

    public function moderate(Request $request,int $id)
    {
        abort_unless(Forum::isModerator($request->user()),403);
        Forum::thread($id,$request->user());
        $data=$request->validate(['is_locked'=>'sometimes|required|boolean','is_pinned'=>'sometimes|required|boolean']);
        // A moderator's session role can be revoked, or their account banned,
        // after the preflight. Require a live, unbanned staff row in the same
        // UPDATE statement. Denied updates must never appear in the audit log.
        abort_unless($data, 422);
        $actorId = (int) $request->user()->id;
        $changed = ThreadModerateQuery::eligible($id, $actorId)->update($data);
        if ($changed === 0) {
            // MySQL can return zero affected rows for an authorized no-op PATCH.
            // Return success ONLY if the current staff role remains valid and
            // the stored flags match all requested values; don't emit an audit.
            $unchanged = ThreadModerateQuery::eligible($id, $actorId);
            foreach ($data as $column => $value) $unchanged->where($column, $value);
            abort_unless($unchanged->exists(), 403, __('server.category_post_forbidden'));
            return response()->json(['thread'=>Forum::threadData(Forum::thread($id,$request->user()))]);
        }
        abort_unless($changed === 1, 403, __('server.category_post_forbidden'));
        Forum::audit('thread.moderated',['thread_id'=>$id]+$data);
        return response()->json(['thread'=>Forum::threadData(Forum::thread($id,$request->user()))]);
    }

    public function deleteThread(Request $request,int $id)
    {
        abort_unless(Forum::isModerator($request->user()),403);
        Forum::thread($id,$request->user());
        // A moderator can be demoted or banned after the session/preflight
        // check. Re-authorize INSIDE the DELETE statement against the current
        // users row. Never audit or remove attachments after a denied delete.
        $paths=DB::transaction(function()use($id,$request){
            $paths=DB::table('attachments')->whereIn('post_id',DB::table('posts')->where('thread_id',$id)->select('id'))->pluck('path')->all();
            $deleted=ThreadDeleteQuery::eligible($id,(int)$request->user()->id)->delete();
            abort_unless($deleted === 1,403);
            Forum::audit('thread.deleted',['thread_id'=>$id]);
            return $paths;
        });
        Storage::disk('local')->delete($paths);
        return response()->json(['ok'=>true]);
    }

    public function bookmark(Request $request,int $id)
    {
        Forum::thread($id,$request->user());
        $actorId=(int)$request->user()->id;
        $key=['thread_id'=>$id,'user_id'=>$actorId];
        $exists=DB::table('bookmarks')->where($key)->exists();
        // The cached authenticated user can be banned (or the thread deleted)
        // between Forum::thread() and the toggle. A single guarded SQL
        // mutation must require CURRENT account and thread existence. The
        // unique bookmark pair remains protected against duplicate INSERTs.
        if ($exists) {
            $changed=BookmarkToggleQuery::eligibleDelete($id,$actorId)->delete();
        } else {
            $changed=DB::table('bookmarks')->insertOrIgnoreUsing(
                ['thread_id','user_id','created_at','updated_at'],
                BookmarkToggleQuery::eligibleInsert($id,$actorId,now()->toDateTimeString())
            );
        }
        // Do not claim toggled=true when a concurrent request, ban, or thread
        // deletion prevented the write. A stale request earns no false success.
        abort_unless($changed===1,403,__('server.category_post_forbidden'));
        return response()->json(['bookmarked'=>!$exists]);
    }

    public function editPost(Request $request,int $id)
    {
        $post=DB::table('posts')->find($id)??abort(404); $thread=Forum::thread($post->thread_id,$request->user());
        abort_unless(Forum::canReplyCategory(DB::table('categories')->find($thread->category_id), $request->user()), 403, __('server.category_post_forbidden'));
        abort_unless($post->user_id===$request->user()->id || Forum::isModerator($request->user()),403);
        abort_if($thread->is_locked && !Forum::isModerator($request->user()),403,__('server.thread_locked'));
        $data=$request->validate(['body'=>'required|string|min:1|max:50000']);
        // Moderator demotion, account ban, or thread lock may commit after
        // the User/Post/Thread preflight checks. Re-evaluate live authorization
        // inside the UPDATE; denied writes must not create an audit entry.
        $updated = PostEditQuery::eligible($id, (int) $request->user()->id)
            ->update($data + ['updated_at' => now()]);
        abort_unless($updated === 1, 403, __('server.category_post_forbidden'));
        Forum::audit('post.edited',['post_id'=>$id]);
        return response()->json(['post'=>Forum::postData(DB::table('posts')->find($id),$request->user())]);
    }

    public function deletePost(Request $request,int $id)
    {
        $post=DB::table('posts')->find($id)??abort(404); $thread=Forum::thread($post->thread_id,$request->user());
        abort_unless(Forum::canReplyCategory(DB::table('categories')->find($thread->category_id), $request->user()), 403, __('server.category_post_forbidden'));
        abort_unless($post->user_id===$request->user()->id || Forum::isModerator($request->user()),403);
        abort_if($thread->is_locked && !Forum::isModerator($request->user()),403);
        $paths = DB::transaction(function () use ($post, $id, $request) {
            // Serialise post removals in this thread before deciding which post
            // is the original. The ordinary request preflight may be stale.
            $thread = DB::table('threads')->where('id', $post->thread_id)->lockForUpdate()->first();
            abort_unless($thread, 404);
            $current = DB::table('posts')->where('id', $id)->where('thread_id', $post->thread_id)->first();
            abort_unless($current, 404);
            $firstId = (int) DB::table('posts')->where('thread_id', $post->thread_id)->min('id');
            abort_if($firstId === $id, 422, __('server.delete_first_post'));
            $paths = DB::table('attachments')->where('post_id', $id)->pluck('path')->all();

            // Live ban, staff role, ownership and thread lock are checked in
            // the actual DELETE, not only against the cached request actor.
            $deleted = PostDeleteQuery::eligible($id, (int) $request->user()->id, $post->thread_id, $firstId)->delete();
            abort_unless($deleted === 1, 403, __('server.category_post_forbidden'));
            Forum::audit('post.deleted', ['post_id' => $id]);
            return $paths;
        });
        // Never erase physical attachments after a rejected DB deletion.
        Storage::disk('local')->delete($paths);
        return response()->json(['ok'=>true]);
    }

    public function reaction(Request $request,int $id)
    {
        $post=DB::table('posts')->find($id)??abort(404); $thread=Forum::thread($post->thread_id,$request->user());
        abort_unless(Forum::canReplyCategory(DB::table('categories')->find($thread->category_id), $request->user()), 403, __('server.category_post_forbidden'));
        abort_if($post->user_id===$request->user()->id,422,__('server.self_reaction'));
        $actorId=(int)$request->user()->id;
        $key=['post_id'=>$id,'user_id'=>$actorId];
        $exists=DB::table('reactions')->where($key)->exists();
        // Existing preflight can become stale while the request is running:
        // a ban, account deletion or a post ownership change must not allow
        // either a new reaction or an unauthorized toggle removal.
        if ($exists) {
            $changed=ReactionToggleQuery::eligibleDelete($id,$actorId)->delete();
        } else {
            $time=now()->toDateTimeString();
            $candidate=ReactionToggleQuery::eligibleInsert($id,$actorId,$time);
            // The unique post/user constraint is preserved; concurrent double
            // inserts must not bypass the live eligibility predicate.
            $changed=DB::table('reactions')->insertOrIgnoreUsing(
                ['post_id','user_id','created_at','updated_at'],$candidate
            );
        }
        abort_unless($changed===1,403,__('server.category_post_forbidden'));
        return response()->json(['reacted'=>!$exists,'reactions_count'=>DB::table('reactions')->where('post_id',$id)->count()]);
    }

}
