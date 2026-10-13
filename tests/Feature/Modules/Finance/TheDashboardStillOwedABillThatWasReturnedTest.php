<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Services\LedgerBalances;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Finance\Dashboard\FinanceDashboard;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReturn;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * পুরো ফেরত দেওয়া বিল ড্যাশবোর্ডে তবুও "মেয়াদোত্তীর্ণ দেনা" — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (⚠️৩)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * অর্থের ড্যাশবোর্ডের "সামনের ৭ দিনের দেনা" আর "মেয়াদোত্তীর্ণ দেনা" বাকি গুনত মোট − পরিশোধ − ভাউচারে, কিন্তু পাকা
 * ক্রয়-ফেরত বাদ দিত না — অথচ [[PurchaseBill::dueAmount()]], সরবরাহকারীর খাতা আর [[CashForecast]] দেয়। ১,০০,০০০ টাকার
 * বিল পুরোটা ফেরত গেলেও ঘরটা বলত "মেয়াদোত্তীর্ণ ১,০০,০০০, একটা বিল"।
 *
 * ── ⭐ দাবি ───────────────────────────────────────────────────────────
 * পুরো ফেরত দেওয়া মেয়াদোত্তীর্ণ বিল ঘরটা নড়ায় না — না অঙ্ক, না গোনা; আর ঘরটা বিলের নিজের বাকির যোগফলের সাথে মেলে।
 */
final class TheDashboardStillOwedABillThatWasReturnedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    public function test_a_fully_returned_overdue_bill_is_not_owed_on_the_dashboard(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        config(['abos.dashboards_v2' => true]);

        $before = $this->overdue();

        $bill = $this->bill('PB-RET-1', Carbon::yesterday()->toDateString(), '100000');
        $this->returnOf($bill, 'PR-RET-1', '100000');

        $after = $this->overdue();

        $this->assertSame(0, bccomp($after['value'], $before['value'], 2),
            '⛔ পুরো ফেরত দেওয়া বিল মেয়াদোত্তীর্ণ দেনায় উঠেছে: আগে '.$before['value'].', পরে '.$after['value'].'।');
        $this->assertSame($before['hint'], $after['hint'], '⛔ পুরো ফেরত দেওয়া বিল মেয়াদোত্তীর্ণ বিলের গোনায় ঢুকেছে।');
        $this->assertSame(0, bccomp($after['value'], $this->rawOverdue(), 2),
            '⛔ মেয়াদোত্তীর্ণ দেনা বিলের নিজের বাকির ([[PurchaseBill::dueAmount()]]) যোগফল নয়।');
    }

    /** @return array{value: string, hint: string} */
    private function overdue(): array
    {
        app(LedgerBalances::class)->forget();
        $stat = $this->stat(FinanceDashboard::dashboard(), 'finance::dashboard.payables_overdue');
        $this->assertNotNull($stat, 'চালুতে মেয়াদোত্তীর্ণ দেনার ঘর নেই।');

        return ['value' => str_replace(',', '', $stat->value), 'hint' => (string) $stat->hint];
    }

    private function stat(DashboardDefinition $def, string $key): ?object
    {
        return collect($def->stats)->firstWhere('label', __($key));
    }

    private function rawOverdue(): string
    {
        return PurchaseBill::query()->posted()->withPaid()
            ->whereNotNull('due_on')
            ->where('due_on', '<', Carbon::today()->toDateString())
            ->get()
            ->reduce(fn (string $sum, PurchaseBill $bill) => bcadd($sum, $bill->dueAmount(), 4), '0');
    }

    private function bill(string $no, string $dueOn, string $total): PurchaseBill
    {
        return PurchaseBill::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('company_id', $this->company->id)->value('id'),
            'document_no' => $no,
            'supplier_id' => (int) DB::table('suppliers')->where('company_id', $this->company->id)->value('id'),
            'trx_date' => Carbon::today()->subDays(20)->toDateString(), 'due_on' => $dueOn,
            'subtotal' => $total, 'total' => $total, 'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function returnOf(PurchaseBill $bill, string $no, string $total): void
    {
        PurchaseReturn::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $bill->branch_id,
            'financial_year_id' => $bill->financial_year_id,
            'document_no' => $no,
            'supplier_id' => $bill->supplier_id,
            'warehouse_id' => (int) DB::table('inv_warehouses')->where('company_id', $this->company->id)->value('id'),
            'purchase_bill_id' => $bill->id,
            'trx_date' => Carbon::today()->toDateString(),
            'subtotal' => $total, 'total' => $total, 'status' => DocumentStatus::CONFIRMED,
        ]);
    }
}
