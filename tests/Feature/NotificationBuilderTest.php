<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Meta;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WfmStatus;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        $this->actor = User::factory()->admin()->create(['name' => 'Ana Actor']);
        $this->otherAdmin = User::factory()->admin()->create();
        Sanctum::actingAs($this->actor);
    }

    private function metaId(string $code): int
    {
        return Meta::where('code', $code)->value('id');
    }

    public function test_a_new_request_tells_the_other_admins_with_its_values_filled_in(): void
    {
        $item = DB::table('proc_items')->where('code', 'FU-001')->first();

        $this->postJson("/api/metas/{$this->metaId('proc_request_form')}/data", [
            'request_no' => 'PR-N-1', 'title' => 'Chairs', 'item_id' => $item->id, 'quantity' => 3,
            'department' => 'IT', 'status' => 'draft', 'needed_by' => '2026-11-01',
        ])->assertCreated();

        $sent = UserNotification::where('notification_meta_id', $this->metaId('proc_notify_new_request'))->get();
        $this->assertSame([$this->otherAdmin->id], $sent->pluck('user_id')->all());
        $this->assertSame('New request PR-N-1: Chairs', $sent[0]->title);
        $this->assertSame('Ana Actor asked for 3 × Office chair for IT, needed by 2026-11-01.', $sent[0]->body);
        $this->assertSame('/run/'.$this->metaId('proc_requests_grid'), $sent[0]->link);
    }

    public function test_approval_tells_whoever_asked_only_when_the_status_changes_to_approved(): void
    {
        $request = DB::table('proc_purchase_requests')->where('status', '!=', 'approved')->first();
        DB::table('proc_purchase_requests')->where('id', $request->id)->update(['requested_by' => 'Rita Requester', 'status' => 'submitted']);
        $form = $this->metaId('proc_request_form');
        $rule = $this->metaId('proc_notify_request_approved');
        $source = (int) Meta::find($rule)->json_config['source_meta_id'];
        $requester = $this->allow(User::factory()->create(['name' => 'Rita Requester']), [$source]);

        $this->putJson("/api/metas/{$form}/data/{$request->id}", ['notes' => 'Looked at it'])->assertOk();
        $this->assertSame(0, UserNotification::where('notification_meta_id', $rule)->count());

        $this->putJson("/api/metas/{$form}/data/{$request->id}", ['status' => 'approved'])->assertOk();
        $sent = UserNotification::where('notification_meta_id', $rule)->sole();
        $this->assertSame($requester->id, $sent->user_id);
        $this->assertSame('success', $sent->type);
        $this->assertStringContainsString('(it was submitted)', $sent->body);
    }

    public function test_moving_an_order_to_sent_links_to_its_report(): void
    {
        $workflow = $this->metaId('proc_order_workflow');
        $approved = WfmStatus::where('workflow_meta_id', $workflow)->where('code', 'approved')->value('id');
        $order = DB::table('proc_purchase_orders')->first();
        DB::table('proc_purchase_orders')->where('id', $order->id)->update(['status_id' => $approved]);

        $state = $this->getJson("/api/workflows/{$workflow}/records/{$order->id}")->assertOk()->json();
        $toSent = collect($state['transitions'])->firstWhere('next_status.code', 'sent')['id'];
        $this->postJson("/api/workflows/{$workflow}/records/{$order->id}/move", ['transition_id' => $toSent])->assertOk();

        $sent = UserNotification::where('notification_meta_id', $this->metaId('proc_notify_order_sent'))->sole();
        $this->assertSame("Order {$order->po_number} is Sent", $sent->title);
        $this->assertSame('/run/'.$this->metaId('proc_order_report')."?record={$order->id}", $sent->link);
    }

    public function test_the_builder_previews_tests_and_lists_what_was_sent(): void
    {
        $rule = $this->metaId('proc_notify_new_request');
        $newest = DB::table('proc_purchase_requests')->orderByDesc('id')->first();

        $this->getJson("/api/metas/{$rule}/notification/preview")
            ->assertOk()
            ->assertJsonPath('record_id', $newest->id)
            ->assertJsonPath('title', "New request {$newest->request_no}: {$newest->title}")
            ->assertJsonPath('recipient_count', 1);

        $this->postJson("/api/metas/{$rule}/notification/send", ['test' => true])->assertOk()->assertJsonPath('sent', 1);
        $this->assertSame([$this->actor->id], UserNotification::where('notification_meta_id', $rule)->pluck('user_id')->all());

        $this->getJson("/api/metas/{$rule}/notification/log")->assertOk()->assertJsonCount(1)->assertJsonPath('0.user_name', 'Ana Actor');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/metas/{$rule}/notification/preview")->assertForbidden();
    }

    public function test_an_inactive_notification_sends_nothing(): void
    {
        Meta::whereKey($this->metaId('proc_notify_new_request'))->update(['is_active' => false]);
        $item = DB::table('proc_items')->value('id');

        $this->postJson("/api/metas/{$this->metaId('proc_request_form')}/data", [
            'request_no' => 'PR-N-2', 'title' => 'Pens', 'item_id' => $item, 'quantity' => 1, 'status' => 'draft',
        ])->assertCreated();

        $this->assertSame(0, UserNotification::whereNotNull('notification_meta_id')->count());
    }

    public function test_a_notification_set_to_email_also_mails_each_recipient(): void
    {
        Mail::fake();
        $rule = Meta::find($this->metaId('proc_notify_new_request'));
        $rule->update(['json_config' => [...$rule->json_config, 'email' => true]]);
        $item = DB::table('proc_items')->value('id');

        $this->postJson("/api/metas/{$this->metaId('proc_request_form')}/data", [
            'request_no' => 'PR-N-3', 'title' => 'Desks', 'item_id' => $item, 'quantity' => 2, 'status' => 'draft',
        ])->assertCreated();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($this->otherAdmin->email)
            && $mail->notification->title === 'New request PR-N-3: Desks');
        Mail::assertSentCount(1);
        $sent = UserNotification::where('notification_meta_id', $rule->id)->sole();
        $this->assertSame('sent', $sent->email_status);
        $this->assertNotNull($sent->emailed_at);
        $this->assertSame('sent', $this->getJson("/api/metas/{$rule->id}/notification/log")->json('0.email_status'));

        // Its link opens in the frontend.
        $url = rtrim(config('app.frontend_url'), '/').$sent->link;
        $this->assertSame($url, NotificationMail::url($sent->link));
        (new NotificationMail($sent))->assertSeeInHtml('New request PR-N-3: Desks')->assertSeeInHtml($url);
    }

    public function test_a_notification_without_email_mails_nobody(): void
    {
        Mail::fake();
        $item = DB::table('proc_items')->value('id');

        $this->postJson("/api/metas/{$this->metaId('proc_request_form')}/data", [
            'request_no' => 'PR-N-4', 'title' => 'Lamps', 'item_id' => $item, 'quantity' => 1, 'status' => 'draft',
        ])->assertCreated();

        Mail::assertNothingSent();
        $this->assertNull(UserNotification::where('notification_meta_id', $this->metaId('proc_notify_new_request'))->sole()->email_status);
    }
}
