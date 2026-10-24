<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Intended HTTP/PDO suite. Requires a complete PHP runtime, not run in DEV.7 host. */
class PrivateMessageReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 1024,
            'hashing.argon.time' => 1,
            'hashing.argon.threads' => 1,
        ]);
        DB::table('settings')->insert(['key' => 'setup_complete', 'value' => 'true']);
    }

    private function account(string $name, string $role = 'member', bool $banned = false): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $name, 'email' => strtolower(str_replace(' ', '-', $name)).'@example.test',
            'password' => 'SomeLongPassword2026!', 'role' => $role,
            'is_banned' => $banned, 'color' => 'violet',
        ])->save();
        return $user;
    }

    public function test_only_visible_recipient_can_report_and_admin_only_sees_reported_pm(): void
    {
        $sender = $this->account('Report Sender');
        $recipient = $this->account('Report Recipient');
        $stranger = $this->account('Report Stranger', 'admin');
        $member = $this->account('Report Member');

        $this->postJson('/api/messages/1/report', ['reason' => 'spam'])->assertUnauthorized();
        $this->getJson('/api/admin/message-reports')->assertUnauthorized();
        $this->actingAs($sender);
        $id = $this->postJson('/api/messages', [
            'recipient_id' => $recipient->id, 'body' => '<script>reported secret</script>',
        ])->assertCreated()->json('id');
        $unreportedId = $this->postJson('/api/messages', [
            'recipient_id' => $recipient->id, 'body' => 'Unreported private conversation',
        ])->assertCreated()->json('id');
        $this->postJson('/api/messages/'.$id.'/report', ['reason' => 'spam'])->assertNotFound();
        $this->actingAs($member);
        $this->postJson('/api/messages/'.$id.'/report', ['reason' => 'spam'])->assertNotFound();
        $this->getJson('/api/admin/message-reports')->assertForbidden();
        $this->actingAs($stranger);
        $this->postJson('/api/messages/'.$id.'/report', ['reason' => 'spam'])->assertNotFound();
        $this->actingAs($recipient);
        $this->postJson('/api/messages/'.$id.'/report', ['reason' => 'forged'])->assertUnprocessable();
        $this->postJson('/api/messages/'.$id.'/report', ['reason' => 'spam', 'details' => str_repeat('X', 501)])
            ->assertUnprocessable();
        $this->postJson('/api/messages/'.$id.'/report', ['reason' => 'harassment', 'details' => '<b>abuse</b>'])
            ->assertOk()->assertJsonPath('ok', true);
        $this->postJson('/api/messages/'.$id.'/report', ['reason' => 'other'])
            ->assertOk(); // No duplicate record or silent change of reason.
        $this->assertDatabaseCount('private_message_reports', 1);
        $this->assertDatabaseHas('private_message_reports', [
            'message_id' => $id, 'reporter_id' => $recipient->id,
            'reason' => 'harassment', 'details' => '<b>abuse</b>', 'status' => 'open',
        ]);
        $this->actingAs($stranger);
        $this->getJson('/api/messages')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/message-reports')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', '<script>reported secret</script>')
            ->assertJsonPath('data.0.details', '<b>abuse</b>');
        $this->assertDatabaseHas('private_messages', ['id' => $unreportedId]);
        $serialized = $this->getJson('/api/admin/message-reports')->json();
        $this->assertStringNotContainsString('Unreported private conversation', json_encode($serialized));
    }

    public function test_hidden_message_cannot_be_reported_and_banned_accounts_are_rejected(): void
    {
        $sender = $this->account('Second Sender');
        $recipient = $this->account('Second Recipient');
        $this->actingAs($sender);
        $id = $this->postJson('/api/messages', [
            'recipient_id' => $recipient->id, 'body' => 'Keep private',
        ])->assertCreated()->json('id');
        $this->actingAs($recipient);
        $this->deleteJson('/api/messages/'.$id)->assertOk();
        $this->postJson('/api/messages/'.$id.'/report', ['reason' => 'spam'])->assertNotFound();
        $this->postJson('/api/messages/999999/report', ['reason' => 'spam'])->assertNotFound();
        $this->assertDatabaseCount('private_message_reports', 0);
        $recipient->is_banned = true;
        $recipient->save();
        $this->postJson('/api/messages/'.$id.'/report', ['reason' => 'spam'])->assertForbidden();
    }

    public function test_only_admin_can_review_and_status_transitions_are_audited_without_content(): void
    {
        $sender = $this->account('Review Sender');
        $recipient = $this->account('Review Recipient');
        $moderator = $this->account('Review Mod', 'moderator');
        $admin = $this->account('Review Admin', 'admin');
        $this->actingAs($sender);
        $id = $this->postJson('/api/messages', [
            'recipient_id' => $recipient->id, 'body' => 'Sensitive PM',
        ])->assertCreated()->json('id');
        $this->actingAs($recipient);
        $this->postJson('/api/messages/'.$id.'/report', ['reason' => 'threat'])->assertOk();
        $reportId = (int) DB::table('private_message_reports')->value('id');
        $this->patchJson('/api/admin/message-reports/'.$reportId, ['status' => 'resolved'])
            ->assertForbidden();
        $this->actingAs($moderator);
        $this->getJson('/api/admin/message-reports')->assertForbidden();
        $this->patchJson('/api/admin/message-reports/'.$reportId, ['status' => 'dismissed'])
            ->assertForbidden();
        $this->actingAs($admin);
        $this->patchJson('/api/admin/message-reports/99999', ['status' => 'resolved'])
            ->assertNotFound();
        $this->patchJson('/api/admin/message-reports/'.$reportId, ['status' => 'other'])
            ->assertUnprocessable();
        $this->patchJson('/api/admin/message-reports/'.$reportId, ['status' => 'resolved'])
            ->assertOk();
        $this->patchJson('/api/admin/message-reports/'.$reportId, ['status' => 'resolved'])
            ->assertOk(); // Idempotent even if DB affected rows == 0.
        $this->getJson('/api/admin/message-reports')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/message-reports?status=resolved')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('private_message_reports', [
            'id' => $reportId, 'status' => 'resolved', 'reviewed_by' => $admin->id,
        ]);
        $this->patchJson('/api/admin/message-reports/'.$reportId, ['status' => 'dismissed'])
            ->assertOk();
        $this->getJson('/api/admin/message-reports?status=dismissed')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/admin/message-reports/'.$reportId, ['status' => 'open'])
            ->assertOk();
        $this->assertDatabaseHas('private_message_reports', [
            'id' => $reportId, 'status' => 'open', 'reviewed_by' => null, 'reviewed_at' => null,
        ]);
        $this->getJson('/api/admin/message-reports?status=garbage')->assertUnprocessable();
        $log = DB::table('audit_logs')->where('action', 'message.report.reviewed')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('Sensitive PM', $log->details);
    }
}
