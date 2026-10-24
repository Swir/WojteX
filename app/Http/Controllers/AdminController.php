<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Services\ForumService as Forum;
use App\Services\ExtensionRegistry;
use App\Services\LanguageRegistry;
use App\Services\AdminUserLookup;
use App\Services\AdminBanQuery;
use App\Services\CategoryHierarchy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function show(Request $request) {
        return response()->json(['settings'=>Forum::settings(),'stats'=>Forum::stats($request->user()),'plugins'=>ExtensionRegistry::catalog(),'categories'=>Forum::categories($request->user()),'users'=>User::orderByDesc('id')->limit(100)->get()->map(fn($u)=>Forum::publicUser($u)+['email'=>$u->email,'is_banned'=>(bool)$u->is_banned]),'recent_actions'=>DB::table('audit_logs')->orderByDesc('id')->limit(25)->get()->map(fn($a)=>['id'=>$a->id,'action'=>$a->action,'details'=>json_decode($a->details,true),'created_at'=>$a->created_at])]);
    }
    /** Moderators cannot use this: e-mail lookup is restricted by /api/admin middleware. */
    public function searchUsers(Request $request) {
        if (is_string($request->input('q'))) {
            $request->merge(['q' => trim($request->input('q'))]);
        }
        $data = $request->validate(['q' => 'required|string|min:2|max:80']);
        $results = AdminUserLookup::search($data['q'])->get([
            'id', 'name', 'email', 'role', 'is_banned', 'created_at',
        ]);
        return response()->json(['data' => $results->map(static fn ($u) => [
            'id' => (int) $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'is_banned' => (bool) $u->is_banned,
            'created_at' => $u->created_at,
        ])->values()]);
    }

    public function settings(Request $request) {
        $data=$request->validate(['site_name'=>'sometimes|required|string|max:80','tagline'=>'sometimes|required|string|max:160','welcome_title'=>'sometimes|required|string|max:180','welcome_text'=>'sometimes|required|string|max:500','theme'=>'sometimes|required|in:dark,midnight,light,system','accent'=>'sometimes|required|in:blue,violet,cyan,green,amber,rose','registration_open'=>'sometimes|required|boolean','default_locale'=>['sometimes','required','string',Rule::in(array_column(app(LanguageRegistry::class)->catalog(),'code'))]]);
        DB::transaction(function()use($data){foreach($data as $k=>$v)Forum::setSetting($k,$k==='registration_open'?(bool)$v:$v);Forum::audit('settings.updated',['keys'=>array_keys($data)]);});
        return response()->json(['settings'=>Forum::settings()]);
    }
    public function plugin(Request $request,string $id) {
        $data=$request->validate(['enabled'=>'required|boolean']);
        abort_unless(collect(ExtensionRegistry::catalog())->contains('id',$id),404,__('server.extension_not_found'));
        DB::table('plugin_states')->updateOrInsert(['key'=>$id],['enabled'=>$data['enabled'],'created_at'=>now(),'updated_at'=>now()]);
        Forum::audit('plugin.updated',['id'=>$id,'enabled'=>$data['enabled']]);
        return response()->json(['plugins'=>ExtensionRegistry::catalog()]);
    }
    public function user(Request $request,int $id) {
        $data=$request->validate(['is_banned'=>'required|boolean']);
        $user=User::findOrFail($id);
        abort_if($user->id===$request->user()->id||$user->role==='admin',422,__('server.admin_ban'));

        // A cached administrator may be demoted/banned, or the target may be
        // promoted to administrator, AFTER the preflight. Check both LIVE
        // database rows within this single UPDATE, not via Eloquent::save().
        $eligible=AdminBanQuery::eligible($id,(int)$request->user()->id);
        $changed=$eligible->update(AdminBanQuery::values((bool)$data['is_banned'],now()));
        if ($changed===0) {
            // MySQL may report zero affected rows for an authorized no-op.
            // Never treat a denied write as a successful no-op or audit it.
            abort_unless(AdminBanQuery::eligible($id,(int)$request->user()->id)
                ->where(AdminBanQuery::banColumn(),(bool)$data['is_banned'])->exists(),403,__('server.admin_only'));
        } else {
            abort_unless($changed===1,403,__('server.admin_only'));
            Forum::audit('user.ban.updated',['user_id'=>$id,'is_banned'=>(bool)$data['is_banned']]);
        }
        $user=User::findOrFail($id);
        return response()->json(['user'=>Forum::publicUser($user)+['is_banned'=>(bool)$user->is_banned]]);
    }
    private function categoryInput(Request $request,bool $create): array {
        return $request->validate(['name'=>($create?'required':'sometimes|required').'|string|min:2|max:120','description'=>'nullable|string|max:500','color'=>['sometimes','required',Rule::in(Forum::COLORS)],'icon'=>'sometimes|required|in:code,chat,rocket,game,book,help,shield,star,folder,terminal','is_private'=>'sometimes|required|boolean','parent_id'=>'nullable|integer|exists:categories,id','sort_order'=>'sometimes|integer|min:0|max:10000']);
    }
    public function createCategory(Request $request) {
        $data=$this->categoryInput($request,true);$data['slug']=(Str::slug($data['name'])?:'dzial').'-'.Str::lower(Str::random(6));
        $id=DB::table('categories')->insertGetId($data+['description'=>'','color'=>'violet','icon'=>'code','is_private'=>false,'sort_order'=>0,'created_at'=>now(),'updated_at'=>now()]);
        Forum::audit('category.created',['category_id'=>$id]);
        return response()->json(['category'=>Forum::categoryData(DB::table('categories')->find($id))],201);
    }
    public function category(Request $request,int $id) {
        $data=$this->categoryInput($request,false);
        return DB::transaction(static function () use ($id, $data) {
            // Two different administrators can independently validate A -> B
            // and B -> A before either UPDATE. Lock one shared, durable row
            // BEFORE walking the ancestor chain and keep it until commit.
            // Every category reparent endpoint uses this same mutex; locking
            // only the target category would NOT prevent a cross-row cycle.
            // InnoDB/PostgreSQL serialize these transactions. SQLite has no
            // SELECT FOR UPDATE: real concurrent SQLite behavior is unproven.
            $lockKey = 'category_hierarchy_mutation_lock';
            DB::table('settings')->insertOrIgnore(['key' => $lockKey, 'value' => 'true']);
            DB::table('settings')->where('key', $lockKey)->lockForUpdate()->first();

            $category=DB::table('categories')->find($id)??abort(404);
            if (array_key_exists('parent_id', $data)) {
                // This must run AFTER acquiring the global hierarchy lock:
                // a concurrent administrator may have reparented an ancestor.
                abort_unless(CategoryHierarchy::allows($id, $data['parent_id'], static fn (int $parent) =>
                    DB::table('categories')->where('id', $parent)->first(['parent_id'])
                ), 422, __('server.category_cycle'));
                if ($data['parent_id'] !== null) $data['parent_id'] = (int) $data['parent_id'];
            }
            // A legitimate MySQL no-op may report zero changed rows. The
            // target's existence was checked under the hierarchy mutex.
            DB::table('categories')->where('id',$id)->update($data+['updated_at'=>now()]);
            Forum::audit('category.updated',['category_id'=>$id,'is_private'=>$data['is_private']??(bool)$category->is_private]);
            return response()->json(['category'=>Forum::categoryData(DB::table('categories')->find($id))]);
        });
    }
}
