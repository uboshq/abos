<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Messaging;

use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageFile;
use App\Modules\Messaging\Models\Thread;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Messenger phone doors — forward, delete (for me / for everyone, the hour's window) and the ERP paper card
 * (sender must open it; each receiver sees only what their own keys open).
 */
final class MessengerForwardDeletePaperTest extends TestCase
{
    use MessengerKit;
    use RefreshDatabase;

    /** the print door's key for a sales invoice ([[ThePhoneCouldNotPrintWhatItSawTest]]) */
    private const INVOICE_KEY = 'sales.invoice.view';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMessenger();
    }

    // ── forward ──────────────────────────────────────────────────────────

    public function test_forward_copies_a_text_and_a_photo_and_shares_the_stored_file(): void
    {
        Storage::fake(MessageFile::DISK);
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $c = $this->person('Chhoton');
        $thread = $this->direct($a, $b);

        $text = $this->say($a, $thread, 'rate list')['id'];
        $photo = $this->upload($a, "/api/v1/messenger/threads/{$thread}/messages", [
            'kind' => 'image', 'file' => UploadedFile::fake()->image('rate.jpg', 400, 300),
        ])->assertCreated()->json('data.id');
        $path = MessageFile::query()->where('message_id', (int) $photo)->value('path');

        $sent = $this->phone($a, 'POST', '/api/v1/messenger/forward', [
            'message_ids' => [(int) $text, (int) $photo], 'to' => ['person:'.$c->id], 'client_id' => 'fw-1',
        ])->assertCreated()->assertJsonPath('data.sent', 1)->json('data.thread_ids');

        $rows = $this->fetch($c, $sent[0])['data'];
        $this->assertSame(['text', 'image'], array_column($rows, 'kind'));
        $this->assertSame([true, true], array_column($rows, 'forwarded'), '⛔ ফরওয়ার্ড চিহ্ন নেই / not marked forwarded');
        $this->assertSame('rate list', $rows[0]['text']);

        $this->assertSame(2, MessageFile::query()->where('path', $path)->count(),
            '⛔ ফরওয়ার্ডে একই ফাইলের নতুন সারি নয় / the forwarded photo does not reuse the stored path');
        $this->phone($c, 'GET', $rows[1]['attachment']['url'])->assertOk();
    }

    public function test_forwarding_a_message_of_a_thread_one_is_not_in_is_404(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $c = $this->person('Chhoton');
        $id = $this->say($a, $this->direct($a, $b), 'secret')['id'];
        $before = Message::query()->count();

        $this->phone($c, 'POST', '/api/v1/messenger/forward', ['message_ids' => [(int) $id], 'to' => ['person:'.$a->id]])
            ->assertNotFound();

        $this->assertSame($before, Message::query()->count(), '⛔ অন্যের বার্তা ফরওয়ার্ড হয়েছে / a stranger forwarded someone else\'s message');
    }

    // ── delete ───────────────────────────────────────────────────────────

    public function test_delete_for_me_hides_it_only_for_me(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $thread = $this->direct($a, $b);
        $id = $this->say($a, $thread, 'oops')['id'];

        $this->phone($a, 'DELETE', "/api/v1/messenger/messages/{$id}", ['for' => 'me'])->assertNoContent();

        $this->assertNull($this->byId($this->fetch($a, $thread)['data'], $id), '⛔ "আমার জন্য মুছুন"-এর পরেও দেখা যায় / still visible to me');
        $forB = $this->byId($this->fetch($b, $thread)['data'], $id);
        $this->assertSame('text', $forB['kind'] ?? null, '⛔ অন্য সদস্যের কাছ থেকেও মুছে গেল / gone for the other member too');
        $this->assertSame('oops', $forB['text']);
    }

    public function test_delete_for_everyone_leaves_a_tombstone_and_closes_the_file(): void
    {
        Storage::fake(MessageFile::DISK);
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $thread = $this->direct($a, $b);
        $data = $this->upload($a, "/api/v1/messenger/threads/{$thread}/messages", [
            'kind' => 'image', 'text' => 'wrong slip', 'file' => UploadedFile::fake()->image('wrong.jpg', 300, 300),
        ])->assertCreated()->json('data');
        $id = $data['id'];

        $this->phone($b, 'GET', $data['attachment']['url'])->assertOk();

        $this->phone($a, 'DELETE', "/api/v1/messenger/messages/{$id}", ['for' => 'everyone'])->assertNoContent();

        foreach ([$a, $b] as $who) {
            $answer = $this->fetch($who, $thread, 0);
            $row = $this->byId($this->fetch($who, $thread)['data'], $id);
            $this->assertSame('deleted', $row['kind'] ?? null, "⛔ {$who->name}: মোছা বার্তা এখনো আছে / not a tombstone");
            $this->assertSame('', $row['text']);
            $this->assertNull($row['attachment'], "⛔ {$who->name}: মোছা বার্তার ছবি / the deleted message still carries its photo");
            $this->assertContains(['id' => $id, 'state' => 'deleted'], $answer['meta']['states'],
                "⛔ {$who->name}: meta.states-এ মোছার খবর নেই / the poll does not say deleted");
        }

        $this->phone($b, 'GET', $data['attachment']['url'])->assertNotFound();
        $this->phone($a, 'GET', $data['attachment']['url'])->assertNotFound();
    }

    public function test_delete_for_everyone_is_refused_after_the_hour(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $thread = $this->direct($a, $b);
        $id = $this->say($a, $thread, 'too late')['id'];

        $this->travel(61)->minutes();

        $this->phone($a, 'DELETE', "/api/v1/messenger/messages/{$id}", ['for' => 'everyone'])
            ->assertForbidden()->assertJsonPath('error', 'delete_not_allowed');
        $this->assertSame('text', $this->byId($this->fetch($b, $thread)['data'], $id)['kind'] ?? null);
    }

    public function test_only_the_sender_deletes_for_everyone_and_an_outsider_gets_404(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $c = $this->person('Chhoton');
        $thread = $this->direct($a, $b);
        $id = $this->say($a, $thread, 'mine')['id'];

        $this->phone($b, 'DELETE', "/api/v1/messenger/messages/{$id}", ['for' => 'everyone'])
            ->assertForbidden()->assertJsonPath('error', 'delete_not_allowed');
        $this->phone($c, 'DELETE', "/api/v1/messenger/messages/{$id}", ['for' => 'me'])->assertNotFound();
        $this->phone($c, 'DELETE', "/api/v1/messenger/messages/{$id}", ['for' => 'everyone'])->assertNotFound();

        $this->assertSame('text', $this->byId($this->fetch($a, $thread)['data'], $id)['kind'] ?? null);
    }

    // ── the paper card ───────────────────────────────────────────────────

    public function test_a_sender_who_may_not_open_the_paper_may_not_send_it(): void
    {
        $invoice = $this->invoice();
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $body = ['paper_type' => 'SalesInvoice', 'paper_id' => $invoice->public_id, 'to' => ['person:'.$b->id]];

        $this->phone($a, 'POST', '/api/v1/messenger/papers', $body)->assertForbidden()->assertJsonPath('error', 'paper_denied');
        $this->assertSame(0, Thread::query()->count(), '⛔ কাগজ না খুলেও কথোপকথন তৈরি / a thread was made for a refused paper');

        $this->grant($a, [self::INVOICE_KEY]);

        $this->phone($a, 'POST', '/api/v1/messenger/papers', $body)->assertCreated()->assertJsonPath('data.sent', 1);
    }

    public function test_each_receiver_sees_only_what_their_own_keys_open(): void
    {
        $invoice = $this->invoice();
        $a = $this->person('Asif', [self::INVOICE_KEY]);
        $b = $this->person('Bina', [self::INVOICE_KEY]);
        $c = $this->person('Chhoton');

        $ids = $this->phone($a, 'POST', '/api/v1/messenger/papers', [
            'paper_type' => 'SalesInvoice', 'paper_id' => $invoice->public_id,
            'to' => ['person:'.$b->id, 'person:'.$c->id], 'note' => 'দেখুন', 'client_id' => 'p-1',
        ])->assertCreated()->assertJsonPath('data.sent', 2)->json('data.thread_ids');
        $this->assertCount(2, $ids);

        $forB = $this->fetch($b, $ids[0])['data'][0];
        $this->assertSame('paper', $forB['kind']);
        $this->assertSame('দেখুন', $forB['text']);
        $this->assertTrue($forB['paper']['can_open'], '⛔ চাবিওয়ালা প্রাপক কাগজ খুলতে পারেন না / the receiver with the key cannot open');
        $this->assertSame($invoice->document_no, $forB['paper']['no']);
        $this->assertStringStartsWith('৳', $forB['paper']['amount'], 'the card of a receiver who may open it carries the amount');

        $forC = $this->fetch($c, $ids[1])['data'][0]['paper'];
        $this->assertFalse($forC['can_open'], '⛔ চাবিহীন প্রাপক কাগজ খুলতে পারেন / the receiver without the key may open');
        $this->assertSame('', $forC['amount'], '⛔ চাবিহীন প্রাপক টাকার অঙ্ক দেখেন / the amount leaked');
        $this->assertSame('', $forC['party'], '⛔ চাবিহীন প্রাপক পক্ষের নাম দেখেন / the party leaked');
        $this->assertSame('', $forC['status'], '⛔ চাবিহীন প্রাপক অবস্থা দেখেন / the status leaked');
        $this->assertSame(['SalesInvoice', $invoice->public_id, $invoice->document_no],
            [$forC['type'], $forC['id'], $forC['no']], 'the number still shows without the key');
    }

    public function test_the_share_sheet_says_who_may_open_the_paper(): void
    {
        $invoice = $this->invoice();
        $a = $this->person('Asif', [self::INVOICE_KEY]);
        $b = $this->person('Bina', [self::INVOICE_KEY]);
        $c = $this->person('Chhoton');

        $people = $this->phone($a, 'GET', '/api/v1/messenger/people?'.http_build_query([
            'paper_type' => 'SalesInvoice', 'paper_id' => $invoice->public_id,
        ]))->assertOk()->json('data');

        $this->assertTrue($this->byId($people, (string) $b->id)['can_open_paper'] ?? null);
        $this->assertFalse($this->byId($people, (string) $c->id)['can_open_paper'] ?? null,
            '⛔ চাবিহীন মানুষকে "খুলতে পারবেন" দেখায় / the share sheet says a keyless person may open');

        $plain = $this->phone($a, 'GET', '/api/v1/messenger/people')->assertOk()->json('data');
        $this->assertArrayNotHasKey('can_open_paper', $this->byId($plain, (string) $b->id) ?? [], 'can_open_paper comes only with a paper');
    }

    /** A confirmed sales invoice of today — the real road ([[SalesInvoiceService]]), by the owner. */
    private function invoice(): SalesInvoice
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $service = app(SalesInvoiceService::class);
        $invoice = $service->confirm($service->create(
            [
                'customer_id' => Customer::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => Carbon::today()->toDateString(),
            ],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '1', 'rate' => '100']],
        ))->fresh();

        $this->assertNotEmpty($invoice->public_id, 'the invoice has no public_id — the claim measures nothing');
        $this->assertNotEmpty($invoice->document_no);
        $this->app['auth']->forgetGuards();

        return $invoice;
    }
}
