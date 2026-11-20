<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HiresTheLabour;
use Tests\TestCase;

/**
 * ⭐ আনলোডিংয়ের চার পথ, প্রত্যেকটা নিজের খাতায় — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে"; ২০ নভেম্বর ২০২৬ দাবি)।
 *
 * ⓘ মালিকের কারণ: মজুরি মালের দামের অংশ (IAS 2) — নগদে দিলে টিল থেকে, ঠিকাদারের কাছে বাকি হলে তাঁর নামে দেনা (২১১৭),
 *   সরবরাহকারী দিলে বা লাগেনি হলে আমাদের খরচ নেই।
 *
 * দাবি (মাল গ্রহণ পাকা হলে, কাগজের নিজের দাখিলায়):
 *  - নগদ: Dr মজুদ ১১২০ মাল + মজুরি / Cr বাছা টিল মজুরিটুকু।
 *  - বাকি: Cr ২১১৭ মজুরিটুকু, পক্ষ = ঠিকাদার (সরবরাহকারী নয়)।
 *  - সরবরাহকারী / লাগেনি: মজুরির কোনো সারি নেই, মজুদ = কেবল মালের দাম।
 *  - সংরক্ষণেই থামে: কারণ ছাড়া "লাগেনি", টিল ছাড়া নগদ, ঠিকাদার ছাড়া বাকি — কিছুই সংরক্ষণ হয় না।
 */
