<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

class ForumService
{
    public const VERSION = '0.1.0-beta.1';
    public const COLORS = ['violet','cyan','amber','rose','green','blue'];

    public static function installed(): bool
    {
        try { return Schema::hasTable('settings') && json_decode(DB::table('settings')->where('key', 'setup_complete')->value('value') ?? 'false', true) === true; }
        catch (\Throwable) { return false; }
    }

    public static function settings(): array
    {
        $languages = app(LanguageRegistry::class);
        $locale = $languages->defaultLocale();
        $text = $languages->messages($locale);
        $defaults = ['site_name'=>'WojteX Forum','tagline'=>$text['seed.tagline'],'welcome_title'=>$text['seed.welcome_title'],'welcome_text'=>$text['seed.welcome_text'],'theme'=>'dark','accent'=>'blue','registration_open'=>true,'default_locale'=>$locale];
        $stored = DB::table('settings')->whereIn('key', array_keys($defaults))->pluck('value','key')->map(fn ($v) => json_decode($v,true))->all();
        return array_replace($defaults, $stored, ['default_locale'=>$locale]);
    }

    public static function setSetting(string $key, mixed $value): void
    {
        DB::table('settings')->updateOrInsert(['key'=>$key], ['value'=>json_encode($value, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }

    public static function isModerator(?User $user): bool
    {
        return $user && !$user->is_banned && in_array($user->role, ['admin','moderator'], true);
    }

    /** Aggregate additive grants from all of a member's groups. No group grants moderator rights. */
    private static function memberCategoryGrants(?User $user): array
    {
        if (!$user || $user->is_banned || !Schema::hasTable('category_group_permissions')) return [];
        $rows = DB::table('category_group_permissions as p')
            ->join('user_group_members as m', 'm.group_id', '=', 'p.group_id')
            ->where('m.user_id', $user->id)
            ->get(['p.category_id', 'p.allow_view', 'p.allow_create', 'p.allow_reply']);
        $grants = [];
        foreach ($rows as $row) {
            $id = (int) $row->category_id;
            foreach (['view', 'create', 'reply'] as $ability) {
                $grants[$id][$ability] = ($grants[$id][$ability] ?? false) || (bool) $row->{'allow_'.$ability};
            }
        }
        return $grants;
    }

    private static function categoryAccess(object $category, ?User $user, string $ability): bool
    {
        if ($user?->is_banned) return false;
        if (self::isModerator($user)) return true;
        $all = DB::table('categories')->get()->keyBy('id')->all();
        return CategoryAccess::allows($all, (int) $category->id, self::memberCategoryGrants($user), $user !== null, $ability);
    }

    public static function canSeeCategory(object $category, ?User $user): bool
    {
        return self::categoryAccess($category, $user, 'view');
    }

    public static function canCreateCategory(object $category, ?User $user): bool
    {
        return self::categoryAccess($category, $user, 'create');
    }

    public static function canReplyCategory(object $category, ?User $user): bool
    {
        return self::categoryAccess($category, $user, 'reply');
    }

    public static function visibleCategoryIds(?User $user): array
    {
        if ($user?->is_banned) return [];
        $all = DB::table('categories')->get()->keyBy('id')->all();
        if (self::isModerator($user)) return array_keys($all);
        $access = CategoryAccess::accessMap($all, self::memberCategoryGrants($user), $user !== null);
        return array_values(array_map('intval', array_keys(array_filter($access,
            static fn (array $permissions): bool => $permissions['view']
        ))));
    }

    public static function categories(?User $user): array
    {
        $all = DB::table('categories')->get()->keyBy('id')->all();
        $grants = self::memberCategoryGrants($user);
        $staff = self::isModerator($user);
        $member = $user !== null;
        $access = $staff || $user?->is_banned
            ? [] : CategoryAccess::accessMap($all, $grants, $member);
        $visible = $user?->is_banned ? [] : ($staff ? array_keys($all) : array_keys(array_filter(
            $access, static fn (array $permissions): bool => $permissions['view']
        )));
        return DB::table('categories')->whereIn('id', $visible)->orderBy('sort_order')->orderBy('id')
            ->select('categories.*')->selectSub(DB::table('threads')->selectRaw('COUNT(*)')->whereColumn('category_id','categories.id'), 'threads_count')
            ->get()->map(fn ($c) => self::categoryData($c) + [
                'can_create' => $staff || (bool) ($access[(int) $c->id]['create'] ?? false),
            ])->all();
    }

    public static function categoryData(object $c): array
    {
        return ['id'=>(int)$c->id,'name'=>$c->name,'slug'=>$c->slug,'description'=>$c->description ?? '', 'color'=>$c->color,'icon'=>$c->icon,'sort_order'=>(int)$c->sort_order,'is_private'=>(bool)$c->is_private,'parent_id'=>$c->parent_id ? (int)$c->parent_id : null,'threads_count'=>(int)($c->threads_count ?? 0)];
    }

    public static function publicUser(object $user): array
    {
        $parts=preg_split('/\s+/u',trim($user->name));
        $initials=mb_strtoupper(mb_substr($parts[0]??'V',0,1).(count($parts)>1?mb_substr(end($parts),0,1):''));
        return ['id'=>(int)$user->id,'name'=>$user->name,'role'=>$user->role,'initials'=>$initials,'color'=>in_array($user->color??'', self::COLORS,true)?$user->color:'violet','bio'=>$user->bio??'','created_at'=>(string)$user->created_at];
    }

    public static function selfUser(User $user): array
    {
        return self::publicUser($user) + [
            'email' => $user->email,
            // Self-only preference: never disclose PM settings in public user data.
            'allow_private_messages' => (bool) ($user->allow_private_messages ?? true),
        ];
    }

    public static function threadQuery(?User $user)
    {
        return DB::table('threads as t')->whereIn('t.category_id', self::visibleCategoryIds($user))
            ->select('t.*')
            ->selectSub(DB::table('posts')->selectRaw('COUNT(*)')->whereColumn('thread_id','t.id'),'posts_count')
            ->selectSub(DB::table('posts')->select('body')->whereColumn('thread_id','t.id')->orderBy('id')->limit(1),'first_body')
            ->selectSub(DB::table('reactions')->join('posts','posts.id','=','reactions.post_id')->selectRaw('COUNT(*)')->whereColumn('posts.thread_id','t.id'),'reactions_count')
            ->selectSub(DB::table('bookmarks')->selectRaw('COUNT(*)')->whereColumn('thread_id','t.id')->where('user_id',$user?->id??0),'bookmarked');
    }

    /** Serialize one topic for create/moderation/detail responses. */
    public static function threadData(object $t): array
    {
        return self::formatThread($t,
            DB::table('users')->find($t->user_id),
            DB::table('categories')->find($t->category_id)
        );
    }

    /**
     * Serialize only the already-authorized, 15-row topic listing page.
     * Reuse two bounded relations for all rows instead of one author and one
     * category lookup per thread. Visibility remains in threadQuery().
     */
    public static function threadsData(Collection $threads): Collection
    {
        if ($threads->isEmpty()) return $threads;
        $authors = DB::table('users')->whereIn('id', $threads->pluck('user_id')->unique()->all())
            ->get()->keyBy('id');
        $categories = DB::table('categories')->whereIn('id', $threads->pluck('category_id')->unique()->all())
            ->get()->keyBy('id');
        return $threads->map(fn ($t) => self::formatThread(
            $t, $authors->get($t->user_id), $categories->get($t->category_id)
        ));
    }

    private static function formatThread(object $t, object $author, object $category): array
    {
        return ['id'=>(int)$t->id,'title'=>$t->title,'slug'=>$t->slug,'category_id'=>(int)$t->category_id,'user_id'=>(int)$t->user_id,'is_pinned'=>(bool)$t->is_pinned,'is_locked'=>(bool)$t->is_locked,'views'=>(int)$t->views,'created_at'=>$t->created_at,'updated_at'=>$t->updated_at,'author'=>self::publicUser($author),'category'=>self::categoryData($category),'replies_count'=>max(0,(int)($t->posts_count??1)-1),'reactions_count'=>(int)($t->reactions_count??0),'excerpt'=>Str::limit(preg_replace('/\s+/u',' ',strip_tags($t->first_body??'')),150),'bookmarked'=>(bool)($t->bookmarked??false)];
    }

    public static function thread(int $id, ?User $user): object
    {
        return self::threadQuery($user)->where('t.id',$id)->first() ?? abort(404, __('server.thread_unavailable'));
    }

    public static function bodyHtml(string $body, string $format='markdown'): string
    {
        if ($format==='bbcode') {
            // Preserve unknown BBCode verbatim. Render only a conservative non-HTML subset.
            $safe=e($body);
            foreach (['b'=>'strong','i'=>'em','u'=>'u','s'=>'s','quote'=>'blockquote','code'=>'pre'] as $bb=>$tag) {
                $safe=preg_replace('/\['.$bb.'\](.*?)\[\/'.$bb.'\]/is','<'.$tag.'>$1</'.$tag.'>',$safe);
            }
            return '<div class="legacy-post">'.nl2br($safe).'</div>';
        }
        return Str::markdown($body,['html_input'=>'strip','allow_unsafe_links'=>false,'max_nesting_level'=>20]);
    }

    /**
     * Serialize an individual post for mutation responses. This path is kept
     * independent of the page loader because it handles only a single row.
     */
    public static function postData(object $post, ?User $user): array
    {
        $author = DB::table('users')->find($post->user_id);
        $reactions = DB::table('reactions')->where('post_id', $post->id)->count();
        $reacted = $user ? DB::table('reactions')
            ->where(['post_id' => $post->id, 'user_id' => $user->id])->exists() : false;
        $attachments = DB::table('attachments')->where('post_id', $post->id)
            ->get(['id', 'name', 'mime', 'size']);
        return self::formatPost($post, $author, (int) $reactions, (bool) $reacted, $attachments);
    }

    /**
     * Render only posts from the already-authorized, paginated topic page.
     * A 40-post page performs four bulk reads for a signed-in visitor (three
     * for a guest), rather than up to 160 per-post lookups. Post visibility
     * remains the responsibility of ForumController::show/Forum::thread.
     */
    public static function postsData(Collection $posts, ?User $user): Collection
    {
        if ($posts->isEmpty()) return $posts;
        $postIds = $posts->pluck('id')->map(fn ($id) => (int) $id)->all();
        $authorIds = $posts->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->all();
        $authors = DB::table('users')->whereIn('id', $authorIds)->get()->keyBy('id');
        $counts = DB::table('reactions')->whereIn('post_id', $postIds)
            ->select('post_id')->selectRaw('COUNT(*) AS total')->groupBy('post_id')
            ->pluck('total', 'post_id');
        $reacted = $user ? array_fill_keys(
            DB::table('reactions')->whereIn('post_id', $postIds)
                ->where('user_id', $user->id)->pluck('post_id')->all(), true
        ) : [];
        $attachments = DB::table('attachments')->whereIn('post_id', $postIds)
            ->orderBy('id')->get(['post_id', 'id', 'name', 'mime', 'size'])
            ->groupBy('post_id');

        return $posts->map(fn ($post) => self::formatPost(
            $post,
            $authors->get($post->user_id),
            (int) ($counts->get($post->id) ?? 0),
            isset($reacted[$post->id]),
            $attachments->get($post->id) ?? collect()
        ));
    }

    private static function formatPost(object $post, object $author, int $reactions, bool $reacted, Collection $attachments): array
    {
        return [
            'id'=>(int)$post->id,'thread_id'=>(int)$post->thread_id,
            'user_id'=>(int)$post->user_id,'body'=>$post->body,
            'body_format'=>$post->body_format,
            'body_html'=>self::bodyHtml($post->body,$post->body_format),
            'created_at'=>$post->created_at,'updated_at'=>$post->updated_at,
            'author'=>self::publicUser($author),'reactions_count'=>$reactions,
            'reacted'=>$reacted,
            'attachments'=>$attachments->map(static function ($attachment) {
                $data = (array) $attachment;
                // The batched SELECT includes post_id for grouping, but the
                // public attachment payload never exposed that extra column.
                unset($data['post_id']);
                return $data + ['url'=>'/attachments/'.$attachment->id];
            })->all(),
        ];
    }

    public static function stats(?User $user): array
    {
        $threads=DB::table('threads')->whereIn('category_id',self::visibleCategoryIds($user));
        return ['users'=>DB::table('users')->where('is_banned',false)->count(),'threads'=>(clone $threads)->count(),'posts'=>DB::table('posts')->whereIn('thread_id',(clone $threads)->select('id'))->count(),'shouts'=>DB::table('shouts')->count()];
    }

    public static function audit(string $action,array $details=[],?int $userId=null): void
    {
        DB::table('audit_logs')->insert(['user_id'=>$userId??auth()->id(),'action'=>$action,'details'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
    }
}
