<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টারের পর্দার ভাগ করা টাকা দরজা পেরোত না — ২০ নভেম্বর ২০২৬।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * পর্দা `payments[i][payment_method_id|amount|reference]` পাঠাত, কিন্তু [[PosController::checkout()]]-এর যাচাইয়ের তালিকায় ঘরগুলো
 * ছিল না — `validate()` কেবল তালিকার ঘর ফেরায়, তাই সারিগুলো সেবায় পৌঁছাত না। ২,০০০ বিকাশ + ৩০০ নগদের বিক্রিও পুরো ২,৩০০ নগদে
 * টিলে বসত: দিনশেষে ড্রয়ারে মিথ্যা ঘাটতি। ⓘ সেবার দাবিগুলো ([[TwoThousandInBkashAndTheRestInCashTest]]) সবুজ ছিল, কারণ ওরা
 * সেবা সরাসরি ডাকত — দরজা দিয়ে নয়। ⭐ তাই এই দাবিগুলো HTTP দিয়ে, পর্দার হুবহু ঘরের নামে।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * সারিগুলো যাচাই হয়ে সেবায় যায়; প্রতিটা ভাগ নিজের খাতে নিজের রসিদ। যোগফল "যা দিলেন"-এর সাথে না মিললে দরজাতেই থামে, কিছুই লেখা
 * হয় না। কেবল নগদের বিক্রি আগের মতোই।
 */
final class TheCounterScreenSendsItsSplitTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Product $product;

    private Account $bkash;

    private PaymentMethod $bkashMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        app(SettingsService::class)->set('sales.screen_pos', true);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();

        $this->bkash = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1105-BKSCR',
            'name_en' => 'bKash Merchant',
            'name_bn' => 'বিকাশ মার্চেন্ট',
            'parent_id' => StandardChart::find(StandardChart::MOBILE_MONEY)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::MFS,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);

        $this->bkashMethod = PaymentMethod::query()->create([
            'company_id' => $this->company->id,
            'code' => 'BKSCR', 'name_en' => 'bKash', 'name_bn' => 'বিকাশ', 'kind' => 'mfs',
            'account_id' => $this->bkash->id,
            'needs_reference' => true, 'is_active' => true,
        ]);
    }

    /** ⭐ ২,৩০০-এর বিল: ২,০০০ বিকাশে, ৩০০ নগদে — পর্দার ঘরের নামে, দরজা দিয়ে */
    public function test_a_cash_and_bkash_split_lands_each_part_in_its_own_account(): void
    {
        $tillBefore = $this->till()->fresh()->balanceOn();

        $this->checkout('2300', [
            'paid' => '2300',
            'payments' => [
                ['payment_method_id' => (string) $this->bkashMethod->id, 'amount' => '2000', 'reference' => 'TRX-SCR-1'],
                // ⓘ পর্দায় পদ্ধতি না বাছা = নগদ (খালি ঘর)
                ['payment_method_id' => '', 'amount' => '300', 'reference' => ''],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(0, bccomp($this->bkash->fresh()->balanceOn(), '2000', 4), '⛔ বিকাশের ২,০০০ বিকাশের খাতে পৌঁছায়নি — সারিগুলো দরজায় হারিয়েছে?');
        $this->assertSame(0, bccomp(bcsub($this->till()->fresh()->balanceOn(), $tillBefore, 4), '300', 4), '⛔ টিলে ৩০০ নয় — পুরো টাকা নগদে বসেছে?');

        $receipts = $this->counterReceipts();
        $this->assertCount(2, $receipts, '⛔ দুই ভাগে দুইটা রসিদ নয়।');
        $this->assertSame('TRX-SCR-1', $receipts->firstWhere('instrument_no', 'TRX-SCR-1')?->instrument_no, '⛔ বিকাশের TrxID রসিদে নেই।');
        $this->assertSame(0, bccomp($this->lastInvoice()->dueAmount(), '0', 4));
    }

    /** ⓘ বেশি দিলে ফেরত নগদের ভাগ থেকে — বিকাশে পুরো ২,০০০ থাকে */
    public function test_the_change_of_a_split_comes_out_of_the_cash_part(): void
    {
        $tillBefore = $this->till()->fresh()->balanceOn();

        $this->checkout('2300', [
            'paid' => '2500',
            'payments' => [
                ['payment_method_id' => (string) $this->bkashMethod->id, 'amount' => '2000', 'reference' => 'TRX-SCR-2'],
                ['payment_method_id' => '', 'amount' => '500', 'reference' => ''],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp($this->bkash->fresh()->balanceOn(), '2000', 4));
        $this->assertSame(0, bccomp(bcsub($this->till()->fresh()->balanceOn(), $tillBefore, 4), '300', 4), '⛔ ড্রয়ারে ৩০০ থাকার কথা (৫০০ নেওয়া, ২০০ ফেরত)।');
    }

    /** ⛔ ভাগের যোগফল (২,১০০) ≠ যা দিলেন (২,৩০০) — দরজাতেই থামে, বিল বা রসিদ কিছুই নয় */
    public function test_a_split_that_does_not_add_up_to_the_amount_paid_is_refused(): void
    {
        $invoices = SalesInvoice::query()->count();
        $receipts = Voucher::query()->where('type', Voucher::RECEIPT)->count();

        $response = $this->checkout('2300', [
            'paid' => '2300',
            'payments' => [
                ['payment_method_id' => (string) $this->bkashMethod->id, 'amount' => '2000', 'reference' => 'TRX-SCR-3'],
                ['payment_method_id' => '', 'amount' => '100', 'reference' => ''],
            ],
        ]);

        $response->assertSessionHasErrors('payments');
        $message = (string) session('errors')->first('payments');
        $this->assertStringContainsString('2,100', $message, '⛔ বার্তায় ভাগের যোগফল নেই: '.$message);
        $this->assertStringContainsString('2,300', $message, '⛔ বার্তায় যা দিলেন তা নেই: '.$message);

        $this->assertSame($invoices, SalesInvoice::query()->count(), '⛔ না-মেলা ভাগে বিল বসে গেছে।');
        $this->assertSame($receipts, Voucher::query()->where('type', Voucher::RECEIPT)->count(), '⛔ না-মেলা ভাগে রসিদ বসে গেছে।');
        $this->assertSame(0, bccomp($this->bkash->fresh()->balanceOn(), '0', 4));
    }

    /** ⓘ বার্তা দুই ভাষাতেই আছে, কাঁচা চাবি নয় */
    public function test_the_mismatch_message_exists_in_both_languages(): void
    {
        foreach (['bn', 'en'] as $locale) {
            $text = __('sales::validation.split_does_not_add_up', ['sum' => '1', 'paid' => '2'], $locale);
            $this->assertNotSame('sales::validation.split_does_not_add_up', $text, "⛔ {$locale}: কাঁচা চাবি");
        }
    }

    /** ⭐ কেবল নগদ — সারি নেই, আগের মতোই: পুরো টাকা টিলে, একটা রসিদ */
    public function test_a_cash_only_sale_is_unchanged(): void
    {
        $tillBefore = $this->till()->fresh()->balanceOn();

        $this->checkout('500', ['paid' => '500'])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(0, bccomp(bcsub($this->till()->fresh()->balanceOn(), $tillBefore, 4), '500', 4));
        $this->assertSame(0, bccomp($this->bkash->fresh()->balanceOn(), '0', 4));

        $receipt = $this->counterReceipts()->sole();
        $this->assertSame('cash', $receipt->instrument);
        $this->assertSame(0, bccomp((string) $receipt->amount, '500', 4));
        $this->assertSame(0, bccomp($this->lastInvoice()->dueAmount(), '0', 4));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $money */
    private function checkout(string $rate, array $money): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('sales.pos.checkout'), [
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '1', 'rate' => $rate]],
            ...$money,
        ]);
    }

    private function till(): Account
    {
        return app(CashTillService::class)->ensurePrimaryTill()->account;
    }

    private function lastInvoice(): SalesInvoice
    {
        return SalesInvoice::query()->latest('id')->firstOrFail();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Voucher> */
    private function counterReceipts()
    {
        return Voucher::query()->where('type', Voucher::RECEIPT)->where('origin', Voucher::ORIGIN_COUNTER)->orderBy('id')->get();
    }
}
