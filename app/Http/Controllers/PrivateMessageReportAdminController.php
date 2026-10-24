<?php

namespace App\Http\Controllers;

use App\Services\ForumService as Forum;
use App\Services\PrivateMessageReportPolicy;
use App\Services\PrivateMessageReportAdminQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Staff can review only messages explicitly reported by their recipient. */
class PrivateMessageReportAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(PrivateMessageReportPolicy::STATUSES)],
        ]);
        $status = $data['status'] ?? 'open';
        $actorId = (int) $request->user()->id;
        // Admin middleware may have loaded a stale User object. Before
        // serializing private message bodies require a currently active admin.
        abort_unless(PrivateMessageReportAdminQuery::activeAdmin($actorId)->exists(),
            403, __('server.admin_only'));
        // No endpoint to inspect arbitrary private messages. The only access
        // is this join, starting at actual reports, protected by admin middleware.
        $page = PrivateMessageReportAdminQuery::guarded(DB::table('private_message_reports as report'), $actorId)
            ->join('private_messages as pm', 'pm.id', '=', 'report.message_id')
            ->join('users as reporter', 'reporter.id', '=', 'report.reporter_id')
            ->join('users as sender', 'sender.id', '=', 'pm.sender_id')
            ->join('users as recipient', 'recipient.id', '=', 'pm.recipient_id')
            ->where('report.status', $status)
            ->orderByDesc('report.id')
            ->select([
                'report.id', 'report.message_id', 'report.reason', 'report.details',
                'report.status', 'report.created_at', 'report.reviewed_at',
                'reporter.name as reporter_name', 'sender.name as sender_name',
                'recipient.name as recipient_name', 'pm.body as body',
                'pm.created_at as message_created_at',
            ])->paginate(20);
        // This check also avoids returning page/count metadata after a role
        // change during pagination. The content SELECT itself is guarded.
        abort_unless(PrivateMessageReportAdminQuery::activeAdmin($actorId)->exists(),
            403, __('server.admin_only'));
        return response()->json([
            'data' => $page->items(), 'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(), 'status' => $status,
        ]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(PrivateMessageReportPolicy::STATUSES)],
        ]);
        $actorId = (int) $request->user()->id;
        DB::transaction(function () use ($actorId, $id, $data) {
            abort_unless(DB::table('private_message_reports')->where('id', $id)->exists(),
                404, __('server.messages_not_found'));
            // Never trust the admin role captured by middleware: it can change
            // after the request was authenticated but before this UPDATE.
            $updated = PrivateMessageReportAdminQuery::reviewable($id, $actorId)
                ->where('status', '!=', $data['status'])->update([
                    'status' => $data['status'],
                    'reviewed_by' => $data['status'] === 'open' ? null : $actorId,
                    'reviewed_at' => $data['status'] === 'open' ? null : now(),
                    'updated_at' => now(),
                ]);
            if ($updated === 1) {
                // Audit only an actual transition; never store private content.
                Forum::audit('message.report.reviewed', ['report_id' => $id, 'status' => $data['status']]);
                return;
            }
            // A repeated PATCH is an authorized no-op, not a new review.
            // If the role was revoked or the report vanished, fail closed.
            abort_unless(PrivateMessageReportAdminQuery::reviewable($id, $actorId)
                ->where('status', $data['status'])->exists(),
                403, __('server.admin_only'));
        });
        return response()->json(['ok' => true]);
    }
}
