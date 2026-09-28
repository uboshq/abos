<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

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
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ক্রয়ের ভ্যাট সবার জন্যই ফেরতযোগ্য ধরা হত — মালিকের সিদ্ধান্ত (খ), ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ─────────────────────────────────────────────────────
 * ভ্যাট সবসময় ২১২০-এ যেত। যে দোকান ভ্যাট-নিবন্ধিত নয় (বা টার্নওভার
 * করে চলে) সে ওই ভ্যাট কোনোদিন ফেরত পায় না — তার কাছে ওটা মালেরই দাম।
 * তবু খাতায় একটা "পাওনা" জমত যা কেউ কোনোদিন দেবে না, আর মজুদের মাল
 * ভ্যাটের পরিমাণে সস্তা দেখাত — বেচার দিন লাভ ততটাই বেশি।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * কোম্পানি-প্রতি সুইচ `purchase.vat_recoverable`, ডিফল্ট হ্যাঁ (আজকের
 * আচরণ)। "না" হলে সরাসরি সারির ভ্যাট মালের দামে — স্তরে আর ১১২০-এ।
 * চালানের সারির মাল আগেই স্তরে বসেছে, তাই তার ভ্যাট ৫১৫০-এ, নিজের সারিতে।
 *
 * ⓘ একই কোম্পানি, সুইচ বন্ধ তারপর চালু — কেবল সুইচটাই বদলায়।
 */
final class TheVatWasRecoverableForEveryCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        // ⚠️ দুইটাই স্পষ্ট: চালান ছাড়া বিল চলে, আর দামের অমিল আটকায় —
        // অফেরতযোগ্য ভ্যাট যেন "অমিল" হয়ে না থামে, সেটাই মাপা হয়
        app(SettingsService::class)->set('purchase.receipt_needs_order', false);
        app(SettingsService::class)->set('purchase.block_price_mismatch', true);

        $this->supplier = Supplier::query()->forPurchasing()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail();
        $this->product->forceFill(['tax_id' => null])->save();
    }

    /** ⭐ ডিফল্ট হ্যাঁ — আজকের আচরণ, কেউ সুইচ না ছুঁলেও। */
    public function test_the_default_is_recoverable(): void
    {
        // ⚠️ বিকল্প মান false — না-ঘোষিত সুইচ যেন "হ্যাঁ" পড়ে সবুজ না হয়
        $this->assertTrue((bool) app(SettingsService::class)->get('purchase.vat_recoverable', false),
            'সুইচটা ঘোষিত নেই, নাকি ডিফল্ট "হ্যাঁ" নয়।');
    }

    /**
     * সরাসরি বিল — একই কোম্পানিতে সুইচ বন্ধ, তারপর চালু।
     *
     * হাতে কষা: ১০ × ১০০ = ১০০০, ভ্যাট ১৫০, মোট ১১৫০
     * ```
     * বন্ধ:  ১১২০ +১১৫০ · ২১২০ ০    · ২১১১ −১১৫০ · স্তর ১১৫০
     * চালু:  ১১২০ +১০০০ · ২১২০ +১৫০ · ২১১১ −১১৫০ · স্তর ১০০০
     * ```
     */
    public function test_off_puts_the_vat_into_the_goods_and_on_keeps_it_recoverable(): void
    {
        app(SettingsService::class)->set('purchase.vat_recoverable', false);

        [$bill, $moved] = $this->directBill();

        $this->assertSame(['1120' => '1150.0000', '2120' => '0.0000', '2111' => '-1150.0000', '5150' => '0.0000'], $moved,
            '⛔ সুইচ বন্ধ: ভ্যাট মালের দামে ঢোকার কথা, ২১২০-এ কিছু নয়।');
        $this->assertSame('1150.0000', $this->layerValue($bill),
            '⛔ সুইচ বন্ধ: খাতায় ১১৫০ অথচ স্তরে অন্য অঙ্ক — বেচার দিন খরচ খাতার সাথে মিলবে না।');

        app(SettingsService::class)->set('purchase.vat_recoverable', true);

        [$bill, $moved] = $this->directBill();

        $this->assertSame(['1120' => '1000.0000', '2120' => '150.0000', '2111' => '-1150.0000', '5150' => '0.0000'], $moved,
            '⛔ সুইচ চালু: ভ্যাট ২১২০-এ, মালের দামে নয় — আজকের আচরণ ভেঙেছে।');
        $this->assertSame('1000.0000', $this->layerValue($bill));
    }

    /**
     * চালানের মাল — স্তর আগেই বসেছে, তাই ফেরতযোগ্য নয় এমন ভ্যাট ৫১৫০-এ, নিজের সারিতে।
     *
     * ```
     * চালান ১০ × ১০০: ১১২০ +১০০০ · ২১৬০ −১০০০
     * বিল, ভ্যাট ১৫০: ২১৬০ +১০০০ · ৫১৫০ +১৫০ · ২১১১ −১১৫০ · ২১২০ ০
     * ```
     *
     * ⛔ সারিটা না থাকলে ১৫০ "দামের অমিল" হয়ে বিলটাই আটকে যেত।
     */
    public function test_off_sends_the_vat_on_received_goods_to_its_own_line_and_the_bill_still_matches(): void
    {
        app(SettingsService::class)->set('purchase.vat_recoverable', false);

        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'received_qty' => '10', 'rate' => '100']],
        );
        $receipts->confirm($receipt->fresh());

        $before = $this->balances();

        $bills = app(PurchaseBillService::class);
        $bill = $bills->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100', 'tax' => '150',
                'purchase_receipt_line_id' => $receipt->fresh()->lines->first()->id]],
        );
        $bill = $bills->confirm($bill->fresh());

        $this->assertSame(PurchaseBill::MATCH_MATCHED, $bill->fresh()->match_state,
            '⛔ অফেরতযোগ্য ভ্যাটকে দামের অমিল ধরা হয়েছে।');

        $moved = $this->delta($before, $this->balances(), ['2160', '5150', '2111', '2120']);

        $this->assertSame(['2160' => '1000.0000', '5150' => '150.0000', '2111' => '-1150.0000', '2120' => '0.0000'], $moved);

        $narration = LedgerEntry::query()
            ->where('account_id', Account::query()->where('code', '5150')->value('id'))
            ->latest('id')->value('narration');

        $this->assertSame(__('purchase::message.vat_not_recoverable', ['no' => $bill->document_no]), $narration,
            '⛔ ৫১৫০-এর সারিটা নিজের নামে নেই — মাস শেষে কেউ বুঝবে না ওটা ভ্যাট।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array{0: PurchaseBill, 1: array<string, string>} */
    private function directBill(): array
    {
        $before = $this->balances();

        $bills = app(PurchaseBillService::class);
        $bill = $bills->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100', 'tax' => '150']],
        );
        $bill = $bills->confirm($bill->fresh());

        return [$bill, $this->delta($before, $this->balances(), ['1120', '2120', '2111', '5150'])];
    }

    /** @return array<string, string> */
    private function balances(): array
    {
        return Account::query()->whereIn('code', ['1120', '2120', '2111', '2160', '5150'])->get()
            ->mapWithKeys(fn (Account $a) => [$a->code => bcadd((string) LedgerEntry::query()->where('account_id', $a->id)
                ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n'), '0', 4)])
            ->all();
    }

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     * @param  list<string>  $codes
     * @return array<string, string>
     */
    private function delta(array $before, array $after, array $codes): array
    {
        $out = [];

        foreach ($codes as $code) {
            $out[$code] = bcsub($after[$code] ?? '0', $before[$code] ?? '0', 4);
        }

        return $out;
    }

    private function layerValue(PurchaseBill $bill): string
    {
        return CostLayer::query()
            ->where('source_type', PurchaseBill::STOCK_SOURCE)
            ->where('source_id', $bill->id)
            ->get()
            ->reduce(fn (string $s, CostLayer $l) => bcadd($s, bcmul((string) $l->qty_in, (string) $l->unit_cost, 4), 4), '0.0000');
    }
}
