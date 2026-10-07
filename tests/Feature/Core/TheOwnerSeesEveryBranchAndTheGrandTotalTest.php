<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "সব শাখা"-তে রিপোর্ট শাখা ধরে ভাগ, প্রতিটার মোট, আর Grand Total — মালিকের নির্দেশ,
 * ২৯ সেপ্টেম্বর ২০২৬: *"সুপার অ্যাডমিনের সব রিপোর্ট শাখাভিত্তিক আলাদা করে দেখাবে,
 * যা যা দেখানো দরকার, সাথে Grand Total"*।
 *
 * ⭐ মূল দাবি: Σ শাখার মোট = Grand Total = ভাগ ছাড়া একই রিপোর্টের মোট — প্রতিটা
 * টাকার কলামে। প্রতিটা শাখার মোট তার নিজের কোয়েরিতে, যোগ করে বানানো নয়; তাই
 * মিলটা আসল প্রমাণ।
 *
 * ⓘ ভাগ চাইলে তবেই (`byBranch: true`) — ফোন আর ড্যাশবোর্ড আগের মতোই পায়।
 */
final class TheOwnerSeesEveryBranchAndTheGrandTotalTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT = 'sales.by_customer';

    private Company $company;

    private Branch $mymensingh;

    private Branch $netrakona;

    private User $owner;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->mymensingh = $this->branch('MMS');
        $this->netrakona = $this->branch('NTK');
        $this->day = now()->toDateString();

        CompanyContext::set($this->company->id, $this->mymensingh->id);
        $this->actingAs($this->owner);

        [$ours, $theirs, $head] = Customer::query()->orderBy('id')->take(3)->get()->all();
        $this->sell($ours, $this->mymensingh, '700');
        $this->sell($theirs, $this->netrakona, '300');
        $unbranched = $this->sell($head, $this->mymensingh, '50');
        DB::table('sal_invoices')->where('id', $unbranched)->update(['branch_id' => null]);
    }

    public function test_all_branches_splits_each_branch_and_the_parts_add_up_to_the_grand_total(): void
    {
        $plain = $this->report();
        $split = $this->report(byBranch: true);

        $this->assertTrue($split->isSplitByBranch(), '⛔ "সব শাখা"-তে রিপোর্ট শাখা ধরে ভাগ হয়নি।');

        $byName = [];
        foreach ($split->sections as $section) {
            $byName[$section->branchName] = $section->totals['total'];
        }

        $this->assertSame(0, bccomp($byName[$this->mymensingh->name()] ?? '0', '700', 2), 'ময়মনসিংহের মোট ভুল।');
        $this->assertSame(0, bccomp($byName[$this->netrakona->name()] ?? '0', '300', 2), 'নেত্রকোনার মোট ভুল।');
        $this->assertSame(0, bccomp($byName[__('core.report.no_branch')] ?? '0', '50', 2), 'শাখাহীন দলের মোট ভুল।');

        foreach ($split->totals as $column => $grand) {
            $sum = '0';
            foreach ($split->sections as $section) {
                $sum = bcadd($sum, (string) ($section->totals[$column] ?? '0'), 4);
            }

            $this->assertSame(0, bccomp($sum, (string) $grand, 4), "⛔ «{$column}» কলামে Σ শাখা ≠ Grand Total।");
            $this->assertSame(0, bccomp((string) $grand, (string) ($plain->totals[$column] ?? '0'), 4),
                "⛔ «{$column}» কলামে Grand Total ≠ ভাগ ছাড়া একই রিপোর্টের মোট।");
        }

        $this->assertCount(count($plain->rows), $split->rows, 'ভাগের পাশে সমতল সারিগুলো আগের মতো থাকেনি — ফোন আর ড্যাশবোর্ড বদলে যেত।');
    }

    public function test_no_split_when_not_asked_when_one_branch_is_chosen_or_for_a_running_balance(): void
    {
        $this->assertFalse($this->report()->isSplitByBranch(), 'না চাইতেই ভাগ হলো।');

        $this->assertFalse(app(ReportEngine::class)
            ->run('accounts.cash_book', ['from' => $this->day, 'to' => $this->day], byBranch: true)->isSplitByBranch(),
            '⛔ চলমান জেরের রিপোর্ট শাখায় কাটা হলো — জের ভুল হত।');

        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => (string) $this->mymensingh->id]);
        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->actingAs($this->owner);

        $this->assertFalse($this->report(byBranch: true)->isSplitByBranch(), 'একটা শাখা বাছা থাকতেও ভাগ হলো।');
    }

    public function test_the_export_stream_carries_each_branch_head_its_total_and_the_grand_total(): void
    {
        $marks = [];
        $grand = null;

        foreach (app(ReportEngine::class)->stream(self::REPORT, ['from' => $this->day, 'to' => $this->day], byBranch: true) as $row) {
            if (isset($row['__section'])) {
                $marks[] = $row['__section'].':'.($row['__branch'] ?? '');

                if ($row['__section'] === 'grand') {
                    $grand = $row['total'] ?? null;
                }
            }
        }

        $this->assertContains('head:'.$this->mymensingh->name(), $marks);
        $this->assertContains('total:'.$this->netrakona->name(), $marks);
        $this->assertSame('grand:', end($marks), 'শেষ সারিটা Grand Total নয়।');
        $this->assertSame(0, bccomp((string) $grand, '1050', 2), '⛔ এক্সপোর্টের Grand Total ভুল।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function report(bool $byBranch = false): \App\Core\Engines\Report\ReportResult
    {
        return app(ReportEngine::class)->run(self::REPORT, ['from' => $this->day, 'to' => $this->day], byBranch: $byBranch);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function sell(Customer $customer, Branch $branch, string $amount): int
    {
        CompanyContext::set($this->company->id, $branch->id);

        $warehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('branch_id', $branch->id)->orderBy('id')->value('id');

        $invoice = app(SalesInvoiceService::class)->create(
            ['customer_id' => $customer->id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse, 'trx_date' => $this->day],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => $amount]],
        );
        app(SalesInvoiceService::class)->confirm($invoice);

        CompanyContext::set($this->company->id, $this->mymensingh->id);

        return (int) $invoice->id;
    }
}
