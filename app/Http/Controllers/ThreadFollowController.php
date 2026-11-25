<?php

namespace App\Http\Controllers;

use App\Services\ForumService as Forum;
use App\Services\ThreadFollows;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ThreadFollowController extends Controller
{
    public function store(Request $request, int $id): JsonResponse
    {
        $viewerId = (int) $request->user()->id;
        $thread = $this->visibleThread($request, $id);
        $source = $thread->whereExists(ThreadFollows::activeViewer($viewerId))
            ->selectRaw('?, t.id, COALESCE((SELECT MAX(p.id) FROM posts AS p WHERE p.thread_id = t.id), 0)', [$viewerId]);
        // Retrying a follow must preserve a watermark that already exists.
        DB::table('thread_follows')->insertOrIgnoreUsing(['user_id', 'thread_id', 'last_read_post_id'], $source);
        $follow = ThreadFollows::status($id, $viewerId);
        abort_unless($follow, 403, __('server.account_blocked'));

        return response()->json(['follow' => $follow]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->visibleThread($request, $id);
        ThreadFollows::writableFollow($id, (int) $request->user()->id)->delete();

        return response()->json(['follow' => null]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['post_id' => 'required|integer|min:1']);
        $this->visibleThread($request, $id);
        $postId = (int) $data['post_id'];
        $post = DB::table('posts')->where('thread_id', $id)->where('id', $postId);
        abort_unless($post->exists(), 404, __('server.post_unavailable'));
        $viewerId = (int) $request->user()->id;

        // The UPDATE stays monotonic when two tabs report progress out of order.
        ThreadFollows::writableFollow($id, $viewerId)->where('last_read_post_id', '<', $postId)
            ->whereExists($post->selectRaw('1'))->update(['last_read_post_id' => $postId]);

        return response()->json(['follow' => ThreadFollows::status($id, $viewerId)]);
    }

    private function visibleThread(Request $request, int $id): Builder
    {
        $thread = Forum::visibleThreads($request->user())->where('t.id', $id);
        abort_unless($thread->exists(), 404, __('server.thread_unavailable'));

        return $thread;
    }
}
