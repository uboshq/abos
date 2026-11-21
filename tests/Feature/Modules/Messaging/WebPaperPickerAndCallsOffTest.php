<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Messaging;

use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The web messenger's last pieces (sample M1UddRxK v7):
 *
 *   ১. attach → "ERP কাগজ": the picker lists only papers the sender may open; the picked one goes into the open chat
 *   ২. calls_off: LIVEKIT_URL / KEY / SECRET empty → the page draws no call button and no group-call badge
 *   ৩. "বার্তায় পাঠান" on a paper's own page (its ⋯) — three ABOS looks only; SAP and Dynamic unchanged
 */
final class WebPaperPickerAndCallsOffTest extends TestCase
{
    use MessengerKit;
    use RefreshDatabase;

    private const INVOICE_KEY = 'sales.invoice.view';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMessenger();
        Queue::fake();
    }

    // ── ১. attach → ERP কাগজ ─────────────────────────────────────────────

    public function test_the_picker_lists_only_papers_the_sender_may_open(): void
    {
        $invoice = $this->invoice();
        $keyless = $this->person('Asif');
        $keyed = $this->person('Bina', [self::INVOICE_KEY]);

        $none = $this->web($keyless)->getJson(route('messaging.x.papers.find', ['q' => $invoice->document_no]))->assertOk()->json('data');
        $this->assertNull($this->paperIn($none, $invoice), '⛔ চাবি ছাড়াই কাগজটা বাছার তালিকায় / a keyless sender sees the paper in the picker');

        $found = $this->web($keyed)->getJson(route('messaging.x.papers.find', ['q' => $invoice->document_no]))->assertOk()->json('data');
        $row = $this->paperIn($found, $invoice);
        $this->assertNotNull($row, 'the sender with the key finds the paper by its number');
        $this->assertSame('SalesInvoice', $row['type'], 'the type is the phone print door\'s name — the same the share door takes');
        $this->assertSame($invoice->document_no, $row['no']);
        $this->assertStringStartsWith('৳', $row['amount']);

        // ⓘ empty search = my most recent papers
        $recent = $this->web($keyed)->getJson(route('messaging.x.papers.find'))->assertOk()->json('data');
        $this->assertNotNull($this->paperIn($recent, $invoice), 'an empty search lists the most recent papers');

        // ⓘ LIKE's own characters are only characters
        $wild = $this->web($keyed)->getJson(route('messaging.x.papers.find', ['q' => '%%']))->assertOk()->json('data');
        $this->assertNull($this->paperIn($wild, $invoice), '⛔ "%" matched every paper');
    }

    public function test_the_picker_is_refused_without_the_messenger_key(): void
    {
        $nobody = $this->person('No Key', [self::INVOICE_KEY], use: false);

        $this->web($nobody)->getJson(route('messaging.x.papers.find'))->assertForbidden();
    }

    public function test_the_picked_paper_goes_into_the_open_chat(): void
    {
        $invoice = $this->invoice();
        $a = $this->person('Asif', [self::INVOICE_KEY]);
        $b = $this->person('Bina');
        $thread = $this->direct($a, $b);

        $this->web($a)->postJson(route('messaging.x.papers'), [
            'paper_type' => 'SalesInvoice', 'paper_id' => $invoice->public_id, 'to' => ['thread:'.$thread], 'note' => 'দেখুন',
        ])->assertCreated()->assertJsonPath('data.thread_ids.0', $thread);

        $last = collect($this->fetch($b, $thread)['data'])->last();
        $this->assertSame('paper', $last['kind'] ?? null);
        $this->assertSame($invoice->document_no, $last['paper']['no'] ?? null);
        $this->assertSame('দেখুন', $last['text'] ?? null);
    }

    // ── ২. calls_off ─────────────────────────────────────────────────────

    public function test_without_livekit_the_page_draws_no_call_button(): void
    {
        config(['services.livekit.url' => '', 'services.livekit.key' => '', 'services.livekit.secret' => '']);
        $me = $this->person('Web Me');

        $html = (string) $this->web($me)->get(route('messaging.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-act="call-video"', $html, '⛔ calls_off, yet a video-call button');
        $this->assertStringNotContainsString('data-act="call-voice"', $html, '⛔ calls_off, yet a voice-call button');
        // ⓘ the badge's markup, not its words — the words ride along in data-words for the page script anyway
        $this->assertStringNotContainsString('class="soon2"', $html, '⛔ calls_off, yet the group-call badge');
        $this->assertStringContainsString('data-calls="0"', $html);
    }

    public function test_with_livekit_the_page_draws_both_call_buttons(): void
    {
        config(['services.livekit.url' => 'wss://rtc.example.test', 'services.livekit.key' => 'k', 'services.livekit.secret' => 's']);
        $me = $this->person('Web Me');

        $html = (string) $this->web($me)->get(route('messaging.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-act="call-video"', $html);
        $this->assertStringContainsString('data-act="call-voice"', $html);
        $this->assertStringContainsString('class="soon2"', $html, 'the group-call badge of the sample');
        $this->assertStringContainsString('data-calls="1"', $html);
    }

    // ── ৩. a paper's own page ────────────────────────────────────────────

    public function test_a_paper_page_offers_send_in_a_message_in_the_abos_looks_only(): void
    {
        $invoice = $this->invoice();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        foreach (['rose', 'navy', 'blue'] as $look) {
            $html = $this->paperPage($owner, $look, $invoice);
            $this->assertStringContainsString('data-msgr-share=', $html, "⛔ {$look}: the invoice page's ⋯ has no \"বার্তায় পাঠান\"");
            $this->assertStringContainsString(e($invoice->public_id), $html);
        }

        foreach (['tiles', 'dynamic'] as $look) {
            $this->assertStringNotContainsString('data-msgr-share=', $this->paperPage($owner, $look, $invoice), "⛔ {$look}: the page head changed");
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function web(User $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh());
    }

    private function paperPage(User $user, string $look, SalesInvoice $invoice): string
    {
        $user->forceFill(['ui' => $look])->save();

        return (string) $this->web($user)->get(route('sales.invoice.show', ['invoice' => $invoice->id]))->assertOk()->getContent();
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function paperIn(array $rows, SalesInvoice $invoice): ?array
    {
        foreach ($rows as $row) {
            if (($row['id'] ?? null) === $invoice->public_id) {
                return $row;
            }
        }

        return null;
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
        $this->app['auth']->forgetGuards();

        return $invoice;
    }
}
