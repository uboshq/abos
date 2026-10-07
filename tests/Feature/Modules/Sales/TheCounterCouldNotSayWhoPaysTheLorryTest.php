<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * কাউন্টার বলতে পারত না গাড়িভাড়া কে দেবে — মালিক, ৪ অক্টোবর ২০২৬ (সংস্করণ ২; "গাড়ি ও ভাড়া", তিন মোডেই)।
 *
 * ⭐ আন্তর্জাতিক freight terms, হাতে গোনা — ২ বিস্কুট × ১০ = ২০, ভাড়া ১০০:
 *   আমরা (Prepaid)                 পরিবহন খরচ Dr ১০০ · বিল ২০ · ভাড়ার আয় নেই
 *   আমরা, বিলে যোগ (Prepaid & Add)   পরিবহন খরচ Dr ১০০ · বিল ১২০ = মাল ২০ + ভাড়া ১০০ · বিক্রয় Cr ২০ · ভাড়ার আয় Cr ১০০
 *   ক্রেতা (Collect)                খাতায় কিছু নয় · বিল ২০
 *   নেই                            খাতায় কিছু নয় · বিল ২০
 * ⓘ খরচ আর আয় দুইটাই পুরো অঙ্কে (IFRS ১৫, আমরা মূল পক্ষ) — একটা আরেকটাকে কাটে না।
 */
final class TheCounterCouldNotSayWhoPaysTheLorryTest extends TestCase
{
    use RefreshDatabase;

    private Product $biscuit;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '1000000'])->save();
    }

    public function test_prepaid_is_our_expense_and_not_on_the_bill(): void
    {
        [$challan, $invoice] = $this->sell('us');

        $this->assertSame('100.0000', $this->booked(StandardChart::VEHICLE_HIRE, 'debit', DeliveryChallan::STOCK_SOURCE, $challan->id));
        $this->assertSame('20.0000', (string) $invoice->total);
        $this->assertSame('0.0000', $this->booked(StandardChart::FREIGHT_INCOME, 'credit', SalesInvoice::drillSourceType(), $invoice->id));
    }

    /** ⭐ Prepaid & Add — খরচ আমাদের, আর বিলে আলাদা লাইনে আদায়; বিক্রয় কেবল মালের দাম */
    public function test_prepaid_and_add_puts_the_fare_on_the_bill_and_into_freight_income(): void
    {
        [$challan, $invoice] = $this->sell('us_add_to_bill');

        $this->assertSame('100.0000', $this->booked(StandardChart::VEHICLE_HIRE, 'debit', DeliveryChallan::STOCK_SOURCE, $challan->id),
            '⛔ আমরা চালককে দিয়েছি — খরচ খাতায় নেই।');
        $this->assertSame('100.0000', (string) $invoice->freight_charge);
        $this->assertSame('120.0000', (string) $invoice->total, '⛔ বিলের মোটে ভাড়া নেই।');
        $this->assertSame('120.0000', $this->booked(StandardChart::RECEIVABLE, 'debit', SalesInvoice::drillSourceType(), $invoice->id));
        $this->assertSame('20.0000', $this->booked(StandardChart::SALES, 'credit', SalesInvoice::drillSourceType(), $invoice->id),
            '⛔ ভাড়া বিক্রয়ে মিশেছে।');
        $this->assertSame('100.0000', $this->booked(StandardChart::FREIGHT_INCOME, 'credit', SalesInvoice::drillSourceType(), $invoice->id),
            '⛔ ভাড়ার আয় নিজের খাতে নেই।');
        $this->assertSame(0, bccomp('120', $invoice->dueAmount(), 4), '⛔ ক্রেতার বকেয়ায় ভাড়া নেই।');
    }

    /** Collect আর "নেই" — ক্রেতা চালককে দেন বা ভাড়াই নেই: আমাদের খাতায় কিছু নয়, বিলে কেবল মাল */
    public function test_collect_and_none_touch_no_books(): void
    {
        foreach (['customer', 'none'] as $who) {
            // ⓘ একই কার্ট দুইবার, জেনেশুনে — "আবার করুন" টিক ([[DirectSaleService::refuseARepeatBill()]])
            [$challan, $invoice] = $this->sell($who, ['confirm_duplicate' => '1']);

            $this->assertFalse(DB::table('ledger_entries')->where('source_type', DeliveryChallan::STOCK_SOURCE)->where('source_id', $challan->id)->exists(),
                "⛔ {$who}: ভাড়া আমাদের খরচে বসেছে।");
            $this->assertSame('20.0000', (string) $invoice->total, "⛔ {$who}: বিলে ভাড়া এসেছে।");
            $this->assertSame($who, $challan->fare_paid_by);
        }
    }

    /** ঘরগুলো চালানে থাকে, আর "পরে পাঠানো হবে" ঠিকানা-তারিখ ছাড়া নয়; "গাড়ি নেই" নিজেই পরিবহনের উত্তর */
    public function test_the_choices_are_kept_and_send_later_needs_an_address_and_a_date(): void
    {
        // ⓘ গাড়ির নম্বর, চালক, ভাড়া কিছুই নেই — পরিবহনের উত্তর কেবল "গাড়ি নেই" ([[TransportRule::named()]])
        [$challan] = $this->sell('none', [
            'delivery_mode' => 'take_now', 'vehicle_owner' => 'none',
            'vehicle_no' => '', 'driver_name' => '', 'driver_phone' => '', 'transport_cost' => '',
        ]);

        $this->assertSame('take_now', $challan->delivery_mode);
        $this->assertSame('none', $challan->vehicle_owner);

        // ⭐ "গাড়ি নেই" নিজেই উত্তর — চালান ছাপার আগের পাহারা আর থামায় না ([[RequireTransportBeforePrint]])
        $this->get(route('sales.print.challan', $challan))->assertSessionHasNoErrors()->assertOk();

        $this->post(route('sales.direct.store'), $this->form('us', ['delivery_mode' => 'send_later']))
            ->assertSessionHasErrors(['ship_to', 'ship_date']);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array{0: DeliveryChallan, 1: SalesInvoice} */
    private function sell(string $farePaidBy, array $extra = []): array
    {
        $this->post(route('sales.direct.store'), $this->form($farePaidBy, $extra))->assertSessionHasNoErrors();

        return [
            DeliveryChallan::query()->latest('id')->firstOrFail(),
            SalesInvoice::query()->latest('id')->firstOrFail(),
        ];
    }

    /** @return array<string, mixed> */
    private function form(string $farePaidBy, array $extra = []): array
    {
        return [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'vehicle_owner' => 'hired',
            'vehicle_no' => 'ঢাকা মেট্রো ন ১২-৩৪৫৬',
            'driver_name' => 'রহিম',
            'driver_phone' => '01711000000',
            'transport_cost' => '100',
            'fare_paid_by' => $farePaidBy,
            'lines' => [['product_id' => $this->biscuit->id, 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
            ...$extra,
        ];
    }

    private function booked(string $code, string $side, string $sourceType, int $sourceId): string
    {
        return bcadd((string) DB::table('ledger_entries as le')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('a.code', $code)
            ->where('le.source_type', $sourceType)
            ->where('le.source_id', $sourceId)
            ->sum('le.'.$side), '0', 4);
    }
}
