<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\IssuedNumber;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseLots;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ লটের নম্বর নিজে থেকে — মালিকের আদেশ, ৫ অক্টোবর ২০২৬: *"এখন থেকে লটে নিজে থেকে প্রস্তাব দেবে, DDMMYY/XX-LOT; মানে
 * আজ কেনা হলে 051026/01-LOT। আর লটের এই প্রস্তাবের জন্য নম্বর-ক্রমে কন্ট্রোল প্যানেলে জায়গা দাও।"*
 *
 * ⭐ দাবি:
 *   ক · আজ দুইটা কেনা → DDMMYY/01-LOT আর DDMMYY/02-LOT; একটা কাগজের সব খালি লট একই নম্বর
 *   খ · কাল আবার ০১; পেছনের তারিখের কাগজ নিজের দিনের নম্বর পায়, আজকের নয়
 *   গ · নম্বর-ক্রমের পাতায় ছক বদলালে পরের লট নতুন ছকে; পুরো তারিখ ছাড়া ছকে রোজ-০১ বসে না
 *   ঘ · হাতে লেখা লট যেমন লেখা তেমনই থাকে, আর সিরিজ এক ধাপও এগোয় না
 *   ঙ · ধাক্কা: হাতে লেখা পুরনো লট নম্বর → মাল সেই পুরনো লটেই (পুরনো মেয়াদে); নিজে বসানো নম্বর কখনো পুরনো লটে মেশে না
 *   চ · পাতা খুললে নম্বর খরচ হয় না, কেবল প্রস্তাব; খসড়া আবার সংরক্ষণে কাগজের আগের নম্বরটাই ফেরে
 *   ছ · মাল গ্রহণের পথেও একই; বাকি সব নম্বর-ক্রম আগের মতো — রোজ-০১ কেবল LOT-এ
 */
final class TheLotNumberComesFromTheSeriesTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $soap;

    private Product $syrup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        [$this->soap, $this->syrup] = Product::query()->active()->orderBy('id')->take(2)->get()->all();
        $this->soap->forceFill(['track_batch' => true])->save();
        $this->syrup->forceFill(['track_batch' => true])->save();
    }

    // ── ক · আজ দুইটা কেনা — ০১ আর ০২; একটা কাগজ একটা লট ────────────────────

    public function test_two_purchases_today_get_01_and_02_and_one_paper_is_one_lot(): void
    {
        $first = $this->buyDirect([$this->line($this->soap), $this->line($this->syrup)]);
        $second = $this->buyDirect([$this->line($this->soap)]);

        $today = now()->format('dmy');

        $this->assertSame([$today.'/01-LOT', $today.'/01-LOT'], $this->lotsOf($first),
            'একটা কাগজের দুই সারি দুই আলাদা নম্বর পেয়েছে — একটা কেনা একটা লট হওয়ার কথা।');
        $this->assertSame([$today.'/02-LOT'], $this->lotsOf($second), 'পরের কেনা ০২ পায়নি।');

        // ⓘ লট পণ্য ধরে অনন্য — একই নম্বরে দুই পণ্যের দুই আলাদা লট, আর সাবানের ০১ ও ০২ আলাদা
        $this->assertSame(1, Batch::query()->where('product_id', $this->syrup->id)->where('batch_no', $today.'/01-LOT')->count());
        $this->assertSame(2, Batch::query()->where('product_id', $this->soap->id)->whereIn('batch_no', [$today.'/01-LOT', $today.'/02-LOT'])->count());
    }

    // ── খ · কাল আবার ০১; কাগজের তারিখ, আজকের নয় ───────────────────────────

    public function test_tomorrow_starts_at_01_again_and_a_back_dated_paper_takes_its_own_day(): void
    {
        $this->buyDirect([$this->line($this->soap)]);
        $this->buyDirect([$this->line($this->soap)]);

        // ⓘ পেছনের তারিখের কাগজ — ঐ দিনের প্রথম লট, আজকের ০৩ নয়
        $back = now()->subDays(3);
        $this->assertSame([$back->format('dmy').'/01-LOT'], $this->lotsOf($this->buyDirect([$this->line($this->soap)], $back)),
            '⛔ পেছনের তারিখের কাগজে আজকের তারিখ বা আজকের গুনতি বসল।');

        $this->travelTo(now()->addDay());

        $this->assertSame([now()->format('dmy').'/01-LOT'], $this->lotsOf($this->buyDirect([$this->line($this->soap)])),
            '⛔ পরের দিন লটের গুনতি ০১ থেকে শুরু হয়নি।');
    }

    // ── গ · ছক বদলালে নতুন ছক ────────────────────────────────────────────────

    public function test_a_changed_shape_on_the_series_page_gives_the_next_lot_in_the_new_shape(): void
    {
        $this->buyDirect([$this->line($this->soap)]);
        $series = NumberSeries::query()->where('doc_type', PurchaseLots::DOC_TYPE)->firstOrFail();

        // ⓘ ডিফল্ট ছক — মালিকের লেখা ধাঁচ, দুই ঘর, রোজ ০১
        $this->assertSame('{DD}{MM}{YY}/{SEQ}-{PREFIX}', $series->format);
        $this->assertSame('LOT', $series->prefix);
        $this->assertSame(2, $series->padding);
        $this->assertTrue($series->reset_daily);

        $this->put(route('master_data.series.update', $series), [
            'prefix' => 'LT', 'suffix' => '', 'padding' => 3,
            'format' => '{PREFIX}-{YYYY}{MM}{DD}-{SEQ}', 'reset_daily' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(['LT-'.now()->format('Ymd').'-001'], $this->lotsOf($this->buyDirect([$this->line($this->soap)])),
            '⛔ নম্বর-ক্রমের পাতায় বদলানো ছক পরের লটে বসেনি।');
        $this->assertSame(['LT-'.now()->format('Ymd').'-002'], $this->lotsOf($this->buyDirect([$this->line($this->soap)])));

        // ⛔ পুরো তারিখ ছাড়া ছকে রোজ-০১ বসে না — দিন আছে কিন্তু মাস-বছর নেই: ৫ তারিখের ০০১ পরের মাসের ৫ তারিখে আবার আসত
        foreach (['{DD}/{SEQ}-{PREFIX}', '{DD}{MM}/{SEQ}-{PREFIX}', '{PREFIX}-{SEQ}'] as $format) {
            $series->forceFill(['reset_daily' => true, 'format' => '{DD}{MM}{YY}/{SEQ}-{PREFIX}'])->save();

            $this->put(route('master_data.series.update', $series), [
                'prefix' => 'LT', 'suffix' => '', 'padding' => 3, 'format' => $format, 'reset_daily' => '1',
            ])->assertSessionHas('warning', __('master_data::message.reset_needs_a_day'));

            $this->assertFalse($series->fresh()->reset_daily, '⛔ পুরো তারিখহীন ছকে রোজ-০১ বসে গেল: '.$format);
        }
    }

    // ── ঘ · হাতে লেখা লট যেমন তেমন, সিরিজ ছোঁয় না ────────────────────────────

    public function test_a_hand_typed_lot_is_kept_and_does_not_spend_the_series(): void
    {
        $bill = $this->buyDirect([$this->line($this->soap, ['batch_no' => 'MILL-889'])]);

        $this->assertSame(['MILL-889'], $this->lotsOf($bill));
        $this->assertSame(0, $this->issuedLots(), '⛔ হাতে লেখা লটেও সিরিজ থেকে নম্বর কাটা হলো।');

        // ⓘ তাই পরের খালি লট এখনো আজকের ০১
        $this->assertSame([now()->format('dmy').'/01-LOT'], $this->lotsOf($this->buyDirect([$this->line($this->soap)])));
    }

    // ── ঙ · ধাক্কা ─────────────────────────────────────────────────────────

    public function test_a_hand_typed_lot_that_exists_joins_the_old_lot_with_its_old_expiry(): void
    {
        $early = now()->addMonths(4)->toDateString();
        $late = now()->addYear()->toDateString();

        $this->buyDirect([$this->line($this->soap, ['batch_no' => 'OLD-7', 'expiry_date' => $early])]);
        $this->buyDirect([$this->line($this->soap, ['batch_no' => 'OLD-7', 'expiry_date' => $late])]);

        $lots = Batch::query()->where('product_id', $this->soap->id)->where('batch_no', 'OLD-7')->get();

        // ⓘ আগের নিয়ম, বদলানো হয়নি: লট পণ্য ধরে অনন্য (inv_batches), BatchService::receive() firstOrCreate
        $this->assertCount(1, $lots, 'একই পণ্যে একই লট নম্বর দুই লট হয়ে গেল।');
        $this->assertSame($early, $lots->first()->expiry_date?->toDateString(),
            'দ্বিতীয় কেনার মেয়াদ পুরনো লটের মেয়াদ বদলে দিল — নিয়ম ছিল পুরনোটাই থাকে।');
        $this->assertSame(0, bccomp($this->inLot((int) $lots->first()->id), '20', 4), 'দুই কেনার মাল একই লটে ঢোকেনি।');
    }

    public function test_an_automatic_number_never_lands_in_a_lot_someone_typed_by_hand(): void
    {
        $taken = now()->format('dmy').'/01-LOT';

        // ⓘ কেউ আগে হাতে লিখে রেখেছেন ঠিক আজকের প্রথম নম্বরটা
        $this->buyDirect([$this->line($this->soap, ['batch_no' => $taken, 'expiry_date' => now()->addMonths(2)->toDateString()])]);

        $auto = $this->buyDirect([$this->line($this->soap)]);

        $this->assertSame([now()->format('dmy').'/02-LOT'], $this->lotsOf($auto),
            '⛔ নিজে বসানো নম্বর হাতে লেখা পুরনো লটে মিশে গেল — নতুন মাল পুরনো মেয়াদে বসত।');
        $this->assertSame(0, bccomp($this->inLot((int) Batch::query()->where('product_id', $this->soap->id)->where('batch_no', $taken)->value('id')), '10', 4));
    }

    // ── চ · পাতা খুললে খরচ নয়; খসড়া আবার সংরক্ষণে একই নম্বর ─────────────────

    public function test_opening_a_screen_spends_nothing_and_shows_the_next_lot(): void
    {
        foreach (['purchase.direct.create', 'purchase.bill.create', 'purchase.receipt.create'] as $screen) {
            $html = (string) $this->get(route($screen))->assertOk()->getContent();

            $this->assertTrue(
                str_contains($html, now()->format('dmy').'/01-LOT') || str_contains($html, now()->format('dmy').'\/01-LOT'),
                $screen.' পাতায় পরের লট নম্বরের প্রস্তাব নেই।',
            );
        }

        $this->assertSame(0, $this->issuedLots(), '⛔ পাতা খুলতেই লটের নম্বর খরচ হলো।');
    }

    public function test_saving_a_draft_again_keeps_the_papers_lot(): void
    {
        $bills = app(PurchaseBillService::class);
        $data = ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()];

        $draft = $bills->create($data, [$this->line($this->soap)]);
        $this->assertSame([now()->format('dmy').'/01-LOT'], $this->lotsOf($draft));

        // ⓘ খসড়া খুলে আরেকটা খালি-লট সারি যোগ — কাগজটা একটাই, লটও একটাই
        $again = $bills->update($draft->fresh(), $data, [
            $this->line($this->soap, ['batch_no' => '']),
            $this->line($this->syrup),
        ]);

        $this->assertSame([now()->format('dmy').'/01-LOT', now()->format('dmy').'/01-LOT'], $this->lotsOf($again),
            '⛔ খসড়া আবার সংরক্ষণে নতুন লট নম্বর কাটা হলো।');
        $this->assertSame(1, $this->issuedLots());

        $bills->confirm($again->fresh());
        $this->assertSame(1, Batch::query()->where('product_id', $this->syrup->id)->where('batch_no', now()->format('dmy').'/01-LOT')->count());
    }

    // ── ছ · মাল গ্রহণ, আর বাকি নম্বর-ক্রম অক্ষত ───────────────────────────────

    public function test_a_goods_receipt_gets_the_lot_too(): void
    {
        $receipts = app(PurchaseReceiptService::class);

        $receipt = $receipts->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->soap->id, 'received_qty' => '5', 'rate' => '10']],
        );

        $this->assertSame(now()->format('dmy').'/01-LOT', (string) $receipt->lines->first()->batch_no);

        $receipts->confirm($receipt->fresh());
        $this->assertTrue(Batch::query()->where('product_id', $this->soap->id)->where('batch_no', now()->format('dmy').'/01-LOT')->exists(),
            'মাল গ্রহণে নম্বরটা বসেছে, কিন্তু লটটা জন্মায়নি।');
    }

    public function test_only_the_lot_series_restarts_every_day(): void
    {
        $this->buyDirect([$this->line($this->soap)]);

        $daily = NumberSeries::query()->where('reset_daily', true)->pluck('doc_type')->unique()->values()->all();
        $this->assertSame([PurchaseLots::DOC_TYPE], $daily, '⛔ LOT ছাড়া অন্য সিরিজেও রোজ-০১ বসে গেল।');

        // ⓘ {DD} অন্য কাগজে কেবল দেখায় — গুনতি চলে (মালিক, ৫ সেপ্টেম্বর ২০২৬); দিন বদলালেও ক্রম এগোয়
        $engine = app(NumberSeriesEngine::class);
        $engine->next('SO', null, Carbon::today());

        $series = NumberSeries::query()->where('doc_type', 'SO')->firstOrFail();
        $series->update(['format' => '{PREFIX}-{YYYY}{MM}{DD}-{SEQ}']);
        $this->assertFalse($series->fresh()->reset_daily);

        $one = $engine->next('SO', null, Carbon::today());
        $two = $engine->next('SO', null, Carbon::today()->addDay());

        $this->assertSame((int) substr($one, -4) + 1, (int) substr($two, -4), '⛔ বিক্রির নম্বর দিন বদলে ১ থেকে শুরু হলো।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function buyDirect(array $lines, ?Carbon $date = null): PurchaseBill
    {
        return app(DirectPurchaseService::class)->complete([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => ($date ?? now())->toDateString(),
            'supplier_bill_no' => 'LOT-'.fake()->unique()->numberBetween(10000, 99999),
        ], $lines)['bill'];
    }

    /** @return array<string, mixed> */
    private function line(Product $product, array $extra = []): array
    {
        return ['product_id' => $product->id, 'qty' => '10', 'rate' => '50', 'sales_price' => '60', 'batch_no' => '', ...$extra];
    }

    /** @return list<string> */
    private function lotsOf(PurchaseBill $bill): array
    {
        return $bill->fresh('lines')->lines->sortBy('line_no')->pluck('batch_no')->map(fn ($b) => (string) $b)->values()->all();
    }

    private function issuedLots(): int
    {
        return IssuedNumber::query()
            ->whereIn('number_series_id', NumberSeries::query()->where('doc_type', PurchaseLots::DOC_TYPE)->select('id'))
            ->count();
    }

    private function inLot(int $batchId): string
    {
        return (string) DB::table('inv_stock_movements')
            ->where('batch_id', $batchId)
            ->selectRaw('COALESCE(SUM(floor_change + unplaced_change), 0) as q')
            ->value('q');
    }
}
