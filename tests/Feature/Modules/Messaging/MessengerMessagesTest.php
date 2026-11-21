<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Messaging;

use App\Jobs\SendPushToUser;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Messenger phone doors — messages (contract §1–§3, §5): send once per client_id, the sent → delivered → read ticks,
 * unread counts, photo / file / voice uploads and their guarded door, typing, mute and push, reply, message info.
 */
final class MessengerMessagesTest extends TestCase
{
    use MessengerKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMessenger();
    }

    // ── text, once per client_id, ticks ─────────────────────────────────

    public function test_a_text_is_stored_once_per_client_id(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $thread = $this->direct($a, $b);

        $first = $this->phone($a, 'POST', "/api/v1/messenger/threads/{$thread}/messages", ['kind' => 'text', 'text' => 'salam', 'client_id' => 'c-1'])
            ->assertCreated()
            ->assertJsonPath('data.mine', true)
            ->assertJsonPath('data.state', 'sent')
            ->assertJsonPath('data.sender', null)
            ->assertJsonPath('data.text', 'salam')
            ->assertJsonPath('data.thread_id', $thread)
            ->json('data.id');

        $again = $this->phone($a, 'POST', "/api/v1/messenger/threads/{$thread}/messages", ['kind' => 'text', 'text' => 'salam', 'client_id' => 'c-1'])
            ->assertOk()->json('data.id');

        $this->assertSame($first, $again, '⛔ একই client_id-তে নতুন বার্তা / the same client_id made a new message');
        $this->assertSame(1, Message::query()->where('client_id', 'c-1')->count(), '⛔ বার্তা দুইবার জমা / stored twice');

        $seen = $this->byId($this->fetch($b, $thread)['data'], $first);
        $this->assertFalse($seen['mine']);
        $this->assertSame((string) $a->id, $seen['sender']['id'] ?? null);
    }

    public function test_ticks_move_from_sent_to_delivered_to_read(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $thread = $this->direct($a, $b);
        $id = $this->say($a, $thread, 'tick tick')['id'];

        $this->assertSame('sent', $this->stateIn($this->fetch($a, $thread, 0), $id)['state'] ?? null);

        /* ⭐ the other member fetching = delivered (contract §2 ⓘ) */
        $this->fetch($b, $thread);
        $this->assertSame('delivered', $this->stateIn($this->fetch($a, $thread, 0), $id)['state'] ?? null,
            '⛔ প্রাপক দেখলেন, তবু "পৌঁছেছে" নয় / fetched by the receiver, yet not delivered');

        $this->phone($b, 'POST', "/api/v1/messenger/threads/{$thread}/read", ['up_to' => (int) $id])->assertNoContent();

        $state = $this->stateIn($this->fetch($a, $thread, 0), $id);
        $this->assertSame('read', $state['state'] ?? null, '⛔ পড়ার পরেও "পড়েছেন" নয় / read, yet not read');
        $this->assertSame(1, $state['read_count'] ?? null);

        $mine = $this->byId($this->fetch($a, $thread)['data'], $id);
        $this->assertSame('read', $mine['state']);
    }

    public function test_unread_counts_follow_the_read_mark_not_the_fetch(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $thread = $this->direct($a, $b);
        $this->say($a, $thread, 'one');
        $last = $this->say($a, $thread, 'two')['id'];

        $this->assertSame(2, $this->threadRow($b, $thread)['unread'] ?? null);
        $this->phone($b, 'GET', '/api/v1/messenger/threads')->assertJsonPath('unread_total', 2);
        $this->assertSame(0, $this->threadRow($a, $thread)['unread'] ?? null, '⛔ নিজের বার্তা নিজের কাছে অপঠিত / own messages count as unread');

        $this->fetch($b, $thread);
        $this->assertSame(2, $this->threadRow($b, $thread)['unread'] ?? null, 'fetching is delivered, not read');

        $this->phone($b, 'POST', "/api/v1/messenger/threads/{$thread}/read", ['up_to' => (int) $last])->assertNoContent();

        $this->assertSame(0, $this->threadRow($b, $thread)['unread'] ?? null);
        $this->phone($b, 'GET', '/api/v1/messenger/threads')->assertJsonPath('unread_total', 0);
    }

    // ── uploads ──────────────────────────────────────────────────────────

    public function test_a_photo_lands_on_the_private_disk_and_opens_only_for_members(): void
    {
        Storage::fake(MessageFile::DISK);
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $c = $this->person('Chhoton');
        $thread = $this->direct($a, $b);

        $data = $this->upload($a, "/api/v1/messenger/threads/{$thread}/messages", [
            'kind' => 'image', 'client_id' => 'img-1', 'text' => 'the slip',
            'file' => UploadedFile::fake()->image('slip.jpg', 800, 600),
        ])->assertCreated()->assertJsonPath('data.kind', 'image')->assertJsonPath('data.text', 'the slip')->json('data');

        $file = MessageFile::query()->firstOrFail();
        $this->assertStringStartsWith('messenger/'.$this->company->id.'/', $file->path, '⛔ ছবি messenger/-এর বাইরে / the photo is not under messenger/');
        Storage::disk(MessageFile::DISK)->assertExists($file->path);
        $this->assertSame('image/jpeg', $data['attachment']['mime']);
        $this->assertNotNull($data['attachment']['thumb_url']);

        $this->phone($b, 'GET', $data['attachment']['url'])->assertOk();
        $this->phone($b, 'GET', $data['attachment']['thumb_url'])->assertOk();

        $this->phone($c, 'GET', $data['attachment']['url'])->assertNotFound();
        $this->phone($c, 'GET', $data['attachment']['thumb_url'])->assertNotFound();
    }

    public function test_a_too_big_photo_and_an_exe_are_422_and_nothing_is_kept(): void
    {
        Storage::fake(MessageFile::DISK);
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $thread = $this->direct($a, $b);
        $url = "/api/v1/messenger/threads/{$thread}/messages";

        $this->upload($a, $url, ['kind' => 'image', 'file' => UploadedFile::fake()->image('big.jpg', 50, 50)->size(11 * 1024)])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->upload($a, $url, ['kind' => 'file', 'file' => UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, Message::query()->count(), '⛔ বাতিল ফাইলের বার্তা জমা / a refused upload left a message');
        $this->assertSame(0, MessageFile::query()->count());
        $this->assertSame([], Storage::disk(MessageFile::DISK)->allFiles(), '⛔ বাতিল ফাইল ডিস্কে / a refused file stayed on disk');

        /* ⓘ the same door takes an allowed file — the 422s were the checks */
        $this->upload($a, $url, ['kind' => 'file', 'file' => UploadedFile::fake()->create('list.pdf', 10, 'application/pdf')])
            ->assertCreated()->assertJsonPath('data.kind', 'file');
    }

    public function test_a_voice_note_keeps_its_waveform_and_length(): void
    {
        Storage::fake(MessageFile::DISK);
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $thread = $this->direct($a, $b);

        $attachment = $this->upload($a, "/api/v1/messenger/threads/{$thread}/messages", [
            'kind' => 'voice', 'client_id' => 'v-1', 'duration_ms' => 4200, 'waveform' => '0.1,0.9',
            'file' => UploadedFile::fake()->create('note.m4a', 30, 'audio/mp4'),
        ])->assertCreated()->assertJsonPath('data.kind', 'voice')->json('data.attachment');

        $this->assertSame([0.1, 0.9], $attachment['waveform'], '⛔ ঢেউয়ের রেখা হারাল / the waveform was lost');
        $this->assertSame(4200, $attachment['duration_ms']);

        $seen = $this->fetch($b, $thread)['data'][0]['attachment'];
        $this->assertSame([0.1, 0.9], $seen['waveform']);
        $this->assertSame(4200, $seen['duration_ms']);
    }

    // ── typing ───────────────────────────────────────────────────────────

    public function test_typing_shows_to_the_other_member_and_never_to_oneself(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $thread = $this->direct($a, $b);

        $this->phone($a, 'POST', "/api/v1/messenger/threads/{$thread}/typing")->assertNoContent();

        $forB = array_column($this->fetch($b, $thread, 0)['meta']['typing'], 'id');
        $this->assertSame([(string) $a->id], $forB, '⛔ "লিখছেন…" দেখা যায় না / B does not see A typing');

        $forA = array_column($this->fetch($a, $thread, 0)['meta']['typing'], 'id');
        $this->assertSame([], $forA, '⛔ নিজেকেই "লিখছেন…" দেখায় / A sees themselves typing');
    }

    // ── mute and push ────────────────────────────────────────────────────

    public function test_a_muted_member_gets_no_push_the_others_do_and_the_sender_never(): void
    {
        $a = $this->person('Asif', ['messaging.group.create']);
        $b = $this->person('Bina');
        $c = $this->person('Chhoton');
        $thread = $this->group($a, 'Depot', [$b, $c]);

        $this->phone($b, 'POST', "/api/v1/messenger/threads/{$thread}/mute", ['muted' => true])
            ->assertOk()->assertJsonPath('data.muted', true);

        Queue::fake([SendPushToUser::class]);

        $id = $this->say($a, $thread, 'meeting at 5')['id'];

        Queue::assertPushed(SendPushToUser::class, fn (SendPushToUser $job): bool => $job->userId === (int) $c->id
            && $job->data['type'] === 'chat' && $job->data['thread_id'] === $thread && $job->data['message_id'] === $id
            && $job->data['from'] === (string) $a->id && $job->body === 'meeting at 5');
        Queue::assertNotPushed(SendPushToUser::class, fn (SendPushToUser $job): bool => $job->userId === (int) $b->id);
        Queue::assertNotPushed(SendPushToUser::class, fn (SendPushToUser $job): bool => $job->userId === (int) $a->id);

        /* ⓘ unmuted again → the push comes back */
        $this->phone($b, 'POST', "/api/v1/messenger/threads/{$thread}/mute", ['muted' => false])->assertOk()->assertJsonPath('data.muted', false);
        $this->say($a, $thread, 'again');
        Queue::assertPushed(SendPushToUser::class, fn (SendPushToUser $job): bool => $job->userId === (int) $b->id);
    }

    // ── reply ────────────────────────────────────────────────────────────

    public function test_a_reply_carries_a_short_quote_of_the_message_it_answers(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $c = $this->person('Chhoton');
        $thread = $this->direct($a, $b);
        $elsewhere = $this->direct($a, $c);
        $first = $this->say($a, $thread, 'which depot?')['id'];
        $foreign = $this->say($a, $elsewhere, 'not here')['id'];

        $reply = $this->say($b, $thread, 'Tejgaon', ['reply_to_id' => (int) $first]);
        $this->assertSame(['id' => $first, 'kind' => 'text', 'text' => 'which depot?', 'mine' => false],
            array_intersect_key($reply['reply_to'] ?? [], array_flip(['id', 'kind', 'text', 'mine'])));
        $this->assertSame((string) $a->id, $reply['reply_to']['sender']['id'] ?? null);

        $forA = $this->byId($this->fetch($a, $thread)['data'], $reply['id']);
        $this->assertTrue($forA['reply_to']['mine'] ?? null, 'for the one quoted, the quote is "mine"');

        /* ⛔ a message of another thread is never quoted */
        $stray = $this->say($b, $thread, 'stray', ['reply_to_id' => (int) $foreign]);
        $this->assertNull($stray['reply_to'], '⛔ অন্য কথোপকথনের বার্তা উদ্ধৃত / quoted a message of another thread');
    }

    // ── message info ─────────────────────────────────────────────────────

    public function test_message_info_fills_delivered_then_read_per_member(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $c = $this->person('Chhoton');
        $thread = $this->direct($a, $b);
        $id = $this->say($a, $thread, 'info please')['id'];

        $rows = $this->phone($a, 'GET', "/api/v1/messenger/messages/{$id}/info")->assertOk()->json('data');
        $this->assertCount(1, $rows, 'only the other member, never the sender');
        $this->assertSame((string) $b->id, $rows[0]['person']['id']);
        $this->assertNull($rows[0]['delivered_at']);
        $this->assertNull($rows[0]['read_at']);

        $this->fetch($b, $thread);
        $rows = $this->phone($a, 'GET', "/api/v1/messenger/messages/{$id}/info")->assertOk()->json('data');
        $this->assertNotNull($rows[0]['delivered_at'], '⛔ দেখার পরেও "পৌঁছেছে" সময় নেই / no delivered_at after the fetch');
        $this->assertNull($rows[0]['read_at']);

        $this->phone($b, 'POST', "/api/v1/messenger/threads/{$thread}/read", ['up_to' => (int) $id])->assertNoContent();
        $rows = $this->phone($a, 'GET', "/api/v1/messenger/messages/{$id}/info")->assertOk()->json('data');
        $this->assertNotNull($rows[0]['read_at'], '⛔ পড়ার পরেও "পড়েছেন" সময় নেই / no read_at after read');

        $this->phone($c, 'GET', "/api/v1/messenger/messages/{$id}/info")->assertNotFound();
    }

    /** @param  array<string, mixed>  $answer @return array<string, mixed>|null */
    private function stateIn(array $answer, string $id): ?array
    {
        return $this->byId($answer['meta']['states'] ?? [], $id);
    }
}
