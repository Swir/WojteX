<?php
namespace App\Http\Controllers;

use App\Services\ForumService as Forum;
use App\Services\ExtensionRegistry;
use App\Services\LanguageRegistry;
use App\Services\ThreadSearchQuery;
use App\Services\ThreadFollows;
use App\Services\ThreadDeleteQuery;
use App\Services\ThreadModerateQuery;
use App\Services\ThreadCreateQuery;
use App\Services\ThreadReplyQuery;
use App\Services\PostEditQuery;
use App\Services\PostDeleteQuery;
use App\Services\ReactionToggleQuery;
use App\Services\BookmarkToggleQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ForumController extends Controller
{
    private const POSTS_PER_PAGE = 40;

    public function bootstrap(Request $request)
    {
        $user = $request->user();
        $community = $request->query('stats') === '0'
            ? ['categories' => Forum::categories($user)]
            : Forum::summary($user);

        $data = [
            'user' => $user ? Forum::selfUser($user) : null,
            'csrf_token' => csrf_token(),
            'settings' => Forum::settings(),
            ...$community,
            'plugins' => ExtensionRegistry::catalog(),
            'version' => Forum::VERSION,
        ];
        if ($request->query('page_locale') !== app()->getLocale()) {
            $data['i18n'] = app(LanguageRegistry::class)->payload();
        }

        return response()->json($data);
    }

    public function summary(Request $request)
    {
        return response()->json(Forum::summary($request->user()));
    }

    public function threads(Request $request)
    {
        $data = $request->validate([
            'category' => 'nullable|integer|min:1',
            'q' => 'nullable|string|max:150',
            'sort' => 'nullable|in:latest,popular,unanswered,recently_saved',
            'page' => 'nullable|integer|min:1|max:100000',
            'bookmarked' => 'nullable|boolean',
            'following' => 'nullable|boolean',
            'unread' => 'nullable|boolean',
        ]);
        $sort = $data['sort'] ?? 'latest';
        $bookmarked = $request->boolean('bookmarked');
        $user = $request->user();
        $query = Forum::threadQuery($user);
        if (!empty($data['category'])) $query->where('t.category_id', $data['category']);
        if (ThreadSearchQuery::shouldSearch($data['q'] ?? null)) {
            // Search literal text within visible threads; SQL wildcard characters
            // supplied by visitors must not expand into accidental full scans.
            $query = ThreadSearchQuery::apply($query, $data['q']);
        }
        if ($bookmarked) {
            abort_unless($user, 401, __('server.bookmarks_login'));
            $query->join('bookmarks as saved_bookmark', static function (JoinClause $join) use ($user): void {
                $join->on('saved_bookmark.thread_id', '=', 't.id')->where('saved_bookmark.user_id', $user->id);
            });
        }
        $unread = $request->boolean('unread');
        if ($request->boolean('following') || $unread) {
            abort_unless($user, 401, __('server.following_login'));
            ThreadFollows::filter($query, (int) $user->id, $unread);
        }
        if ($sort === 'unanswered') $query->whereRaw('(SELECT COUNT(*) FROM posts WHERE posts.thread_id=t.id) = 1');
        if ($bookmarked && $sort === 'recently_saved') {
            $query->orderByDesc('saved_bookmark.created_at')->orderByDesc('saved_bookmark.id');
        } else {
            $query->orderByDesc('t.is_pinned');
            if ($sort === 'popular') $query->orderByDesc('reactions_count')->orderByDesc('posts_count');
            $query->orderByDesc('t.updated_at')->orderByDesc('t.id');
        }
        $page = $query->paginate(15);
        return response()->json(['data'=>Forum::threadsData($page->getCollection()),'current_page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'total'=>$page->total()]);
    }

    public function quickSearch(Request $request)
    {
        $data = $request->validate(['q' => 'required|string|min:2|max:150']);
        $threads = Forum::threadQuery($request->user())->select('t.id', 't.title', 't.category_id');
        $results = ThreadSearchQuery::applyTitle($threads, $data['q'])
            ->orderByDesc('t.updated_at')->orderByDesc('t.id')->limit(6)->get();

        return response()->json(['data' => $results]);
    }

    public function previewPost(Request $request)
    {
        $data = $request->validate([
            'body' => 'required|string|max:50000',
            'format' => 'required|in:markdown,bbcode',
        ]);

        return response()->json(['html' => Forum::bodyHtml($data['body'], $data['format'])]);
    }

    public function searchPosts(Request $request, int $id)
    {
        $data = $request->validate(['q' => 'required|string|min:2|max:150', 'page' => 'nullable|integer|min:1|max:100000']);
        $term = trim($data['q']);
        $visible = Forum::visibleThreads($request->user())->where('t.id', $id);
        abort_unless($visible->exists(), 404, __('server.thread_unavailable'));

        $posts = DB::table('posts')->whereIn('posts.thread_id', $visible->select('t.id'));
        $page = ThreadSearchQuery::applyPostBody($posts, $term)->orderBy('posts.id')
            ->simplePaginate(10, ['posts.id', 'posts.body', 'posts.created_at'], 'page', $data['page'] ?? 1);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($post) => [
                'id' => (int) $post->id,
                'excerpt' => $this->postSearchExcerpt($post->body, $term),
                'created_at' => $post->created_at,
            ])->values(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    private function postSearchExcerpt(string $body, string $term): string
    {
        $length = 220;
        $position = mb_stripos($body, $term);
        $start = $position === false ? 0 : max(0, $position - 45);
        $excerpt = trim(preg_replace('/\s+/u', ' ', mb_substr($body, $start, $length)) ?? '');

        return ($start > 0 ? '…' : '').$excerpt.(mb_strlen($body) > $start + $length ? '…' : '');
    }

    public function create(Request $request)
    {
        $data=$request->validate(['category_id'=>'required|integer','title'=>'required|string|min:5|max:180','body'=>'required|string|min:3|max:50000']);
        $category=DB::table('categories')->find($data['category_id']);
        abort_unless($category && Forum::canSeeCategory($category,$request->user()),404,__('server.category_not_found'));
        abort_unless(Forum::canCreateCategory($category,$request->user()),403,__('server.category_post_forbidden'));
        [$id, $postId]=DB::transaction(function() use($data,$request) {
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
            $postId=DB::table('posts')->insertGetId(['thread_id'=>$id,'user_id'=>$actorId,'body'=>$data['body'],'body_format'=>'markdown','created_at'=>now(),'updated_at'=>now()]);
            return [$id, (int) $postId];
        });
        Event::dispatch('veyra.thread.created',[$id,$request->user()->id]);
        return response()->json(['thread'=>Forum::threadData(Forum::thread($id,$request->user())), 'post_id' => $postId],201);
    }

    public function show(Request $request,int $id)
    {
        $data = $request->validate([
            'page' => 'nullable|integer|min:1|max:100000',
            'post' => 'nullable|integer|min:1',
            'unread' => 'nullable|boolean',
            'latest' => 'nullable|boolean',
        ]);
        $viewer = $request->user();
        $thread=Forum::thread($id,$viewer);
        $follow = $viewer ? ThreadFollows::status($id, (int) $viewer->id) : null;
        $postId = isset($data['post']) ? (int) $data['post']
            : ($request->boolean('unread') ? ($follow['first_unread_post_id'] ?? null) : null);
        $query = DB::table('posts')->where('thread_id', $id)->orderBy('id');
        if ($postId === null && $request->boolean('latest')) {
            $posts = $this->latestPostsPage($query);
            $latest = $posts->getCollection()->last();
            $postId = $latest ? (int) $latest->id : null;
        } else {
            $page = isset($data['page']) ? (int) $data['page'] : null;
            if ($postId !== null && $page === null) {
                $page = $this->pageContainingPost($id, $postId);
            }
            $posts = $query->paginate(self::POSTS_PER_PAGE, ['*'], 'page', $page);
            if ($postId !== null && !$posts->getCollection()->contains('id', $postId)) {
                // A link with an older page number can outlive deleted replies.
                $posts = $query->paginate(self::POSTS_PER_PAGE, ['*'], 'page', $this->pageContainingPost($id, $postId));
                abort_unless($posts->getCollection()->contains('id', $postId), 404, __('server.post_unavailable'));
            }
        }
        // Count one view per topic per session, bounded to the latest 100 topic ids.
        $seen=$request->session()->get('viewed_threads',[]);
        if (!in_array($id,$seen,true)) {
            DB::table('threads')->where('id',$id)->increment('views'); ++$thread->views;
            $seen[]=$id; $request->session()->put('viewed_threads',array_slice($seen,-100));
        }
        return response()->json([
            'thread' => Forum::threadData($thread),
            'posts' => Forum::postsData($posts->getCollection(), $viewer),
            'page' => $posts->currentPage(),
            'last_page' => $posts->lastPage(),
            'total_posts' => $posts->total(),
            'can_reply' => Forum::canReplyCategory(DB::table('categories')->find($thread->category_id), $viewer),
            'follow' => $follow,
            'target_post_id' => $postId,
        ]);
    }

    private function latestPostsPage(Builder $query): LengthAwarePaginator
    {
        $total = $query->getCountForPagination();
        $page = $total > 0 ? intdiv($total - 1, self::POSTS_PER_PAGE) + 1 : 1;
        $size = $total - ($page - 1) * self::POSTS_PER_PAGE;
        // Read the end of the thread's (thread_id, id) index instead of applying a deep OFFSET.
        $rows = $query->reorder('id', 'desc')->limit($size)->get()->reverse()->values();

        return new LengthAwarePaginator($rows, $total, self::POSTS_PER_PAGE, $page);
    }

    private function pageContainingPost(int $threadId, int $postId): int
    {
        $position = DB::table('posts')->where('thread_id', $threadId)->where('id', '<=', $postId)
            ->selectRaw('COUNT(*) AS post_position, MAX(id) AS last_id')->first();
        abort_unless((int) $position->last_id === $postId, 404, __('server.post_unavailable'));

        return intdiv((int) $position->post_position - 1, self::POSTS_PER_PAGE) + 1;
    }

    public function reply(Request $request,int $id)
    {
        $data=$request->validate(['body'=>'required|string|min:1|max:50000']);
        $thread=Forum::thread($id,$request->user());
        abort_unless(Forum::canReplyCategory(DB::table('categories')->find($thread->category_id), $request->user()), 403, __('server.category_post_forbidden'));
        abort_if($thread->is_locked && !Forum::isModerator($request->user()),403,__('server.thread_locked'));
        [$postId, $page] = DB::transaction(function()use($id,$request,$data){
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
            return [$postId, $this->pageContainingPost($id, $postId)];
        });
        Event::dispatch('veyra.post.created',[$postId,$request->user()->id]);
        return response()->json([
            'post' => Forum::postData(DB::table('posts')->find($postId), $request->user()),
            'page' => $page,
        ], 201);
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
        $changed = $this->writeBookmarkRow($id, $actorId, !$exists);
        // Do not claim toggled=true when a concurrent request, ban, or thread
        // deletion prevented the write. A stale request earns no false success.
        abort_unless($changed===1,403,__('server.category_post_forbidden'));
        return response()->json(['bookmarked'=>!$exists]);
    }

    public function saveBookmark(Request $request, int $id)
    {
        return $this->setBookmark($request, $id, true);
    }

    public function removeBookmark(Request $request, int $id)
    {
        return $this->setBookmark($request, $id, false);
    }

    private function setBookmark(Request $request, int $id, bool $desired)
    {
        $user = $request->user();
        abort_unless(Forum::visibleThreads($user)->where('t.id', $id)->exists(), 404, __('server.thread_unavailable'));
        $actorId = (int) $user->id;

        $this->writeBookmarkRow($id, $actorId, $desired);
        $bookmarked = DB::table('bookmarks')->where(['thread_id' => $id, 'user_id' => $actorId])->exists();
        abort_unless($bookmarked === $desired, 403, __('server.category_post_forbidden'));

        return response()->json(['bookmarked' => $bookmarked]);
    }

    private function writeBookmarkRow(int $id, int $actorId, bool $bookmarked): int
    {
        if (!$bookmarked) {
            return BookmarkToggleQuery::eligibleDelete($id, $actorId)->delete();
        }

        return DB::table('bookmarks')->insertOrIgnoreUsing(
            ['thread_id', 'user_id', 'created_at', 'updated_at'],
            BookmarkToggleQuery::eligibleInsert($id, $actorId, now()->toDateTimeString())
        );
    }

    public function editPost(Request $request,int $id)
    {
        $post=DB::table('posts')->find($id)??abort(404); $thread=Forum::thread($post->thread_id,$request->user());
        abort_unless(Forum::canReplyCategory(DB::table('categories')->find($thread->category_id), $request->user()), 403, __('server.category_post_forbidden'));
        abort_unless($post->user_id===$request->user()->id || Forum::isModerator($request->user()),403);
        abort_if($thread->is_locked && !Forum::isModerator($request->user()),403,__('server.thread_locked'));
        $data = $request->validate([
            'body' => 'required|string|min:1|max:50000',
            'revision' => 'sometimes|required|integer|min:0',
        ]);
        // Moderator demotion, account ban, or thread lock may commit after
        // the User/Post/Thread preflight checks. Re-evaluate live authorization
        // inside the UPDATE; denied writes must not create an audit entry.
        $actor = (int) $request->user()->id;
        $eligible = PostEditQuery::eligible($id, $actor);
        $update = isset($data['revision']) ? (clone $eligible)->where('p.edit_version', (int) $data['revision']) : $eligible;
        $updated = $update->update([
            'body' => $data['body'], 'updated_at' => now(), 'edit_version' => new Expression('edit_version + 1'),
        ]);
        if ($updated !== 1 && isset($data['revision']) && $eligible->exists()) {
            abort(409, __('server.post_edit_conflict'));
        }
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
