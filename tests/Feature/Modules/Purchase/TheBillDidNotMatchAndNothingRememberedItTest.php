<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিলটা মিলল না, আর কেউ সেটা মনে রাখল না।
 *
 * ── ⭐ মালিকের স্পেক, ২৩–২৪ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"3-Way Matching mandatory architecture হবে"* — আদেশ + চালান + বিল
 * মিলিয়ে ফল হবে `MATCHED` · `PARTIAL_MATCH` · `MISMATCH` · `EXCEPTION`।
 *
 * ── ⛔ যে ফাঁকটা ছিল ─────────────────────────────────────────────────
 * তিনটা সুইচ আগে থেকেই কাজ করত (আদেশ ছাড়া গ্রহণ, আদেশের বেশি গ্রহণ,
 * দাম না মিললে আটকানো)। ⚠️ কিন্তু **ফলটা কোথাও থাকত না**:
 *
 *   · সুইচ চালু → বিলটা আটকে যেত, অর্থাৎ ঘটনাটা লেখাই হত না
 *   · সুইচ বন্ধ → বিলটা চুপচাপ পাশ, পার্থক্য কেবল মূল্য-পার্থক্য খাতে
 *
 * ⓘ দুই অবস্থাতেই *"কোন বিলগুলো মেলেনি"* প্রশ্নের উত্তর বের করতে
 * হিসাবের খাত ধরে উল্টোদিকে হাঁটতে হত।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. মিলে গেলে ফলটা `matched` হয়ে বিলের গায়ে বসে
 *   ২. ফাঁকের টাকাটাও বসে — "মেলেনি" একাই কিছু বলে না
 *   ৩. ব্যতিক্রমের তালিকায় মিলে যাওয়া বিল **ওঠে না**
 *   ৪. তালিকার দরজা বন্ধ, যার চাবি নেই তার কাছে
 */
final class TheBillDidNotMatchAndNothingRememberedItTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    // ── ১ ও ২ · ফলটা বিলের গায়ে বসে ──────────────────────────────────

    public function test_a_bill_that_lines_up_is_written_down_as_matched(): void
    {
        $bill = $this->billFromAReceipt();

        $this->assertNotNull($bill->match_state,
            'বিলটা নিশ্চিত হয়েছে, তবু মিলকরণের ফল খালি — অর্থাৎ তিন-মুখী '
            .'মিলকরণটা হয়েছে কি না তা কাগজ দেখে বলার কোনো উপায় নেই।');

        $this->assertSame(PurchaseBill::MATCH_MATCHED, $bill->match_state);

        $this->assertSame(0, bccomp((string) $bill->match_difference, '0', 4),
            'মিলে যাওয়া বিলেও ফাঁকের টাকা বসেছে।');
    }

    public function test_the_gap_is_kept_as_money_not_just_a_word(): void
    {
        /*
         * ⛔ *"মেলেনি"* কথাটা একাই কিছু বলে না। ⚠️ দুই টাকার অমিল আর
         * দুই লাখ টাকার অমিল এক জিনিস নয়, আর কোনটা আগে দেখতে হবে
         * সেটা কেবল সংখ্যাটাই বলে।
         */
        $bill = $this->billFromAReceipt();

        $this->assertNotNull($bill->match_difference,
            'ফাঁকের ঘরটা খালি — তাহলে ব্যতিক্রমের তালিকা কোন ক্রমে সাজাবে?');
    }

    // ── ৩ · তালিকায় কেবল যেগুলো দেখার দরকার ──────────────────────────

    public function test_a_matched_bill_never_shows_up_in_the_exception_list(): void
    {
        $bill = $this->billFromAReceipt();

        $html = (string) $this->actingAs($this->owner)
            ->get(route('purchase.report.show', ['slug' => 'match-exceptions']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($bill->document_no, $html,
            'মিলে যাওয়া বিলটাও ব্যতিক্রমের তালিকায় এসেছে — তাহলে এটা গোটা '
            .'বিলের তালিকা হয়ে যেত, আর যে তিনটা সত্যিই দেখার দরকার সেগুলো '
            .'একশোটার ভিড়ে হারাত।');
    }

    public function test_the_exception_list_opens_for_someone_with_the_report_key(): void
    {
        /*
         * ⚠️ উপরের পাহারাটা উল্টো দিকেও সবুজ থাকত: পাতাটা যদি কখনো না
         * খুলত, তাহলে "বিলের নম্বর নেই" দাবিটা এমনিতেই সত্যি হত।
         * ⓘ তাই আলাদা করে দেখা হয় পাতাটা সত্যিই খোলে।
         */
        $this->actingAs($this->owner)
            ->get(route('purchase.report.show', ['slug' => 'match-exceptions']))
            ->assertOk()
            ->assertSee(__('purchase::menu.match_exceptions'));
    }

    // ── ৪ · দরজাটা বন্ধ ───────────────────────────────────────────────

    public function test_the_exception_list_is_closed_without_the_report_key(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($salesman)
            ->get(route('purchase.report.show', ['slug' => 'match-exceptions']))
            ->assertForbidden();
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * একটা চালান নিশ্চিত করলে বিলটা নিজেই লেখা হয় — আর সেটাই মাপা হয়।
     */
    private function billFromAReceipt(): PurchaseBill
    {
        /*
         * ⓘ দামের পার্থক্য আটকানোর সুইচটা এখানে প্রাসঙ্গিক নয় (ফাঁক
         * শূন্য), তবু স্পষ্ট করে বসানো হয় — ⚠️ ডেমোর সেটিং একদিন
         * বদলালে পরীক্ষাটা অন্য পথ মাপত, আর কেউ টের পেত না।
         */
        app(SettingsService::class)->set('purchase.block_price_mismatch', true);

        $receipt = $this->receive();

        $this->actingAs($this->owner)
            ->post(route('purchase.receipt.confirm', $receipt))
            ->assertSessionHasNoErrors();

        return PurchaseBill::query()
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->whereHas('lines.receiptLine', fn ($q) => $q->where('purchase_receipt_id', $receipt->id))
            ->latest('id')
            ->firstOrFail();
    }

    private function receive(): PurchaseReceipt
    {
        return app(PurchaseReceiptService::class)->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'received_qty' => '10',
                'rate' => '172.54',
            ]],
        );
    }
}
