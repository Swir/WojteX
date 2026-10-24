<?php

namespace App\Http\Controllers;

use App\Services\ForumService as Forum;
use App\Services\PrivateMessageAccess;
use App\Services\PrivateMessageBlockToggleQuery;
use App\Services\PrivateMessageContactPolicy;
use App\Services\PrivateMessageContactQuery;
use App\Services\PrivateMessageRecipientSearch;
use App\Services\PrivateMessageReportPolicy;
use App\Services\PrivateMessageReportQuery;
use App\Services\PrivateMessageHideQuery;
use App\Services\PrivateMessageReadReceiptQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Plain-text direct messages. Only participants may read; only receiver may mark read. */
class PrivateMessageController extends Controller
{
    private function allowAccount(Request $request): int
    {
        abort_if($request->user()->is_banned, 403, __('server.messages_banned'));
        return (int) $request->user()->id;
    }

    public function index(Request $request): JsonResponse
    {
        $id = $this->allowAccount($request);
        // Sender and recipient independently control visibility of their copy.
        // The other participant's copy remains available when one hides theirs.
        $query = DB::table('private_messages')->where(fn ($q) => $q
            ->where(fn ($sender) => $sender->where('sender_id', $id)->whereNull('sender_hidden_at'))
            ->orWhere(fn ($recipient) => $recipient->where('recipient_id', $id)->whereNull('recipient_hidden_at')));
        $messages = (clone $query)->orderByDesc('id')->paginate(30);
        $rows = $messages->getCollection();
        $ids = $rows->flatMap(fn ($m) => [(int) $m->sender_id, (int) $m->recipient_id])->unique()->all();
        $users = DB::table('users')->whereIn('id', $ids)->get()->keyBy('id');
        $data = $rows->map(static function ($m) use ($users, $id) {
            // Defense in depth if query construction changes in a future release.
            abort_unless(PrivateMessageAccess::visibleInMailbox($id, (int) $m->sender_id,
                (int) $m->recipient_id, $m->sender_hidden_at !== null,
                $m->recipient_hidden_at !== null, false), 404, __('server.messages_not_found'));
            return [
                'id' => (int) $m->id,
                'sender_id' => (int) $m->sender_id,
                'recipient_id' => (int) $m->recipient_id,
                'sender' => Forum::publicUser($users->get($m->sender_id)),
                'recipient' => Forum::publicUser($users->get($m->recipient_id)),
                'body' => $m->body, // escaped as text in SPA; no HTML rendering.
                'created_at' => $m->created_at,
                'read_at' => $m->read_at,
                'outgoing' => (int) $m->sender_id === $id,
            ];
        })->values();
        $unread = (clone $query)->where('recipient_id', $id)->whereNull('read_at')->count();
        return response()->json([
            'data' => $data,
            'current_page' => $messages->currentPage(),
            'last_page' => $messages->lastPage(),
            'unread' => $unread,
        ]);
    }

    /**
     * Search public display names without exposing e-mail addresses.
     * The numeric ID is used for delivery so equal display names stay distinct.
     */
    public function recipients(Request $request): JsonResponse
    {
        $viewer = $this->allowAccount($request);
        // Validate the actual search text, not whitespace padded input.
        if (is_string($request->input('q'))) {
            $request->merge(['q' => trim($request->input('q'))]);
        }
        $data = $request->validate(['q' => 'required|string|min:2|max:50']);
        $term = $data['q'];
        $matches = PrivateMessageContactQuery::availableRecipients($viewer)
            ->where('is_banned', false)
            ->where('id', '!=', $viewer)
            // Use an explicit escape character on both SQLite and MySQL.
            // Searching for '%' or '_' must NOT enumerate every account.
            ->whereRaw("name LIKE ? ESCAPE '!'", [PrivateMessageRecipientSearch::likePattern($term)])
            ->orderBy('name')->orderBy('id')
            ->limit(10)->get(['id', 'name']);
        return response()->json(['data' => $matches->map(static fn ($user) => [
            'id' => (int) $user->id,
            'name' => $user->name,
        ])->values()]);
    }