final class EachWayOfPayingTheUnloadingBooksItsOwnLinesTest extends TestCase
{
    use HiresTheLabour;
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
        config(['abos.unloading_required' => true]);

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();
    }

    public function test_cash_debits_the_stock_and_credits_the_chosen_till(): void
    {
        $till = $this->labourTill();
        $before = $till->balanceOn();

        $receipt = $this->confirmed(['unload_mode' => 'cash', 'unload_amount' => '75', 'unload_account_id' => $till->id]);

        $this->assertSame('75.0000', $this->booked(PurchaseReceipt::drillSourceType(), $receipt->id, (int) $till->id, 'credit'),
            '⛔ নগদের মজুরি বাছা টিল থেকে কাটেনি।');
        $this->assertSame('1075.0000', $this->booked(PurchaseReceipt::drillSourceType(), $receipt->id, StandardChart::INVENTORY, 'debit'),
            '⛔ মজুদে মাল (১০০০) + মজুরি (৭৫) বসেনি।');
        $this->assertSame('0.0000', $this->booked(PurchaseReceipt::drillSourceType(), $receipt->id, StandardChart::LABOUR_PAYABLE, 'credit'),
            '⛔ নগদে দেওয়া মজুরি দেনাতেও বসল — দুইবার।');
        $this->assertSame(0, bccomp(bcsub($before, $till->fresh()->balanceOn(), 4), '75', 4), '⛔ টিলের জের ঠিক ৭৫ কমেনি।');
        $this->assertSame(auth()->id(), (int) $receipt->unload_payer_id, 'কে দিলেন লেখা নেই।');
    }

    public function test_due_is_owed_to_the_contractor_on_labour_payable(): void
    {
        $contractor = $this->labourContractor();

        $receipt = $this->confirmed(['unload_mode' => 'due', 'unload_amount' => '120', 'unload_contractor_id' => $contractor->id]);

        $due = LedgerEntry::query()
            ->where('source_type', PurchaseReceipt::drillSourceType())->where('source_id', $receipt->id)
            ->where('account_id', StandardChart::find(StandardChart::LABOUR_PAYABLE)->id)
            ->get();

        $this->assertCount(1, $due, '⛔ বাকি মজুরি প্রদেয় হাম্মালিতে (২১১৭) একটা সারিতে বসেনি।');
        $this->assertSame([0, 'supplier', $contractor->id], [bccomp((string) $due->first()->credit, '120', 4), $due->first()->party_type, (int) $due->first()->party_id],
            '⛔ দেনা ঠিকাদারের নামে ১২০ নয় (সরবরাহকারীর নামে বসল?)।');
        $this->assertSame('1120.0000', $this->booked(PurchaseReceipt::drillSourceType(), $receipt->id, StandardChart::INVENTORY, 'debit'),
            '⛔ মজুদে মাল + বাকি মজুরি বসেনি।');
    }

    public function test_paid_by_the_supplier_or_not_needed_books_no_labour_and_the_stock_is_the_goods_only(): void
    {
        foreach ([['unload_mode' => 'supplier'], ['unload_mode' => 'none', 'unload_reason' => 'Our own staff unloaded']] as $choice) {
            $receipt = $this->confirmed($choice);
            $source = PurchaseReceipt::drillSourceType();

            $this->assertSame('1000.0000', $this->booked($source, $receipt->id, StandardChart::INVENTORY, 'debit'),
                "⛔ {$choice['unload_mode']}: মজুদে মালের দামের বেশি বসল।");
            $this->assertSame('0.0000', $this->booked($source, $receipt->id, StandardChart::LABOUR_PAYABLE, 'credit'),
                "⛔ {$choice['unload_mode']}: আমাদের খরচ নেই, তবু ২১১৭-এ দেনা।");
            $this->assertSame(2, LedgerEntry::query()->where('source_type', $source)->where('source_id', $receipt->id)->count(),
                "⛔ {$choice['unload_mode']}: মজুদ / অপেক্ষমাণ দায় ছাড়া আর সারি বসল।");
            $this->assertSame(0, bccomp($this->layerValue(PurchaseReceipt::STOCK_SOURCE, $receipt->id), '1000', 4),
                "⛔ {$choice['unload_mode']}: স্তরের দাম মালের দাম নয়।");
            $this->assertSame(0, bccomp((string) $receipt->lines->first()->unload_cost, '0', 4),
                "⛔ {$choice['unload_mode']}: সারিতে মজুরির ভাগ বসল।");
        }

        $this->assertSame('Our own staff unloaded', PurchaseReceipt::query()->latest('id')->value('unload_reason'), '"লাগেনি"-র কারণ লেখা থাকেনি।');
    }

    public function test_an_incomplete_choice_is_refused_when_it_is_saved(): void
    {
        $count = PurchaseReceipt::query()->count();

        $this->refusedOn(fn () => $this->draft(['unload_mode' => 'none', 'unload_reason' => 'no']), 'unload_reason', __('supplier::labour.needs_reason'));
        $this->refusedOn(fn () => $this->draft(['unload_mode' => 'cash', 'unload_amount' => '50']), 'unload_account_id', __('supplier::labour.needs_till'));
        $this->refusedOn(fn () => $this->draft(['unload_mode' => 'due', 'unload_amount' => '50']), 'unload_contractor_id', __('supplier::labour.needs_contractor'));
        $this->refusedOn(fn () => $this->draft(['unload_mode' => 'lorry']), 'unload_mode', __('supplier::labour.pick_one'));

        $this->assertSame($count, PurchaseReceipt::query()->count(), '⛔ থেমে যাওয়া বাছাইয়ের মাল গ্রহণ সংরক্ষণ হয়ে গেল।');
    }

    public function test_a_blank_amount_falls_back_to_cartons_times_the_rate(): void
    {
        $contractor = $this->labourContractor();
        $receipt = $this->draft(['unload_mode' => 'due', 'unload_amount' => '', 'unload_contractor_id' => $contractor->id]);

        // ⓘ প্রস্তাব = কার্টন × সাধারণ দর (০.৪০) — কার্টন পণ্যের কেনার প্যাক ধরে
        $expected = \App\Core\Support\Money::round(bcmul((string) $receipt->unload_cartons, '0.40', 8), 2);

        $this->assertSame(0, bccomp((string) $receipt->unload_amount, $expected, 4),
            "⛔ খালি অঙ্কে প্রস্তাবিত অঙ্ক বসেনি ({$receipt->unload_amount} ≠ {$expected})।");
    }

    // ── যন্ত্রপাতি ──

    /** @param  array<string, mixed>  $unloading */
    private function draft(array $unloading): PurchaseReceipt
    {
        return app(PurchaseReceiptService::class)->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()] + $unloading,
            [['product_id' => $this->product->id, 'received_qty' => '10', 'rate' => '100']],
        );
    }

    /** @param  array<string, mixed>  $unloading */
    private function confirmed(array $unloading): PurchaseReceipt
    {
        $receipts = app(PurchaseReceiptService::class);

        return $receipts->confirm($this->draft($unloading)->fresh());
    }
}
