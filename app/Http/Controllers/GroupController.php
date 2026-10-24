<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ForumService as Forum;
use App\Services\GroupMemberLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Additive category grants for private rooms, entirely administered by owners. */
class GroupController extends Controller
{
    public function index(): JsonResponse
    {
        $groups = DB::table('forum_groups')->orderBy('name')->get();
        $permissions = DB::table('category_group_permissions')->get()->groupBy('group_id');
        // The administrator's user tab currently shows 100 recent accounts.
        // Scope membership indicators to the same bounded list.
        $displayed = User::orderByDesc('id')->limit(100)->pluck('id');
        $members = DB::table('user_group_members')->whereIn('user_id', $displayed)->get()->groupBy('group_id');
        $memberCounts = GroupMemberLookup::memberCounts()->get()->keyBy('group_id');
        return response()->json(['groups' => $groups->map(fn ($group) => [
            'id' => (int) $group->id, 'name' => $group->name,
            'member_count' => (int) ($memberCounts->get($group->id)->member_count ?? 0),
            'member_ids' => ($members->get($group->id) ?? collect())->pluck('user_id')->map(fn ($v) => (int) $v)->values(),
            'permissions' => ($permissions->get($group->id) ?? collect())->map(fn ($p) => [
                'category_id' => (int) $p->category_id,
                'view' => (bool) $p->allow_view,
                'create' => (bool) $p->allow_create,
                'reply' => (bool) $p->allow_reply,
            ])->values(),
        ])->values()]);
    }

    /** Search all accounts by literal name or explicit #ID; dashboard is limited to 100. */
    public function searchUsers(Request $request): JsonResponse
    {
        if (is_string($request->input('q'))) {
            $request->merge(['q' => trim($request->input('q'))]);
        }
        $data = $request->validate(['q' => 'required|string|min:2|max:80']);
        $users = GroupMemberLookup::search($data['q'])->get(['id', 'name', 'is_banned']);
        $membership = DB::table('user_group_members')->whereIn('user_id', $users->pluck('id'))
            ->get(['user_id', 'group_id'])->groupBy('user_id');
        return response()->json(['data' => $users->map(static fn ($user) => [
            'id' => (int) $user->id,
            'name' => $user->name,
            'is_banned' => (bool) $user->is_banned,
            'group_ids' => ($membership->get($user->id) ?? collect())->pluck('group_id')
                ->map(static fn ($v) => (int) $v)->values(),
        ])->values()]);
    }

    private function group(int $id): object
    {
        return DB::table('forum_groups')->find($id) ?? abort(404, __('server.group_not_found'));
    }

    private function validSlug(string $name): string
    {
        $slug = Str::slug($name);
        abort_if($slug === '', 422, __('server.group_invalid_name'));
        return $slug;
    }

    /**
     * Keep the acting administrator's CURRENT DB row locked until group grants,
     * membership, and audit are committed. On MySQL/PostgreSQL this serializes
     * a concurrent ban/demotion of that same actor with the group mutation.
     * SQLite does not implement SELECT FOR UPDATE; no portable global
     * serializability or cross-database isolation is claimed.
     */
    private function authorizedMutation(Request $request, \Closure $mutation): mixed
    {
        $actorId = (int) ($request->user()?->id ?? 0);
        return DB::transaction(static function () use ($actorId, $mutation) {
            $current = DB::table('users')->where('id', $actorId)
                ->lockForUpdate()->first(['role', 'is_banned']);
            abort_unless($current && $current->role === 'admin' && !(bool) $current->is_banned,
                403, __('server.admin_only'));
            return $mutation();
        });
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|min:2|max:80']);
        $slug = $this->validSlug($data['name']);
        $request->validate(['name' => [Rule::unique('forum_groups', 'name')]]);
        return $this->authorizedMutation($request, static function () use ($data, $slug) {
            abort_if(DB::table('forum_groups')->where('slug', $slug)->exists(), 422, __('server.group_exists'));
            $id = DB::table('forum_groups')->insertGetId(['name' => $data['name'], 'slug' => $slug,
                'created_at' => now(), 'updated_at' => now()]);
            Forum::audit('group.created', ['group_id' => $id]);
            return response()->json(['id' => $id], 201);
        });
    }

    public function rename(Request $request, int $id): JsonResponse
    {
        $this->group($id);
        $data = $request->validate(['name' => 'required|string|min:2|max:80']);
        $slug = $this->validSlug($data['name']);
        return $this->authorizedMutation($request, static function () use ($id, $data, $slug) {
            abort_if(DB::table('forum_groups')->where('slug', $slug)->where('id', '<>', $id)->exists(),
                422, __('server.group_exists'));
            $updated = DB::table('forum_groups')->where('id', $id)
                ->update(['name' => $data['name'], 'slug' => $slug, 'updated_at' => now()]);
            abort_unless($updated === 1, 404, __('server.group_not_found'));
            Forum::audit('group.renamed', ['group_id' => $id]);
            return response()->json(['ok' => true]);
        });
    }

    public function delete(Request $request, int $id): JsonResponse
    {
        $this->group($id);
        return $this->authorizedMutation($request, static function () use ($id) {
            $deleted = DB::table('forum_groups')->where('id', $id)->delete();
            abort_unless($deleted === 1, 404, __('server.group_not_found'));
            // FK cascades revoke all group permissions and memberships.
            Forum::audit('group.deleted', ['group_id' => $id]);
            return response()->json(['ok' => true]);
        });
    }

    public function member(Request $request, int $id, int $userId): JsonResponse
    {
        $this->group($id);
        User::findOrFail($userId);
        $data = $request->validate(['enabled' => 'required|boolean']);
        return $this->authorizedMutation($request, static function () use ($id, $userId, $data) {
            if ($data['enabled']) {
                DB::table('user_group_members')->insertOrIgnore(['group_id' => $id, 'user_id' => $userId]);
            } else {
                DB::table('user_group_members')->where(['group_id' => $id, 'user_id' => $userId])->delete();
            }
            Forum::audit('group.member.updated', ['group_id' => $id, 'user_id' => $userId,
                'enabled' => (bool) $data['enabled']]);
            return response()->json(['ok' => true]);
        });
    }

    public function permission(Request $request, int $id, int $categoryId): JsonResponse
    {
        $this->group($id);
        abort_unless(DB::table('categories')->where('id', $categoryId)->exists(), 404,
            __('server.category_not_found'));
        $data = $request->validate(['view' => 'required|boolean', 'create' => 'required|boolean',
            'reply' => 'required|boolean']);
        abort_if(!$data['view'] && ($data['create'] || $data['reply']), 422,
            __('server.group_view_required'));
        return $this->authorizedMutation($request, static function () use ($id, $categoryId, $data) {
            $key = ['group_id' => $id, 'category_id' => $categoryId];
            if (!$data['view'] && !$data['create'] && !$data['reply']) {
                DB::table('category_group_permissions')->where($key)->delete();
            } else {
                DB::table('category_group_permissions')->updateOrInsert($key, [
                    'allow_view' => (bool) $data['view'],
                    'allow_create' => (bool) $data['create'],
                    'allow_reply' => (bool) $data['reply'],
                ]);
            }
            Forum::audit('group.permissions.updated', ['group_id' => $id,
                'category_id' => $categoryId] + $data);
            return response()->json(['ok' => true]);
        });
    }
}