    public function send(Request $request): JsonResponse
    {
        $id = $this->allowAccount($request);
        $data = $request->validate([
            'recipient_id' => 'sometimes|required|integer|min:1',
            // Backward-compatible legacy clients can use an exact name when unique.
            // New UI always chooses a stable recipient_id from the search results.
            'recipient' => 'required_without:recipient_id|string|min:2|max:255',
            'body' => 'required|string|min:1|max:4000',
        ]);
        if (isset($data['recipient_id'])) {
            $target = DB::table('users')->where('id', (int) $data['recipient_id'])
                ->first(['id', 'is_banned', 'allow_private_messages']);
        } else {
            // Legacy name lookup must never silently select one of duplicates.
            $matches = DB::table('users')->where('name', trim($data['recipient']))
                ->limit(2)->get(['id', 'is_banned', 'allow_private_messages']);
            $target = $matches->count() === 1 ? $matches->first() : null;
        }
        if (!$target) {
            return response()->json(['message' => __('server.messages_recipient_unavailable')], 422);
        }
        if (!PrivateMessageAccess::canSend($id, (int) $target->id, false, (bool) $target->is_banned)
            || !(bool) $target->allow_private_messages) {
            return response()->json(['message' => __('server.messages_recipient_unavailable')], 422);
        }
        // This check is applied to BOTH ID delivery and legacy name delivery.
        // A staff role cannot bypass a member's refusal of PM contact.
        $senderBlockedRecipient = DB::table('private_message_blocks')
            ->where('blocker_id', $id)->where('blocked_id', (int) $target->id)->exists();
        $recipientBlockedSender = DB::table('private_message_blocks')
            ->where('blocker_id', (int) $target->id)->where('blocked_id', $id)->exists();
        if (!PrivateMessageContactPolicy::canMessage($senderBlockedRecipient, $recipientBlockedSender)) {
            return response()->json(['message' => __('server.messages_recipient_unavailable')], 422);
        }
        $body = trim($data['body']);
        if ($body === '') return response()->json(['message' => __('server.messages_body_required')], 422);
        // Enforce block state AGAIN in the INSERT itself. A stale preflight
        // query must not deliver a PM when a block was committed just before
        // the database writes this row (including legacy-name sends).
        $recipientId = (int) $target->id;
        $time = now()->toDateTimeString();
        $eligible = PrivateMessageContactQuery::eligibleMessage($id, $recipientId, $body, $time);
        $inserted = DB::table('private_messages')->insertUsing(
            ['sender_id', 'recipient_id', 'body', 'read_at', 'created_at', 'updated_at'],
            $eligible
        );
        if ($inserted !== 1) {
            return response()->json(['message' => __('server.messages_recipient_unavailable')], 422);
        }
        $messageId = (int) DB::connection()->getPdo()->lastInsertId();
        return response()->json(['id' => $messageId, 'message' => __('server.messages_sent')], 201);
    }

    /** Only the owner can inspect their own list of blocked PM contacts. */
    public function blockedContacts(Request $request): JsonResponse
    {
        $viewer = $this->allowAccount($request);
        $rows = DB::table('private_message_blocks')
            ->join('users', 'users.id', '=', 'private_message_blocks.blocked_id')
            ->where('private_message_blocks.blocker_id', $viewer)
            ->orderBy('users.name')->orderBy('users.id')
            ->get(['users.id', 'users.name']);
        return response()->json(['data' => $rows->map(static fn ($row) => [
            'id' => (int) $row->id,
            'name' => $row->name,
        ])->values()]);
    }

    /** Idempotent block: prevents subsequent PMs in either direction. */
    public function blockContact(Request $request): JsonResponse
    {
        $viewer = $this->allowAccount($request);
        $data = $request->validate(['user_id' => 'required|integer|min:1']);
        $otherId = (int) $data['user_id'];
        if (!PrivateMessageContactPolicy::canBlock($viewer, $otherId)
            || !DB::table('users')->where('id', $otherId)->exists()) {
            return response()->json(['message' => __('server.messages_recipient_unavailable')], 422);
        }
        // Moderation can revoke this actor after allowAccount() and the
        // target lookup. Never write a block from a stale session model.
        $inserted = DB::table('private_message_blocks')->insertOrIgnoreUsing(
            ['blocker_id', 'blocked_id', 'created_at', 'updated_at'],
            PrivateMessageBlockToggleQuery::eligibleInsert($viewer, $otherId, now()->toDateTimeString())
        );
        if ($inserted === 0) {
            // Duplicates remain idempotent, but no-op must never disguise a
            // ban/deleted actor or vanished target as a successful block.
            abort_unless(PrivateMessageBlockToggleQuery::activeActor($viewer)->exists(),
                403, __('server.messages_banned'));
            abort_unless(PrivateMessageBlockToggleQuery::eligibleInsert(
                $viewer, $otherId, now()->toDateTimeString())->exists(),
                422, __('server.messages_recipient_unavailable'));
        }
        return response()->json(['ok' => true]);
    }

    /** Removing a block changes only this user's settings. */
    public function unblockContact(Request $request, int $id): JsonResponse
    {
        $viewer = $this->allowAccount($request);
        // Unblocking also changes contact/privacy settings. A concurrent
        // ban or account removal must block the DELETE itself.
        $deleted = PrivateMessageBlockToggleQuery::eligibleDelete($viewer, $id)->delete();
        if ($deleted === 0) {
            // Removing a missing entry is an authorized no-op only while
            // the actor is still an existing, unbanned account.
            abort_unless(PrivateMessageBlockToggleQuery::activeActor($viewer)->exists(),
                403, __('server.messages_banned'));
        }
        return response()->json(['ok' => true]);
    }

