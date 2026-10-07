<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Dashboard\SupplierDashboard;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * সরবরাহকারীর "দেনার বয়স" চার্টের নিচের লেখা — ৬ অক্টোবর ২০২৬-এর ১০৮০p যাচাইয়ে ধরা: চারটা ভাগ মিলে ১.১৪ কোটি,
 * অথচ লেখায় "মোট দেনা 0.00"। ⓘ লেখা পড়ত রিপোর্টের `outstanding` কলাম, যা রিপোর্টে নেই; মোটের কলাম `payable`।
 *
 * দাবি: লেখার মোট = চার ভাগের যোগ = কোম্পানির খতিয়ানে সরবরাহকারীদের দেনা; আর সেটা শূন্য নয় (দাবিটা সত্যিই তাকায়)।
 */
final class TheSupplierAgeingSaysItsOwnTotalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_note_under_the_ageing_chart_is_the_sum_of_its_parts_and_the_books(): void
    {
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ বাকিতে কেনা — দেনা তৈরি হোক
        $bills = app(PurchaseBillService::class);
        $bills->confirm($bills->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->value('id'), 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->where('track_batch', false)->whereNull('tax_id')->orderBy('id')->value('id'), 'qty' => '10', 'rate' => '500']],
        ));

        $ageing = collect(SupplierDashboard::dashboard()->panels)
            ->first(fn ($p) => $p instanceof Breakdown && $p->label === __('supplier::dashboard.ageing'));
        $this->assertNotNull($ageing, 'দেনার বয়সের চার্ট নেই।');

        $sum = collect($ageing->parts)->reduce(fn (string $s, array $p) => bcadd($s, str_replace(',', '', (string) $p['value']), 2), '0');
        $books = (string) (DB::table('ledger_entries')->where('company_id', $company->id)->where('party_type', Supplier::drillSourceType())
            ->whereIn('party_id', Supplier::onlySuppliersIds())
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as n')->value('n') ?? '0');

        $this->assertSame(1, bccomp($sum, '0', 2), 'দেনা নেই — দাবিটা কিছু মাপছে না।');
        $this->assertSame(0, bccomp($sum, $books, 2), '⛔ চার ভাগের যোগ খতিয়ানের দেনা নয়।');
        $this->assertSame(__('supplier::dashboard.ageing_hint', ['total' => Money::format($sum)]), $ageing->hint,
            '⛔ চার্টের নিচের মোট চার ভাগের যোগ নয়।');
    }
}
