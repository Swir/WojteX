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
        $data = $request->validate([
            'file' => 'required|file|max:5120|mimes:jpg,jpeg,png,gif,webp,pdf,txt,zip',
            'upload_key' => 'sometimes|required|string|regex:/\A[a-f0-9]{32}\z/',
        ]);
        $key = $data['upload_key'] ?? null;
        $actorId = (int) $request->user()->id;
        $existing = $key === null ? null : DB::table('attachments')
            ->where(['upload_key' => $key, 'post_id' => $id, 'user_id' => $actorId]);
        if ($attachment = $existing?->first(['id', 'name'])) {
            return $this->uploaded($attachment->id, $attachment->name, 200);
        }
        abort_if(DB::table('attachments')->where('post_id',$id)->count()>=5,422,__('server.attachment_limit'));
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
            $result=DB::transaction(function()use($id,$p,$actorId,$path,$name,$file,$existing,$key){
                $post=DB::table('posts')->where('id',$id)->lockForUpdate()->first();
                abort_unless($post && (int)$post->thread_id===(int)$p->thread_id,404);
                // The client can retry a file after losing the original response.
                // Locking the lookup also sees concurrent commits in MySQL.
                if ($attachment = $existing?->lockForUpdate()->first(['id', 'name'])) {
                    return [$attachment->id, $attachment->name, false];
                }
                $time=now()->toDateTimeString();
                $eligible=AttachmentInsertQuery::eligible(
                    $id,(int)$p->thread_id,$actorId,$path,$name,
                    $file->getMimeType()??'application/octet-stream',(int)$file->getSize(),$time
                );
                $columns = ['post_id','user_id','path','name','mime','size','created_at','updated_at'];
                if ($key !== null) {
                    $columns[] = 'upload_key';
                    $eligible->selectRaw('? AS upload_key', [$key]);
                }
                $inserted=DB::table('attachments')->insertUsing($columns, $eligible);
                abort_unless($inserted===1,403,__('server.category_post_forbidden'));
                return [(int)DB::connection()->getPdo()->lastInsertId(), $name, true];
            });
        } catch(\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        [$aid, $uploadedName, $created] = $result;
        if (!$created) Storage::disk('local')->delete($path);
        return $this->uploaded($aid, $uploadedName, $created ? 201 : 200);
    }

    private function uploaded(int $id, string $name, int $status)
    {
        return response()->json(['id' => $id, 'name' => $name, 'url' => '/attachments/'.$id], $status);
    }
    public function download(Request $request,int $id) {
        $a = $this->readableAttachment($request, $id);
        return Storage::disk('local')->download($a->path,$a->name,['Content-Type'=>'application/octet-stream','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
    }

    public function preview(Request $request, int $id)
    {
        $a = $this->readableAttachment($request, $id);
        abort_unless(Forum::canPreviewAttachment($a->mime), 404, __('server.attachment_unavailable'));
        $storage = Storage::disk('local');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($storage->path($a->path));
        abort_unless($mime === $a->mime, 404, __('server.attachment_unavailable'));

        return $storage->response($a->path, null, [
            'Content-Type' => $a->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    private function readableAttachment(Request $request, int $id): object
    {
        $a=DB::table('attachments')->find($id)??abort(404);$p=DB::table('posts')->find($a->post_id)??abort(404);
        Forum::thread($p->thread_id,$request->user());
        abort_unless(Storage::disk('local')->exists($a->path),404,__('server.attachment_unavailable'));

        return $a;
    }
}
