<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Dashboard\DashboardRegistry;
use App\Core\Dashboard\Widget;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Hr\Dashboard\HrDashboard;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * হোম পর্দার কোণের সংখ্যাগুলোও বাছা শাখা মানে — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * [[TheDashboardShowsTheBranchYouPickedTest]] টাকা, বিক্রি, প্রাপ্য আর মাথার সারি মাপে। এটা
 * বাকিগুলো: কর্মী আর আজকের হাজিরা (HR), সরবরাহকারীর পাওনা, এই মাসের মার্জিন, আর বিক্রির
 * সাত দিনের রেখা (sparkline) — একই মালিক, একই ডেটা, কেবল হেডারের বাছাই বদলায়।
 *
 *   শাখা ক  → কেবল ক
 *   শাখা খ  → কেবল খ
 *   সব শাখা → ক + খ + শাখাহীন
 */
final class TheDashboardCornersFollowTheBranchTooTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $a;

    private Branch $b;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();

        CompanyContext::set($this->company->id, $this->a->id);
        $this->actingAs($this->owner);
    }

    public function test_every_corner_figure_follows_the_picked_branch_and_all_adds_up(): void
    {
        $before = [];

        foreach (['a', 'b', 'all'] as $pick) {
            $this->pick($pick);
            $before[$pick] = $this->figures();
        }

        $this->putPeopleOwingsAndSalesInEachBranch();

        $expected = [
            'a' => ['employees' => '1', 'on_payroll' => '1', 'present' => '1', 'supplier_owed' => '500', 'margin_sold' => '700', 'spark_today' => '700'],
            'b' => ['employees' => '2', 'on_payroll' => '2', 'present' => '1', 'supplier_owed' => '800', 'margin_sold' => '300', 'spark_today' => '300'],
            'all' => ['employees' => '4', 'on_payroll' => '4', 'present' => '3', 'supplier_owed' => '1350', 'margin_sold' => '1050', 'spark_today' => '1050'],
        ];

        foreach (['a', 'b', 'all'] as $pick) {
            $this->pick($pick);
            $after = $this->figures();

            foreach ($expected[$pick] as $figure => $want) {
                $grew = bcsub($after[$figure], $before[$pick][$figure], 2);

                $this->assertSame(0, bccomp($grew, $want, 2),
                    "⛔ '{$pick}' বাছা, অথচ হোম পর্দার '{$figure}' বাড়ল {$grew} — হওয়ার কথা {$want}।");
            }
        }
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** কর্মী ও হাজিরা, সরবরাহকারীর দেনা আর বিক্রি — দুই শাখায় আলাদা অঙ্কে, আর কিছু শাখাহীন। */
    private function putPeopleOwingsAndSalesInEachBranch(): void
    {
        // কর্মী: ক-তে ১ (উপস্থিত), খ-তে ২ (একজন উপস্থিত), শাখাহীন ১ (উপস্থিত)
        $this->employee($this->a->id, present: true);
        $this->employee($this->b->id, present: true);
        $this->employee($this->b->id, present: false);
        $this->employee(null, present: true);

        // সরবরাহকারীর দেনা — খাতার আসল দরজা দিয়ে, পক্ষসহ
        $supplier = (int) Supplier::query()->value('id');
        $this->owe($supplier, '500', $this->a->id);
        $this->owe($supplier, '800', $this->b->id);
        $this->owe($supplier, '50', null);

        [$first, $second, $third] = Customer::query()->orderBy('id')->take(3)->get()->all();
        $this->sell($first, $this->a, '700');
        $this->sell($second, $this->b, '300');
        $unbranched = $this->sell($third, $this->a, '50');
        DB::table('sal_invoices')->where('id', $unbranched)->update(['branch_id' => null]);

        CompanyContext::set($this->company->id, $this->a->id);
    }

    private function employee(?int $branch, bool $present): void
    {
        $employee = Employee::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'branch_id' => $branch ?? $this->a->id,
            'code' => 'EMP-'.mt_rand(100000, 999999),
            'name_en' => 'Branch worker',
            'joining_date' => now()->subYear()->toDateString(),
        ]);

        if ($branch === null) {
            DB::table('hr_employees')->where('id', $employee->id)->update(['branch_id' => null]);
        }

        if ($present) {
            Attendance::query()->withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'employee_id' => $employee->id,
                'work_date' => now()->toDateString(),
                'status' => Attendance::PRESENT,
            ]);
        }
    }

    private function owe(int $supplier, string $amount, ?int $branch): void
    {
        CompanyContext::set($this->company->id, $branch);

        app(PostingEngine::class)->post(
            sourceType: 'test:dashboard-corners',
            sourceId: random_int(1, 999999),
            trxDate: now(),
            lines: [
                ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => $amount],
                ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'credit' => $amount,
                    'party_type' => Supplier::drillSourceType(), 'party_id' => $supplier],
            ],
            branchId: $branch,
        );

        CompanyContext::set($this->company->id, $this->a->id);
    }

    private function sell(Customer $customer, Branch $branch, string $amount): int
    {
        CompanyContext::set($this->company->id, $branch->id);

        $warehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('branch_id', $branch->id)->orderBy('id')->value('id');

        $invoice = app(SalesInvoiceService::class)->create(
            ['customer_id' => $customer->id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => $amount]],
        );
        app(SalesInvoiceService::class)->confirm($invoice);

        CompanyContext::set($this->company->id, $this->a->id);

        return (int) $invoice->id;
    }

    /** হেডারের বাছাই — আসল দরজা দিয়ে, তারপর পরের অনুরোধের মতো প্রসঙ্গ। */
    private function pick(string $which): void
    {
        $branch = match ($which) {
            'a' => (string) $this->a->id,
            'b' => (string) $this->b->id,
            default => 'all',
        };

        $this->actingAs($this->owner->fresh())
            ->post(route('branch.switch'), ['branch_id' => $branch])
            ->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->actingAs($this->owner);
    }

    /**
     * কোণের সংখ্যাগুলো — পর্দা যেখান থেকে নেয় ঠিক সেখান থেকে।
     *
     * @return array<string, string>
     */
    private function figures(): array
    {
        $groups = app(DashboardRegistry::class)->forUser($this->owner);
        $all = array_merge(...array_values($groups));

        // HR — "উপস্থিত / বেতনের খাতায়"
        [$present, $onPayroll] = array_map('trim', explode('/', $this->widget($all, __('hr::dashboard.present_today'))->value));

        $employees = null;

        foreach (HrDashboard::dashboard()->stats as $stat) {
            if ($stat->label === __('hr::dashboard.employees')) {
                $employees = $stat->value;
            }
        }

        $this->assertNotNull($employees, 'HR-এর "কর্মী" সংখ্যাটাই নেই — দাবিটা তখন কিছুই মাপত না।');

        // ⓘ দেনা বা মার্জিন শূন্য হলে ঘরটা লুকায় — তখন শূন্য
        $owed = $this->maybe($all, __('supplier::widget.owed_to_principals'));
        $margin = $this->maybe($all, __('purchase::widget.margin_this_month'));

        return [
            'employees' => self::number($employees),
            'on_payroll' => self::number($onPayroll),
            'present' => self::number($present),
            'supplier_owed' => $owed === null ? '0' : self::number($owed->value),
            'margin_sold' => $margin === null ? '0' : self::number($margin->parts[__('supplier::field.sold')]),
            'spark_today' => self::number((string) last($this->salesToday($groups['today'])->spark)),
        ];
    }

    /** @param  list<Widget>  $widgets */
    private function widget(array $widgets, string $label): Widget
    {
        return $this->maybe($widgets, $label) ?? $this->fail("হোম পর্দায় '{$label}' ঘরটাই নেই — দাবিটা তখন কিছুই মাপত না।");
    }

    /** @param  list<Widget>  $widgets */
    private function maybe(array $widgets, string $label): ?Widget
    {
        foreach ($widgets as $widget) {
            if ($widget->label === $label) {
                return $widget;
            }
        }

        return null;
    }

    /** @param  list<Widget>  $widgets */
    private function salesToday(array $widgets): Widget
    {
        foreach ($widgets as $widget) {
            if ($widget->sort === 10 && $widget->tone === 'money' && $widget->spark !== []) {
                return $widget;
            }
        }

        $this->fail("হোম পর্দার 'আজ' দলে বিক্রির রেখাওয়ালা ঘর নেই।");
    }

    /** সাজানো সংখ্যা ("১,২৩৪.০০" বা "1,234.00") থেকে সংখ্যা। */
    private static function number(string $formatted): string
    {
        $western = strtr($formatted, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $clean = preg_replace('/[^0-9.\-]/', '', $western);

        return $clean === '' || $clean === null ? '0' : $clean;
    }
}
