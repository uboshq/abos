<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Reports\BranchesSideBySideReport;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * শাখা পাশাপাশি — নির্বাহী পাতা আর শাখাভিত্তিক লাভ-ক্ষতি। রিপোর্ট সেন্টার ধাপ ২, ২ অক্টোবর ২০২৬
 * ([[BranchesSideBySideReport]])।
 *
 * দুটো নতুন শাখা, যাতে ডেমোর কিছু নেই:
 *   · ক — ১,০০০ বাকিতে বিক্রি, তার মালের দাম ৬০০, নগদে ৪০০ আদায়; আর সময়ের **আগে** ৩০০ বাকিতে বিক্রি
 *     (বিক্রিতে পড়ে না, পাওনায় পড়ে)।
 *   · খ — ৫০০-র মাল বাকিতে কেনা, ৫০ ভাড়া নগদে।
 */
final class EachBranchStandsBesideTheOthersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
    }

    public function test_each_branch_shows_its_own_sales_profit_money_dues_and_stock(): void
    {
        [$a, $b] = $this->happen();

        $rows = collect(app(ReportEngine::class)->run(BranchesSideBySideReport::KEY, [
            'from' => now()->subDays(2)->toDateString(), 'to' => now()->toDateString(),
        ], perPage: 500)->rows)->keyBy(fn ($r) => (string) $r['branch_name']);

        $want = [
            'BR-A' => ['sales' => '1000', 'income' => '1000', 'expense' => '600', 'profit' => '400', 'money' => '400', 'receivable' => '900', 'payable' => '0', 'stock' => '-600'],
            'BR-B' => ['sales' => '0', 'income' => '0', 'expense' => '50', 'profit' => '-50', 'money' => '-50', 'receivable' => '0', 'payable' => '500', 'stock' => '500'],
        ];

        foreach ($want as $branch => $cells) {
            $this->assertTrue($rows->has($branch), "প্রস্তুতিটাই ভুল — {$branch}-এর সারি নেই।");

            foreach ($cells as $key => $value) {
                $this->assertSame(0, bccomp((string) $rows[$branch][$key], $value, 2), "⛔ {$branch}-এর {$key} {$value} হওয়ার কথা, এল {$rows[$branch][$key]}।");
            }
        }

        $this->get(route('accounts.report.show', ['slug' => 'branches']))->assertOk()->assertSee('BR-A')->assertSee('BR-B');
    }

    public function test_only_the_final_accounts_key_opens_it_same_person_off_then_on(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo('accounts.report');

        $this->actingAs($clerk)->get(route('accounts.report.show', ['slug' => 'branches']))->assertForbidden();

        $clerk->givePermissionTo('accounts.report.final');
        $this->actingAs($clerk->fresh())->get(route('accounts.report.show', ['slug' => 'branches']))->assertOk();
    }

    /** @return array{Branch, Branch} */
    private function happen(): array
    {
        $home = $this->company->defaultBranch();
        $make = function (string $code) use ($home): Branch {
            $branch = $home->replicate(['public_id']);
            $branch->forceFill(['code' => $code, 'name_en' => $code, 'name_bn' => $code, 'is_default' => false])->save();

            return $branch;
        };
        $a = $make('BR-A');
        $b = $make('BR-B');

        $cash = Account::query()->ofMoneyKind(Account::CASH)->postable()->active()->orderBy('id')->firstOrFail();
        $id = fn (string $code) => StandardChart::find($code)->id;
        $post = fn (Branch $branch, array $lines, int $ago = 0) => app(PostingEngine::class)->post(
            sourceType: 'test:branches', sourceId: random_int(1, 9_999_999), trxDate: now()->subDays($ago)->toDateString(), lines: $lines, branchId: $branch->id,
        );
        $customer = ['party_type' => 'customer', 'party_id' => 1];
        $supplier = ['party_type' => 'supplier', 'party_id' => 1];

        $post($a, [['account_id' => $id(StandardChart::RECEIVABLE), 'debit' => '1000', ...$customer], ['account_id' => $id(StandardChart::SALES), 'credit' => '1000']]);
        $post($a, [['account_id' => $id(StandardChart::COST_OF_GOODS_SOLD), 'debit' => '600'], ['account_id' => $id(StandardChart::INVENTORY), 'credit' => '600']]);
        $post($a, [['account_id' => $cash->id, 'debit' => '400'], ['account_id' => $id(StandardChart::RECEIVABLE), 'credit' => '400', ...$customer]]);
        $post($a, [['account_id' => $id(StandardChart::RECEIVABLE), 'debit' => '300', ...$customer], ['account_id' => $id(StandardChart::SALES), 'credit' => '300']], ago: 10);

        $post($b, [['account_id' => $id(StandardChart::INVENTORY), 'debit' => '500'], ['account_id' => $id(StandardChart::PAYABLE), 'credit' => '500', ...$supplier]]);
        $post($b, [['account_id' => $id(StandardChart::RENT), 'debit' => '50'], ['account_id' => $cash->id, 'credit' => '50']]);

        return [$a, $b];
    }
}
