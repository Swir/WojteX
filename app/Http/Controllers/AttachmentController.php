<?php
namespace App\Http\Controllers;
use App\Services\ForumService as Forum;
use App\Services\AttachmentInsertQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentController extends Controller
{
    public function upload(Request $request,int $id) {
        $p=DB::table('posts')->find($id)??abort(404);$t=Forum::thread($p->thread_id,$request->user());
        abort_unless(Forum::canReplyCategory(DB::table('categories')->find($t->category_id), $request->user()), 403, __('server.category_post_forbidden'));
        abort_unless($p->user_id===$request->user()->id||Forum::isModerator($request->user()),403);
        abort_if($t->is_locked&&!Forum::isModerator($request->user()),403);
        abort_if(DB::table('attachments')->where('post_id',$id)->count()>=5,422,__('server.attachment_limit'));
        $request->validate(['file'=>'required|file|max:5120|mimes:jpg,jpeg,png,gif,webp,pdf,txt,zip']);
        $file=$request->file('file');$ext=strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($ext,['jpg','jpeg','png','gif','webp','pdf','txt','zip'],true),422,__('server.attachment_type'));
        $name=mb_substr(preg_replace('/[\x00-\x1F\x7F]/u','',basename($file->getClientOriginalName())),0,180);
        $path=$file->storeAs('attachments',Str::uuid().'.'.$ext,'local');
        abort_unless(is_string($path) && $path!=='',500);
        try {
            // Serialize uploads for this post where the database supports row
            // locks; then re-evaluate current ban, staff role, thread lock,
            // ownership and attachment limit in the INSERT itself. The file
            // staged on disk is removed on ANY rejected/failed DB operation.
            $aid=DB::transaction(function()use($id,$p,$request,$path,$name,$file){
                $post=DB::table('posts')->where('id',$id)->lockForUpdate()->first();
                abort_unless($post && (int)$post->thread_id===(int)$p->thread_id,404);
                $time=now()->toDateTimeString();
                $eligible=AttachmentInsertQuery::eligible(
                    $id,(int)$p->thread_id,(int)$request->user()->id,$path,$name,
                    $file->getMimeType()??'application/octet-stream',(int)$file->getSize(),$time
                );
                $inserted=DB::table('attachments')->insertUsing(
                    ['post_id','user_id','path','name','mime','size','created_at','updated_at'],$eligible
                );
                abort_unless($inserted===1,403,__('server.category_post_forbidden'));
                return (int)DB::connection()->getPdo()->lastInsertId();
            });
        } catch(\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        return response()->json(['id'=>$aid,'name'=>$name,'url'=>'/attachments/'.$aid],201);
    }
    public function download(Request $request,int $id) {
        $a=DB::table('attachments')->find($id)??abort(404);$p=DB::table('posts')->find($a->post_id)??abort(404);
        Forum::thread($p->thread_id,$request->user());
        abort_unless(Storage::disk('local')->exists($a->path),404,__('server.attachment_unavailable'));
        return Storage::disk('local')->download($a->path,$a->name,['Content-Type'=>'application/octet-stream','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
    }
}
