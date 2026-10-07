<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Contracts\CustomerSalesFilters;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Services\NoCustomerSalesFilters;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Dashboard\CustomerDashboard;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * গ্রাহক ড্যাশবোর্ডের পুরো নকশা — মালিক, ৫ অক্টোবর ২০২৬ ([[CustomerTradeCharts]])।
 *
 * ⭐ প্রতিটা দাবি আসল কাগজ খাইয়ে, আগে-পরে মেপে, খাতার নিজের অঙ্কের সাথে মিলিয়ে:
 *   ১. এ মাসের বিক্রয় বাড়ে ঠিক নতুন বিলের মোট; গড় = খাতার পাকা বিলের যোগ ÷ সংখ্যা
 *   ২. সবচেয়ে বড় ক্রেতার সারি — এ মাসের কেনা খাতার বিলের যোগ, বকেয়া গ্রাহকের খাতার জের
 *   ৩. ধরনের ডোনাট — যোগফল "সচল" কার্ড; একজনকে বন্ধ করলে তাঁর ধরন ঠিক এক কমে
 *   ৪. মেয়াদ পেরোনো বিল — বাকি বাড়ে ঠিক বিলের মোট, যা গ্রাহকের খাতার জেরের বৃদ্ধিও; আজ পড়া বিল এখনো পেরোয়নি
 *   ৫. সুইচ বন্ধে নতুন কিছুই নেই; বিক্রয় বন্ধে (চুক্তির শূন্য রূপ) টাকার সংখ্যাগুলো নেই, মিথ্যা শূন্য নয়
 */
