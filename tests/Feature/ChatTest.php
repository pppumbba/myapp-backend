<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    /** The next requests are made as this user. */
    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    public function test_direct_chat_is_one_per_pair_and_messages_count_as_unread_until_read(): void
    {
        [$ann, $bob] = User::factory()->count(2)->create();

        $chatId = $this->as($ann)->postJson('/api/chats/direct', ['user_id' => $bob->id])->assertOk()->assertJsonPath('name', $bob->name)->json('id');
        $this->as($bob)->postJson('/api/chats/direct', ['user_id' => $ann->id])->assertOk()->assertJsonPath('id', $chatId)->assertJsonPath('name', $ann->name);
        $this->as($ann)->postJson('/api/chats/direct', ['user_id' => $ann->id])->assertUnprocessable();

        $first = $this->as($ann)->postJson("/api/chats/{$chatId}/messages", ['body' => ' Hello Bob '])->assertCreated()->assertJsonPath('body', 'Hello Bob')->json('id');
        $this->as($ann)->postJson("/api/chats/{$chatId}/messages", ['body' => 'Are you there?'])->assertCreated();

        $this->as($bob)->getJson('/api/chats/poll')->assertOk()->assertJsonPath('unread', 2)->assertJsonPath('chats.0.last.body', 'Are you there?');
        $this->as($ann)->getJson('/api/chats/poll')->assertJsonPath('unread', 0);

        // Bob opens the chat: everything, then only what is new after the last id he has.
        $this->as($bob)->getJson("/api/chats/poll?open={$chatId}")->assertJsonCount(2, 'open.messages');
        $this->as($bob)->getJson("/api/chats/poll?open={$chatId}&after={$first}")->assertJsonCount(1, 'open.messages');

        $this->as($bob)->postJson("/api/chats/{$chatId}/read", ['message_id' => $first])->assertOk();
        $this->as($bob)->getJson('/api/chats/poll')->assertJsonPath('unread', 1);
        // Ann sees how far Bob has read ("Seen").
        $this->as($ann)->getJson("/api/chats/poll?open={$chatId}")->assertJsonPath("open.read.{$bob->id}", $first);
        // A read mark never moves back.
        $this->as($bob)->postJson("/api/chats/{$chatId}/read", ['message_id' => 0])->assertOk();
        $this->as($bob)->getJson('/api/chats/poll')->assertJsonPath('unread', 1);
    }

    public function test_typing_shows_to_the_others_for_a_few_seconds(): void
    {
        [$ann, $bob] = User::factory()->count(2)->create();
        $chatId = $this->as($ann)->postJson('/api/chats/direct', ['user_id' => $bob->id])->json('id');

        $this->as($ann)->postJson("/api/chats/{$chatId}/typing")->assertOk();
        $this->as($bob)->getJson("/api/chats/poll?open={$chatId}")->assertJsonPath('open.typing', [$ann->name])->assertJsonPath('chats.0.typing', [$ann->name]);
        $this->as($ann)->getJson("/api/chats/poll?open={$chatId}")->assertJsonPath('open.typing', []);

        $this->travel(Chat::TYPING_SECONDS + 1)->seconds();
        $this->as($bob)->getJson("/api/chats/poll?open={$chatId}")->assertJsonPath('open.typing', []);
    }

    public function test_groups_add_rename_leave_and_outsiders_get_nothing(): void
    {
        [$ann, $bob, $cat, $dan] = User::factory()->count(4)->create();

        $id = $this->as($ann)->postJson('/api/chats', ['name' => 'Buyers', 'user_ids' => [$bob->id]])->assertCreated()->assertJsonPath('name', 'Buyers')->assertJsonCount(2, 'members')->json('id');
        $this->as($ann)->postJson("/api/chats/{$id}/messages", ['body' => 'Before Cat joined']);

        $this->as($bob)->postJson("/api/chats/{$id}/members", ['user_ids' => [$cat->id, $bob->id]])->assertOk()->assertJsonCount(3, 'members');
        // Cat starts with the history read, so only the "added" line is new to her.
        $this->as($cat)->getJson('/api/chats/poll')->assertJsonPath('unread', 1)->assertJsonPath('chats.0.last.kind', 'system');

        $this->as($cat)->putJson("/api/chats/{$id}", ['name' => 'Purchasing'])->assertOk()->assertJsonPath('name', 'Purchasing');

        // Dan is not in it: no messages, no posting, no reading it, not in his searches.
        $this->as($dan)->getJson("/api/chats/{$id}/messages")->assertNotFound();
        $this->as($dan)->postJson("/api/chats/{$id}/messages", ['body' => 'Hi'])->assertNotFound();
        $this->as($dan)->getJson("/api/chats/poll?open={$id}")->assertJsonPath('open', null)->assertJsonPath('chats', []);
        $this->as($dan)->getJson('/api/chats/search?q=Before')->assertJsonCount(0);
        $this->as($ann)->getJson('/api/chats/search?q=Before')->assertJsonCount(1)->assertJsonPath('0.chat_id', $id);

        $this->as($cat)->deleteJson("/api/chats/{$id}/members/me")->assertOk();
        $this->as($cat)->getJson('/api/chats/poll')->assertJsonPath('chats', []);
        $this->as($ann)->getJson("/api/chats/{$id}/messages")->assertOk()->assertJsonPath('messages.4.body', __(':name left', ['name' => $cat->name]));
    }

    public function test_people_search_leaves_out_the_user(): void
    {
        $me = User::factory()->create(['name' => 'Ann Alpha']);
        User::factory()->create(['name' => 'Anna Beta']);
        User::factory()->create(['name' => 'Carl']);

        $this->as($me)->getJson('/api/chats/people?q=ann')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Anna Beta');
    }
}
