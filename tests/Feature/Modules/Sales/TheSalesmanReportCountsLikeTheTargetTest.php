<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\DealerBindingService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use App\Modules\Sales\Reports\SalespersonReports;
use App\Modules\Sales\Services\SalesTargetService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ বিক্রয়কর্মী ধরে বিক্রি — বিক্রয় পরিকল্পনা সংস্করণ ২ §৯ (গ) ([[SalespersonReports]])।
 *
 * ⛔ "বিক্রি" ঘর লক্ষ্যের অর্জনের হুবহু সমান ([[SalesTargetService::achievedByUser()]]): দেয়াল চালু থাকলে বিলের দিনে বাঁধা জন,
 * হাতবদলের আগের বিক্রি পুরনো জনের; বাঁধনহীন ডিলার আলাদা সারিতে; ভ্যাট বাদ; খসড়া নয়; ফেরত একই নিয়মে বাদ। দেয়াল বন্ধ থাকলে বিল
 * যিনি কেটেছেন। দেয়ালের ভিতরের বিক্রয়কর্মী কেবল নিজের ডিলারের সারি দেখেন।
 */
final class TheSalesmanReportCountsLikeTheTargetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $sales;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        // ⓘ ডেমোর বিলের বাইরে একটা দিন — অঙ্কগুলো কেবল এই টেস্টের
        $this->day = Carbon::today()->subDays(120);
    }

    public function test_the_report_counts_whoever_was_bound_that_day_exactly_like_the_target(): void
    {
        $rahim = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $niloy = Customer::acrossDealers()->where('name_en', 'Niloy Store')->firstOrFail();
        $new = $this->colleague('new-sr@abos.test');

        app(DealerBindingService::class)->handover((int) $this->sales->id, (int) $new->id, [(int) $rahim->id], $this->day->toDateString());

        $old = $this->bill('SP-OLD', $rahim, $this->day->copy()->subDay(), '1150', '150');
        $this->bill('SP-NEW', $rahim, $this->day, '500', '0');
        $this->bill('SP-LOOSE', $niloy, $this->day, '900', '0');
        $this->bill('SP-DRAFT', $rahim, $this->day, '7000', '0', DocumentStatus::DRAFT);
        $this->giveBack('SR-NEW', $rahim, $this->day, '200', $old);

        $rows = $this->rows();

        $this->assertMoney('1000', $rows[$this->sales->id]['sales'] ?? null, '⛔ হাতবদলের আগের বিক্রি পুরনো জন পাননি (ভ্যাট বাদ)');
        $this->assertMoney('500', $rows[$new->id]['sales'] ?? null, '⛔ হাতবদলের পরের বিক্রি নতুন জন পাননি, বা খসড়া গোনা হল');
        $this->assertMoney('200', $rows[$new->id]['returns'] ?? null, '⛔ ফেরত ফেরতের দিনের বাঁধা জনের ঘরে যায়নি');
        $this->assertMoney('300', $rows[$new->id]['net_sales'] ?? null, 'নিট = বিক্রি − ফেরত');
        $this->assertMoney('900', $rows[0]['sales'] ?? null, '⛔ বাঁধনহীন ডিলারের বিক্রি আলাদা সারিতে নেই');
        $this->assertSame((string) __('sales::salesperson_report.nobody'), $rows[0]['salesperson']);
        $this->assertArrayNotHasKey((int) $this->owner->id, $rows, '⛔ বিল যিনি কেটেছেন তিনি বিক্রি পেলেন');

        // ⭐ লক্ষ্যের স্কোরবোর্ডের সাথে হুবহু — এক নিয়ম, দুই পর্দা
        $achieved = app(SalesTargetService::class)->achievedByUser($this->day->copy()->subDay(), $this->day);

        foreach ($achieved as $user => $amount) {
            $this->assertMoney((string) $amount, $rows[$user]['sales'] ?? null, "⛔ বিক্রয়কর্মী {$user}: রিপোর্ট আর লক্ষ্য আলাদা অঙ্ক বলে");
        }

        $this->assertSame(count($achieved), count(array_filter(array_keys($rows))), '⛔ লক্ষ্যে নেই এমন কারও সারি রিপোর্টে');
    }

    public function test_with_the_wall_off_the_sale_belongs_to_whoever_wrote_the_bill(): void
    {
        app(SettingsService::class)->set(DealerScope::SWITCH, false);
        app(DealerScope::class)->forget();

        $rahim = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $bill = $this->bill('SP-OFF', $rahim, $this->day, '800', '0');
        $this->giveBack('SR-OFF', $rahim, $this->day, '100', $bill, by: $this->sales);

        $rows = $this->rows();

        $this->assertMoney('800', $rows[$this->owner->id]['sales'] ?? null, '⛔ দেয়াল বন্ধে বিল যিনি কেটেছেন তিনি বিক্রি পাননি');
        $this->assertMoney('100', $rows[$this->owner->id]['returns'] ?? null, '⛔ দেয়াল বন্ধে ফেরত আসল বিলের লেখকের ঘরে যায়নি');
        $this->assertArrayNotHasKey((int) $this->sales->id, $rows, '⛔ ফেরত যিনি বসিয়েছেন তিনি ফেরত পেলেন');
        $this->assertMoney(
            (string) (app(SalesTargetService::class)->achievedByUser($this->day, $this->day)[$this->owner->id] ?? '0'),
            $rows[$this->owner->id]['sales'] ?? null,
            '⛔ দেয়াল বন্ধে রিপোর্ট আর লক্ষ্য আলাদা',
        );
    }

    public function test_the_page_opens_and_a_walled_salesman_sees_only_his_own_dealers(): void
    {
        $rahim = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $niloy = Customer::acrossDealers()->where('name_en', 'Niloy Store')->firstOrFail();
        $this->bill('SP-MINE', $rahim, $this->day, '400', '0');
        $this->bill('SP-THEIRS', $niloy, $this->day, '900', '0');

        $range = ['slug' => 'by-salesperson', 'from' => $this->day->toDateString(), 'to' => $this->day->toDateString()];
        $this->get(route('sales.report.show', $range))->assertOk()->assertSee((string) __('sales::salesperson_report.nobody'));

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->sales->fresh());
        $this->assertTrue(app(DealerScope::class)->walled($this->sales->fresh()), 'দৃশ্যটাই বানানো যায়নি — ডেমোর বিক্রয়কর্মী দেয়ালে নেই');

        $rows = $this->rows();
        $this->assertArrayNotHasKey(0, $rows, '⛔ দেয়ালের ভিতরের বিক্রয়কর্মী বাঁধনহীন ডিলারের বিক্রি দেখলেন');
        $this->assertMoney('400', $rows[$this->sales->id]['sales'] ?? null, 'নিজের ডিলারের বিক্রি');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> বিক্রয়কর্মী (বাঁধনহীন = 0) → সারি */
    private function rows(): array
    {
        $range = ['from' => $this->day->copy()->subDay()->toDateString(), 'to' => $this->day->toDateString()];

        return collect(app(ReportEngine::class)->run(SalespersonReports::KEY, $range)->rows)
            ->map(fn ($r) => (array) $r)
            ->keyBy(fn (array $r) => (int) ($r['seller_id'] ?? 0))
            ->all();
    }

    private function colleague(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->assignRole('salesman'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DealerScope::class)->forget();

        return $user;
    }

    private function bill(string $no, Customer $customer, Carbon $on, string $amount, string $tax, string $status = DocumentStatus::CONFIRMED): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->firstOrFail()->id,
            'document_no' => $no,
            'customer_id' => $customer->id,
            'trx_date' => $on->toDateString(),
            'subtotal' => $amount,
            'tax' => $tax,
            'total' => $amount,
            'status' => $status,
            'created_by' => $this->owner->id,
        ]);

        (new SalesInvoiceLine)->forceFill([
            'line_no' => 1, 'sales_invoice_id' => $invoice->id, 'product_id' => Product::query()->firstOrFail()->id,
            'qty' => '1', 'rate' => $amount, 'amount' => $amount, 'tax' => $tax,
        ])->save();

        return $invoice;
    }

    private function giveBack(string $no, Customer $customer, Carbon $on, string $amount, SalesInvoice $invoice, ?User $by = null): void
    {
        $return = (new SalesReturn)->forceFill([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->firstOrFail()->id,
            'document_no' => $no,
            'customer_id' => $customer->id,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'sales_invoice_id' => $invoice->id,
            'trx_date' => $on->toDateString(),
            'subtotal' => $amount,
            'total' => $amount,
            'status' => DocumentStatus::CONFIRMED,
            'created_by' => ($by ?? $this->owner)->id,
        ]);
        $return->save();

        (new SalesReturnLine)->forceFill([
            'company_id' => $this->company->id, 'line_no' => 1, 'sales_return_id' => $return->id,
            'product_id' => Product::query()->firstOrFail()->id, 'qty' => '1', 'rate' => $amount, 'amount' => $amount, 'tax' => '0',
        ])->save();
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 2), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }
}
