<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সরবরাহকারী অন্য দরে বিল করলে মালের দাম চালানের দামেই থাকত — পুরো-ERP অডিট, ৯ অক্টোবর ২০২৬, ক্রয় ⚠️৩ (খাতার দিক;
 * স্তরের দিক cb-র 90bb3435)।
 *
 * ⛔ আগে পুরো পার্থক্য ৫১৫০-এ, তাকের মাল আর পরের বিক্রয়ের খরচ চালানের দামে।
 * ⭐ বিল গোটা চালানের হলে: তাকের পার্থক্য মজুদে, আগেই বেরোনো অংশের পার্থক্য বিক্রীত মালের খরচে, ৫১৫০-এ কিছু না।
 * ⭐ বাতিল বা সম্পাদনায় স্তর চালানের দামে ফেরে, আর মাঝে নতুন দামে যা বেরিয়েছে তার সংশোধনী — মজুদ খাত সবসময় স্তরের মূল্যের সমান।
 * ⓘ আংশিক বিল আগের মতো ৫১৫০-এ।
 *
 * চালান ১০০ × ৫০; ৪০ বিক্রি; বিল ১০০ × ৫২ → মজুদ +১২০ (৬০ × ২), খরচ +৮০ (৪০ × ২); আরও ৩০ বিক্রি ৫২-তে; বাতিল →
 * মজুদ = ৩০ × ৫০, মোট খরচ = ৭০ × ৫০।
 */
final class TheBillsPriceNeverReachedTheShelfTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private int $sales = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SettingsService::class)->set('purchase.block_price_mismatch', false);

        // ⓘ নতুন পণ্য, কোনো স্তর নেই — FIFO যেন কেবল এই চালানের স্তর থেকে তোলে (ডেমোর সব পণ্যের খোলা মজুদ আছে)
        $model = Product::query()->where('track_batch', false)->where('is_active', true)->orderBy('id')->firstOrFail();
        $this->product = $model->replicate(['public_id']);
        $this->product->forceFill(['code' => 'REVAL-1', 'barcode' => null, 'name_en' => 'Revalue test', 'name_bn' => 'দাম-বদল পরীক্ষা'])->save();
        $this->assertFalse(CostLayer::query()->withoutGlobalScopes()->where('product_id', $this->product->id)->exists());
    }

    public function test_a_whole_bill_moves_the_shelf_and_the_sold_part_and_its_cancel_puts_them_back(): void
    {
        $start = $this->books();
        $receipt = $this->receive('100', '50');
        $this->sell('40');

        $bill = $this->bills()->confirm($this->bill($receipt, '100', '52'));
        $now = $this->books($start);

        $this->assertSame(0, bccomp($now['variance'], '0', 4), '⛔ পার্থক্য এখনও ৫১৫০-এ।');
        $this->assertSame(0, bccomp($now['stock'], '3120', 4), '⛔ তাকের ৬০-এর পার্থক্য মজুদে বসেনি।');
        $this->assertSame(0, bccomp($now['cost'], '2080', 4), '⛔ বেরোনো ৪০-এর পার্থক্য বিক্রীত মালের খরচে বসেনি।');
        $this->assertSame(0, bccomp($now['stock'], $this->layers(), 4), '⛔ মজুদ খাত আর স্তরের মূল্য আলাদা।');

        $this->sell('30');
        $this->assertSame(0, bccomp($this->books($start)['stock'], $this->layers(), 4), '⛔ নতুন দামে বিক্রির পরে মজুদ খাত আর স্তর আলাদা।');

        $this->bills()->cancel($bill->fresh(), 'ভুল বিল');
        $after = $this->books($start);

        $this->assertSame(0, bccomp($this->layers(), '1500', 4), '⛔ বাতিলে স্তর চালানের দামে ফেরেনি।');
        $this->assertSame(0, bccomp($after['stock'], '1500', 4), '⛔ বাতিলের পরে মজুদ খাত স্তরের সাথে মেলে না।');
        $this->assertSame(0, bccomp($after['cost'], '3500', 4), '⛔ বাতিলের পরে ৭০টার খরচ চালানের দামে নয়।');
        $this->assertSame(0, bccomp($after['variance'], '0', 4));
        $this->assertSame(0, bccomp($after['grni'], '-5000', 4), 'বাতিলে দায় ২১৬০-এ ফেরেনি।');
        $this->assertTrue(LedgerEntry::query()->where('source_type', PurchaseBill::drillSourceType().':revalue')->where('source_id', $bill->id)->exists(),
            'দৃশ্যটাই বানানো যায়নি — নতুন দামে কিছু বেরোয়নি, সংশোধনী লাগেনি।');
    }

    public function test_editing_a_receipt_priced_bill_to_the_suppliers_price_moves_the_goods_and_back_again(): void
    {
        $start = $this->books();
        $receipt = $this->receive('100', '50');
        $bill = $this->bills()->confirm($this->bill($receipt, '100', '50'));
        $this->sell('10');

        $line = fn (string $rate) => [[
            'product_id' => $this->product->id, 'qty' => '100', 'rate' => $rate,
            'purchase_receipt_line_id' => $receipt->lines->first()->id,
        ]];

        // ⓘ সরবরাহকারীর আসল বিল ৫৫ — নিশ্চিত বিল সম্পাদনা (মাল-গ্রহণে বিল জন্মায় চালানের দামে, তারপর এটাই পথ)
        $bill = $this->bills()->update($bill->fresh(), ['trx_date' => now()->toDateString()], $line('55'), repost: true);
        $this->assertSame(0, bccomp($this->layers(), '4950', 4), '⛔ সম্পাদনায় স্তর বিলের দামে যায়নি (৯০ × ৫৫)।');
        $this->assertSame(0, bccomp($this->books($start)['stock'], $this->layers(), 4));
        $this->assertSame(0, bccomp($this->books($start)['cost'], '550', 4), '⛔ বেরোনো ১০-এর খরচ বিলের দামে নয়।');

        $this->sell('20');
        // ⓘ আবার সম্পাদনা, ৫১-এ — আগের সংশোধনী খোলা থাকলেও দুবার নয়
        $this->bills()->update($bill->fresh(), ['trx_date' => now()->toDateString()], $line('51'), repost: true);
        $books = $this->books($start);
        $this->assertSame(0, bccomp($this->layers(), '3570', 4), '⛔ দ্বিতীয় সম্পাদনায় স্তর ৫১-এ নয় (৭০ × ৫১)।');
        $this->assertSame(0, bccomp($books['stock'], $this->layers(), 4), '⛔ দুই সম্পাদনার পরে মজুদ খাত আর স্তর আলাদা।');
        /*
         * ⓘ বেরোনো ৩০-এর খরচ শেষ দামে (৩০ × ৫১ = ১,৫৩০) — খরচ আর ৫১৫০ মিলে। ⚠️ দ্বিতীয় সম্পাদনায় আগের স্তরগুলো "পুনর্মূল্যায়িত"
         * চিহ্ন পায়, তাই তাদের বেরোনো অংশের ৩০ টাকা যায় ৫১৫০-এ, বিক্রীত মালের খরচে নয় (স্তরের চিহ্নের নিয়ম, 90bb3435)।
         * মজুদ খাত তবু স্তরের সমান (উপরে), আর লাভ-ক্ষতির মোট খরচ ঠিক।
         */
        $this->assertSame(0, bccomp(bcadd($books['cost'], $books['variance'], 4), '1530', 4), '⛔ বেরোনো ৩০-এর মোট খরচ শেষ দামে নয় (৩০ × ৫১)।');

        // ⓘ তৃতীয়বার, ৫৩-এ — এবার আগের সংশোধনী খোলা: উল্টে, মিলিয়ে আবার বসে, দুবার নয়
        $this->sell('10');
        $this->bills()->update($bill->fresh(), ['trx_date' => now()->toDateString()], $line('53'), repost: true);
        $books = $this->books($start);
        $this->assertTrue(LedgerEntry::query()->where('source_type', PurchaseBill::drillSourceType().':revalue:reversal')->where('source_id', $bill->id)->exists(),
            'দৃশ্যটাই বানানো যায়নি — আগের সংশোধনী খোলা থাকার কথা, উল্টে মিলিয়ে বসার কথা।');
        $this->assertSame(0, bccomp($this->layers(), '3180', 4), '⛔ তৃতীয় সম্পাদনায় স্তর ৫৩-এ নয় (৬০ × ৫৩)।');
        $this->assertSame(0, bccomp($books['stock'], $this->layers(), 4), '⛔ তিন সম্পাদনার পরে মজুদ খাত আর স্তর আলাদা।');
        $this->assertSame(0, bccomp(bcadd($books['cost'], $books['variance'], 4), '2120', 4), '⛔ বেরোনো ৪০-এর মোট খরচ শেষ দামে নয় (৪০ × ৫৩)।');
    }

    public function test_a_part_bill_keeps_the_difference_in_the_variance_account(): void
    {
        $start = $this->books();
        $receipt = $this->receive('100', '50');
        $this->bills()->confirm($this->bill($receipt, '40', '52'));

        $books = $this->books($start);
        $this->assertSame(0, bccomp($books['variance'], '80', 4), 'আংশিক বিলের পার্থক্য ৫১৫০-এ থাকার কথা।');
        $this->assertSame(0, bccomp($this->layers(), '5000', 4), '⛔ আংশিক বিল স্তর বদলে দিল।');
        $this->assertSame(0, bccomp($books['stock'], '5000', 4));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function bills(): PurchaseBillService
    {
        return app(PurchaseBillService::class);
    }

    private function receive(string $qty, string $rate): PurchaseReceipt
    {
        return app(PurchaseReceiptService::class)->confirm(app(PurchaseReceiptService::class)->create([
            'supplier_id' => Supplier::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'received_qty' => $qty, 'rate' => $rate]]))->fresh(['lines']);
    }

    private function bill(PurchaseReceipt $receipt, string $qty, string $rate): PurchaseBill
    {
        return $this->bills()->create(
            ['supplier_id' => $receipt->supplier_id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate, 'purchase_receipt_line_id' => $receipt->lines->first()->id]],
        );
    }

    /** বিক্রয়ের মতো — স্তর থেকে FIFO, আর খাতায় খরচ */
    private function sell(string $qty): void
    {
        $cost = app(CostLayerService::class)->issue($this->product, $qty, 'test:sale', ++$this->sales, date: now())['cost'];
        app(PostingEngine::class)->post(sourceType: 'test:sale', sourceId: $this->sales, trxDate: now(), lines: [
            ['account_id' => StandardChart::find(StandardChart::COST_OF_GOODS_SOLD)->id, 'debit' => $cost],
            ['account_id' => StandardChart::find(StandardChart::INVENTORY)->id, 'credit' => $cost],
        ]);
    }

    private function layers(): string
    {
        return (string) CostLayer::query()->withoutGlobalScopes()->where('product_id', $this->product->id)
            ->selectRaw('COALESCE(SUM(qty_remaining * unit_cost), 0) as v')->value('v');
    }

    /** @return array{stock: string, cost: string, variance: string, grni: string} */
    private function books(?array $from = null): array
    {
        $sum = function (string $code) {
            $ids = Account::query()->where('code', 'like', $code.'%')->pluck('id');

            return (string) LedgerEntry::query()->withoutGlobalScopes()->whereIn('account_id', $ids)
                ->selectRaw('COALESCE(SUM(debit - credit), 0) as v')->value('v');
        };
        $now = ['stock' => $sum(StandardChart::INVENTORY), 'cost' => $sum(StandardChart::COST_OF_GOODS_SOLD),
            'variance' => $sum(StandardChart::PURCHASE_PRICE_VARIANCE), 'grni' => $sum(StandardChart::GOODS_RECEIVED_NOT_INVOICED)];

        return $from === null ? $now : array_map(fn ($k) => bcsub($now[$k], $from[$k], 4), array_combine(array_keys($now), array_keys($now)));
    }
}