final class TheCustomerDashboardShowsTheWholeSpecTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();

        config(['abos.dashboards_v2' => true]);
    }

    public function test_month_sales_average_and_top_buyer_follow_the_books(): void
    {
        $customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $before = $this->stats();

        $invoice = $this->sell($customer, '3', '9000');
        $after = $this->stats();

        // ── ১ · এ মাসের বিক্রয় আর গড় ───────────────────────────────────
        $this->assertSame(
            Money::round(bcadd($this->money($before[__('customer::dashboard.sales_month')]->value), (string) $invoice->total, 4)),
            Money::round($this->money($after[__('customer::dashboard.sales_month')]->value)),
            '⛔ এ মাসের বিক্রয় নতুন বিলের মোট ধরে বাড়েনি।',
        );

        $month = SalesInvoice::query()->posted()
            ->whereBetween('trx_date', [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()]);
        $count = (clone $month)->count();
        $sum = (string) (clone $month)->sum('total');

        $this->assertSame(Money::format(bcdiv($sum, (string) $count, 4)), $after[__('customer::dashboard.average_order')]->value,
            '⛔ গড় বিলের অঙ্ক খাতার পাকা বিলের যোগ ÷ সংখ্যা নয়।');
        $this->assertSame(__('customer::dashboard.sales_month_hint', ['count' => $count]), $after[__('customer::dashboard.sales_month')]->hint,
            '⛔ বিলের সংখ্যা খাতার সাথে মেলে না।');

        // ── ২ · সবচেয়ে বড় ক্রেতা, বকেয়াসহ ──────────────────────────────
        $top = $this->listing();
        $this->assertNotNull($top, '⛔ সবচেয়ে বড় ক্রেতার তালিকা নেই।');
        $first = $top->rows->first();
        $this->assertSame($customer->id, $first->id, 'প্রস্তুতিটাই ভুল — এত বড় বিলের পরেও ক্রেতা প্রথমে নেই।');
        $this->assertLessThanOrEqual(5, $top->rows->count(), '⛔ পাঁচের বেশি ক্রেতা।');

        $boughtByHim = (string) (clone $month)->where('customer_id', $customer->id)->sum('total');
        $ledger = (string) Customer::query()->withOutstandingInView()->findOrFail($customer->id)->outstanding_in_view;

        $this->assertSame(Money::format($boughtByHim), $this->cell($top, 'sold', $first), '⛔ এ মাসের কেনা খাতার বিলের যোগ নয়।');
        $this->assertSame(Money::format($ledger), $this->cell($top, 'due', $first), '⛔ বকেয়া গ্রাহকের খাতার জের নয়।');
        $this->assertSame(1, bccomp($ledger, '0', 4), 'প্রস্তুতিটাই ভুল — বাকির বিক্রি, অথচ খাতায় জের শূন্য।');
    }

    public function test_the_type_donut_adds_up_to_the_active_card_and_loses_one_when_a_customer_is_stopped(): void
    {
        $parts = $this->parts('segments');
        $active = (int) $this->stats()[__('customer::dashboard.active')]->value;

        $this->assertSame($active, (int) array_sum($parts), '⛔ ধরনের ভাগের যোগফল সচল গ্রাহকের সংখ্যা নয়।');

        // ⓘ নতুন একটা ধরন, একজন গ্রাহককে দেওয়া — ডেমোতে ধরন বসানো না-ও থাকতে পারে, তাই নিজে খাওয়ানো
        $type = PartyType::query()->create([
            'code' => 'TSEG', 'name_en' => 'Test segment', 'name_bn' => 'পরীক্ষার ধরন',
            'applies_to' => 'customer', 'is_active' => true,
        ]);
        $victim = Customer::query()->inViewedBranch()->where('is_active', true)->orderBy('id')->firstOrFail();
        $victim->forceFill(['party_type_id' => $type->id])->save();

        $typed = $this->parts('segments');
        $this->assertSame(1, $typed[$type->name()] ?? 0, '⛔ ধরন পাওয়া গ্রাহক নিজের ধরনের ভাগে গোনা হয়নি।');
        $this->assertSame(array_sum($parts), array_sum($typed), '⛔ ধরন বদলালে সচলের মোট বদলেছে।');

        $victim->forceFill(['is_active' => false])->save();

        $after = $this->parts('segments');
        $this->assertArrayNotHasKey($type->name(), $after, '⛔ বন্ধ করা গ্রাহক এখনো নিজের ধরনে গোনা।');
        $this->assertSame(array_sum($parts) - 1, array_sum($after), '⛔ বন্ধ করলে সচলের মোট এক কমেনি।');
        $this->assertSame($active - 1, (int) $this->stats()[__('customer::dashboard.active')]->value, 'প্রস্তুতিটাই ভুল — সচল কার্ড কমেনি।');
    }

    public function test_an_unpaid_bill_past_its_due_date_is_overdue_by_its_ledger_amount_and_one_due_today_is_not(): void
    {
        $overdueIds = array_column(app(CustomerSalesFilters::class)->overdueByCustomer(Carbon::today()->toDateString()), 'customer_id');
        $customer = Customer::query()->where('is_active', true)->whereNotIn('id', $overdueIds)->orderBy('id')->firstOrFail();

        $before = $this->stats();
        $ledgerBefore = $this->ledger($customer);

        // ⓘ আজ পড়া বিল — "পেরোনো" নয় (আদায়ের সূচির নিয়ম: আজকের আগে)
        $this->bill($customer, Carbon::today(), Carbon::today());
        $this->assertSame($before[__('customer::dashboard.overdue')]->value, $this->stats()[__('customer::dashboard.overdue')]->value,
            '⛔ আজ পড়া বিলকে মেয়াদ পেরোনো ধরা হয়েছে।');

        $ledgerMid = $this->ledger($customer);
        $late = $this->bill($customer, Carbon::today()->subDays(10), Carbon::today()->subDays(3));
        $after = $this->stats();
        $ledgerGrew = bcsub($this->ledger($customer), $ledgerMid, 4);

        $this->assertSame(1, bccomp(bcsub($ledgerMid, $ledgerBefore, 4), '0', 4), 'প্রস্তুতিটাই ভুল — বিল খাতায় বসেনি।');
        $this->assertSame(Money::round($late->total), Money::round($ledgerGrew), 'প্রস্তুতিটাই ভুল — খাতার জের বিলের মোট ধরে বাড়েনি।');

        $this->assertSame(
            Money::round(bcadd($this->money($before[__('customer::dashboard.overdue')]->value), $ledgerGrew, 4)),
            Money::round($this->money($after[__('customer::dashboard.overdue')]->value)),
            '⛔ মেয়াদ পেরোনো বাকি খাতার জেরের বৃদ্ধি ধরে বাড়েনি।',
        );
        $this->assertSame((int) $before[__('customer::dashboard.overdue_customers')]->value + 1,
            (int) $after[__('customer::dashboard.overdue_customers')]->value, '⛔ মেয়াদ পেরোনো গ্রাহক এক বাড়েনি।');

        $bars = $this->parts('overdue_by_customer', money: true);
        $this->assertSame(Money::round($ledgerGrew), Money::round($bars[$customer->name()] ?? '0'),
            '⛔ চার্টে এই গ্রাহকের দণ্ড তাঁর মেয়াদ পেরোনো বিলের অঙ্ক নয়।');
    }

    public function test_switch_off_or_sales_off_shows_none_of_the_new_figures(): void
    {
        $this->sell(Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail(), '1', '50');

        $newStats = ['sales_month', 'average_order', 'overdue', 'overdue_customers'];
        $newPanels = ['segments', 'overdue_by_customer'];

        $on = $this->board();
        foreach ($newStats as $key) {
            $this->assertArrayHasKey(__('customer::dashboard.'.$key), $this->statsOf($on), "প্রস্তুতিটাই ভুল — চালুতে {$key} নেই।");
        }
        $this->assertNotNull($this->panelOf($on, 'segments'), 'প্রস্তুতিটাই ভুল — চালুতে ধরনের চার্ট নেই।');
        $this->assertNotNull($this->listingOf($on), 'প্রস্তুতিটাই ভুল — চালুতে বড় ক্রেতার তালিকা নেই।');

        // ── সুইচ বন্ধ — একই মালিক, একই কাগজ ───────────────────────────────
        config(['abos.dashboards_v2' => false]);
        $off = $this->board();
        $this->assertCount(4, $off->stats, '⛔ সুইচ বন্ধে কার্ডের সংখ্যা বদলেছে।');
        foreach ($newStats as $key) {
            $this->assertArrayNotHasKey(__('customer::dashboard.'.$key), $this->statsOf($off), "⛔ সুইচ বন্ধেও {$key}।");
        }
        foreach ($newPanels as $key) {
            $this->assertNull($this->panelOf($off, $key), "⛔ সুইচ বন্ধেও {$key} চার্ট।");
        }
        $this->assertNull($this->listingOf($off), '⛔ সুইচ বন্ধেও বড় ক্রেতার তালিকা।');

        // ── বিক্রয় বন্ধ (চুক্তির শূন্য রূপ) — টাকার সংখ্যা নেই, ধরনের ডোনাট থাকে ─────────
        config(['abos.dashboards_v2' => true]);
        $this->app->bind(CustomerSalesFilters::class, NoCustomerSalesFilters::class);
        $noSales = $this->board();
        foreach ($newStats as $key) {
            $this->assertArrayNotHasKey(__('customer::dashboard.'.$key), $this->statsOf($noSales), "⛔ বিক্রয় বন্ধেও {$key} — মিথ্যা শূন্য।");
        }
        $this->assertNull($this->panelOf($noSales, 'overdue_by_customer'), '⛔ বিক্রয় বন্ধেও মেয়াদ পেরোনোর চার্ট।');
        $this->assertNull($this->listingOf($noSales), '⛔ বিক্রয় বন্ধেও বড় ক্রেতার তালিকা।');
        $this->assertNotNull($this->panelOf($noSales, 'segments'), '⛔ ধরনের ডোনাট বিক্রয়ের উপর নির্ভর করছে।');
    }

    // ── সাহায্য ─────────────────────────────────────────────────────────

    private function sell(Customer $customer, string $qty, string $rate): SalesInvoice
    {
        $result = app(DirectSaleService::class)->complete(
            ['customer_id' => $customer->id, 'warehouse_id' => $this->warehouse->id, 'own_transport' => '1'],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate, 'free_qty' => '0']],
        );

        $invoice = $result['invoice']->fresh();
        $this->assertContains($invoice->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — বিক্রিটা পাকা হয়নি।');

        return $invoice;
    }

    /** বাকির বিল, নিজের দিন আর মেয়াদ নিয়ে, পাকা */
    private function bill(Customer $customer, Carbon $on, Carbon $due): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);
        $invoice = $service->confirm($service->create(
            [
                'customer_id' => $customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => $on->toDateString(),
                'due_on' => $due->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => '2', 'rate' => '45']],
        ))->fresh();

        $this->assertContains($invoice->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — বিলটা পাকা হয়নি।');

        return $invoice;
    }

    private function ledger(Customer $customer): string
    {
        return bcadd((string) (Customer::query()->withOutstandingInView()->findOrFail($customer->id)->outstanding_in_view ?? '0'), '0', 4);
    }

    private function board(): DashboardDefinition
    {
        return CustomerDashboard::dashboard();
    }

    /** @return array<string, Stat> */
    private function stats(): array
    {
        return $this->statsOf($this->board());
    }

    /** @return array<string, Stat> */
    private function statsOf(DashboardDefinition $board): array
    {
        return collect($board->stats)->keyBy(fn (Stat $s) => $s->label)->all();
    }

    private function panelOf(DashboardDefinition $board, string $key): ?Breakdown
    {
        return collect($board->panels)
            ->first(fn ($p) => $p instanceof Breakdown && $p->label === __('customer::dashboard.'.$key));
    }

    private function listingOf(DashboardDefinition $board): ?Listing
    {
        return collect($board->listings)->first(fn (Listing $l) => $l->label === __('customer::dashboard.top_buyers'));
    }

    private function listing(): ?Listing
    {
        return $this->listingOf($this->board());
    }

    /** @return array<string, int|string> ভাগের নাম → মান */
    private function parts(string $key, bool $money = false): array
    {
        $panel = $this->panelOf($this->board(), $key);
        $this->assertNotNull($panel, "⛔ {$key} চার্টটা নেই।");

        return collect($panel->parts)->mapWithKeys(fn (array $p) => [
            $p['label'] => $money ? $this->money($p['value']) : (int) $p['value'],
        ])->all();
    }

    private function cell(Listing $listing, string $key, mixed $row): string
    {
        $column = collect($listing->columns)->firstWhere('key', $key);

        return (string) $column['render']($row);
    }

    private function money(?string $shown): string
    {
        return str_replace(',', '', (string) $shown);
    }
}