    /** Only the visible recipient may submit an abuse report for an inbound PM. */
    public function report(Request $request, int $id): JsonResponse
    {
        $viewer = $this->allowAccount($request);
        $data = $request->validate([
            'reason' => ['required', Rule::in(PrivateMessageReportPolicy::REASONS)],
            'details' => 'nullable|string|max:500',
        ]);
        $message = DB::table('private_messages')->where('id', $id)
            ->first(['recipient_id', 'recipient_hidden_at']);
        abort_unless($message && PrivateMessageReportPolicy::canReport(
            $viewer, (int) $message->recipient_id, $message->recipient_hidden_at !== null
        ), 404, __('server.messages_not_found'));

        // The same recipient/visibility predicate is checked inside the INSERT:
        // an old preflight result must not report a PM hidden in the meantime.
        $time = now()->toDateTimeString();
        $eligible = PrivateMessageReportQuery::eligible(
            $viewer, $id, $data['reason'], trim($data['details'] ?? '') ?: null, $time
        );
        $inserted = DB::table('private_message_reports')->insertOrIgnoreUsing([
            'message_id', 'reporter_id', 'reason', 'details', 'status',
            'reviewed_by', 'reviewed_at', 'created_at', 'updated_at',
        ], $eligible);
        // UNIQUE conflicts are idempotent only for a STILL active recipient
        // who can STILL see this message. A stale session ban, account removal
        // or mailbox hide must never convert the zero-write path into success.
        if ($inserted < 1) {
            abort_unless(PrivateMessageReportQuery::activeReporter($viewer)->exists(),
                403, __('server.messages_banned'));
            abort_unless(PrivateMessageReportQuery::visible($viewer, $id)->exists()
                && DB::table('private_message_reports')
                    ->where('message_id', $id)->where('reporter_id', $viewer)->exists(),
                404, __('server.messages_not_found'));
        }
        return response()->json(['ok' => true]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $viewer = $this->allowAccount($request);
        $message = DB::table('private_messages')->where('id', $id)->first();
        // The same 404 for messages that do not exist and for other people's
        // messages prevents a recipient/sender ID enumeration channel.
        abort_unless($message && $message->recipient_hidden_at === null
            && PrivateMessageAccess::canMarkRead($viewer, (int) $message->recipient_id, false
        ), 404, __('server.messages_not_found'));
        // A ban, account removal or mailbox hide may commit after the above
        // preflight. Enforce the CURRENT recipient authorization in the same
        // UPDATE that writes the read receipt. Never alter the sender's copy.
        $updated = PrivateMessageReadReceiptQuery::eligible($viewer, $id)
            ->update(['read_at' => now(), 'updated_at' => now()]);
        if ($updated !== 1) {
            abort_unless(PrivateMessageReadReceiptQuery::activeRecipient($viewer)->exists(),
                403, __('server.messages_banned'));
            // Repeated marks remain idempotent without changing the original
            // read_at. An invisible/deleted copy must NOT return false success.
            abort_unless(PrivateMessageReadReceiptQuery::visibleRead($viewer, $id)->exists(),
                404, __('server.messages_not_found'));
        }
        return response()->json(['ok' => true]);
    }

    /** Hide a message from this participant's mailbox; never delete the other's copy. */
    public function hide(Request $request, int $id): JsonResponse
    {
        $viewer = $this->allowAccount($request);
        $message = DB::table('private_messages')->where('id', $id)->first();
        abort_unless($message && PrivateMessageAccess::canHide(
            $viewer, (int) $message->sender_id, (int) $message->recipient_id, false
        ), 404, __('server.messages_not_found'));
        $column = (int) $message->sender_id === $viewer ? 'sender_hidden_at' : 'recipient_hidden_at';
        // Constrain the UPDATE to the participant and their visible copy. A
        // concurrent repeated request cannot mutate the other person's state.
        $updated = PrivateMessageHideQuery::eligible($viewer, $id, $column === 'sender_hidden_at')
            ->update([$column => now(), 'updated_at' => now()]);
        if ($updated !== 1) {
            // An old authenticated User may have been banned or removed after
            // the preflight. Do not misreport the rejected write as success.
            abort_unless(PrivateMessageHideQuery::activeActor($viewer)->exists(),
                403, __('server.messages_banned'));
            abort(404, __('server.messages_not_found'));
        }
        return response()->json(['ok' => true]);
    }
}
