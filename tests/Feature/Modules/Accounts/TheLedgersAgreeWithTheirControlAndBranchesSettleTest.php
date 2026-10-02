<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * পার্টির খাতা বনাম মূল খাতা, আর শাখা-শাখা দেনা-পাওনা — রিপোর্ট সেন্টার ধাপ ৬
 * ([[Customer\Reports\LedgerCheckReports]], [[BranchDuesReports]])।
 *
 *   গ্রাহক      পাওনার খাতে ৫০০ + নগদ খাতে ৩০ তাঁর নামে → খাতায় ৫৩০, পাওনার খাতে ৫০০, পার্থক্য ৩০
 *   পক্ষ ছাড়া   পাওনার খাতে ৭০, কারও নামে নয় → আলাদা সারি, পাওনার খাতে ৭০
 *   শাখা        এক শাখায় ডেবিট ১০০, অন্য শাখায় ক্রেডিট ১০০ (একই ভাউচার) → +১০০ / −১০০, কোম্পানির যোগ শূন্য
 * ⓘ দেখার চাবি `customer.report` — একই মানুষ, চাবি ছাড়া ৪০৩, চাবি দিলে খোলে।
 */
final class TheLedgersAgreeWithTheirControlAndBranchesSettleTest extends TestCase
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

        // ⓘ হিসাবের ছক — পাওনার খাত আর নগদের খাত এখান থেকেই আসে
        app(StandardChart::class)->install();
    }

    public function test_a_customer_ledger_is_set_against_the_receivable_control_and_the_unnamed_part_shows(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $receivable = $this->postable(StandardChart::RECEIVABLE);
        $cash = $this->postable(StandardChart::CASH_IN_HAND);

        $this->entry($receivable->id, '500', '0', 'customer', $customer->id);
        $this->entry($cash->id, '30', '0', 'customer', $customer->id);
        $this->entry($receivable->id, '70', '0');

        $rows = collect($this->rows('customer.ledger_check'));

        $mine = $rows->first(fn (array $r) => (int) $r['party_id'] === (int) $customer->id);
        $this->assertNotNull($mine, '⛔ গ্রাহক খাতায় নেই।');
        $this->assertSame(0, bccomp((string) $mine['party_total'], '530', 4), '⛔ গ্রাহকের খাতায় ৫৩০ নয়।');
        $this->assertSame(0, bccomp((string) $mine['control_total'], '500', 4), '⛔ পাওনার খাতে ৫০০ নয়।');
        $this->assertSame(0, bccomp((string) $mine['difference'], '30', 4), '⛔ পার্থক্য ৩০ নয় — অন্য খাতে বসা অংশ ধরা পড়েনি।');

        $nobody = $rows->first(fn (array $r) => $r['party_id'] === null);
        $this->assertNotNull($nobody, '⛔ পাওনার খাতে কারও নামে নয় এমন টাকা খাতায় আলাদা সারি পায়নি।');
        $this->assertSame(0, bccomp((string) $nobody['control_total'], '70', 4));
    }

    public function test_a_voucher_across_two_branches_shows_as_a_due_and_the_company_still_balances(): void
    {
        $home = $this->company->defaultBranch();
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('id', '<>', $home->id)->firstOrFail();
        $cash = $this->postable(StandardChart::CASH_IN_HAND);

        $before = collect($this->rows('accounts.branch_dues'))->keyBy('branch_name');

        $this->entry($cash->id, '100', '0', branchId: $home->id);
        $this->entry($cash->id, '0', '100', branchId: $other->id);

        $after = collect($this->rows('accounts.branch_dues'))->keyBy('branch_name');
        $net = fn ($rows, Branch $b) => (string) ($rows[$b->name()]['net'] ?? $rows[$b->name_en]['net'] ?? '0');

        $this->assertSame(0, bccomp(bcsub($net($after, $home), $net($before, $home), 4), '100', 4), '⛔ এই শাখার +১০০ দেখায়নি।');
        $this->assertSame(0, bccomp(bcsub($net($after, $other), $net($before, $other), 4), '-100', 4), '⛔ অন্য শাখার −১০০ দেখায়নি।');
        $this->assertSame(0, bccomp((string) $after->sum(fn ($r) => (float) $r['net']), '0', 2), '⛔ কোম্পানির যোগ শূন্য নয় — খাতাটাই ভাঙা।');
    }

    public function test_only_the_report_key_opens_the_ledger_check_same_person_off_then_on(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($clerk)->get(route('customer.report.show', 'ledger-check'))->assertForbidden();
        $clerk->givePermissionTo('customer.report');
        $this->actingAs($clerk->fresh())->get(route('customer.report.show', 'ledger-check'))->assertOk();
    }

    private function postable(string $code): Account
    {
        $root = Account::query()->where('code', $code)->firstOrFail();

        return Account::query()->postable()->whereIn('id', $root->selfAndDescendants()->pluck('id'))->orderBy('code')->firstOrFail();
    }

    private function entry(int $account, string $debit, string $credit, ?string $partyType = null, ?int $partyId = null, ?int $branchId = null): void
    {
        DB::table('ledger_entries')->insert([
            'company_id' => $this->company->id,
            'branch_id' => $branchId ?? $this->company->defaultBranch()->id,
            'financial_year_id' => DB::table('financial_years')->where('company_id', $this->company->id)->orderByDesc('id')->value('id'),
            'account_id' => $account,
            'party_type' => $partyType,
            'party_id' => $partyId,
            'trx_date' => now()->toDateString(),
            'debit' => $debit,
            'credit' => $credit,
            'source_type' => 'zq_test',
            'source_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $key): array
    {
        return app(ReportEngine::class)->run($key, [
            'from' => now()->subYear()->toDateString(), 'to' => now()->toDateString(),
        ], perPage: 500)->rows;
    }
}
