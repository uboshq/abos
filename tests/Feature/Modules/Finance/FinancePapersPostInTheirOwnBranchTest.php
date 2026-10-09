<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\Finance\Services\InsuranceClaimService;
use App\Modules\Finance\Services\InsuranceService;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ অর্থের কাগজ কাগজের শাখায় খাতায় বসে, হেডারে যে শাখা দেখানো তাতে নয় — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (সারাই ৩)।
 *
 * ⓘ ভাড়া ৬ অক্টোবরে সারানো হয়েছিল ([[TheRentIsPaidInTheContractsBranchTest]]); হাতধার, বীমা দাবি, মূলধন আর লাভ বণ্টন তখনো
 * হেডারের শাখায় বসত — অন্য শাখা দেখানো অবস্থায় কাজ করলে খাতার সারি এক শাখায়, কাগজ আরেক শাখায়, আর শাখা ধরে কোনোটাই মিলত না।
 *
 * ⭐ দাবি: হেডারে অন্য শাখা দেখিয়ে কাজ করলেও খাতার সারি কাগজের শাখায় —
 *   · হাতধার দেওয়া · বীমা দাবির অনুমোদন আর টাকা আসা · মূলধন পোস্ট · রসিদ থেকে মূলধনের সারি
 *   · লাভ ঘোষণা (কোম্পানি-স্তরের কাগজ) — প্রধান শাখায়, হেডার যাই হোক; ভাগের সারিও সেখানে
 */
final class FinancePapersPostInTheirOwnBranchTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->putMoneyIn($this->cash(), '1000000', now()->subDays(5)->toDateString());
    }

    public function test_a_hand_loan_moves_in_its_own_branch(): void
    {
        $this->choose('MMS');
        $account = app(HandLoanService::class)->open(['person_id' => $this->person('Branch Karim')->id]);

        $this->choose('NTK');
        $movement = app(HandLoanService::class)->move($account->fresh(), [
            'direction' => 'out', 'amount' => '5000', 'moved_on' => now()->toDateString(), 'money_account_id' => $this->cash()->id,
        ]);

        $this->assertRowsIn($movement->voucher_id, 'MMS', 'হাতধার');
    }

    public function test_an_insurance_claim_books_its_approval_and_money_in_the_policys_branch(): void
    {
        $this->choose('MMS');
        $policy = $this->policy();
        $claim = app(InsuranceClaimService::class)->lodge($policy, [
            'incident_on' => now()->subDays(3)->toDateString(), 'claimed_on' => now()->subDays(2)->toDateString(),
            'incident' => 'Water', 'claimed_amount' => '100000',
        ]);

        $this->choose('NTK');
        $claim = app(InsuranceClaimService::class)->approve($claim->fresh(), [
            'approved_amount' => '80000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'LTR-1',
        ]);
        $done = app(InsuranceClaimService::class)->receive($claim->fresh(), [
            'money_account_id' => $this->cash()->id, 'amount' => '30000', 'received_on' => now()->toDateString(),
        ]);

        $this->assertRowsIn((int) $claim->approval_voucher_id, 'MMS', 'বীমা দাবির অনুমোদন');
        $this->assertRowsIn((int) $done['voucher']->id, 'MMS', 'বীমা দাবির টাকা');
    }

    public function test_capital_posts_in_the_entrys_branch_and_a_receipt_makes_its_row_in_the_receipts_branch(): void
    {
        $this->choose('MMS');
        $entry = app(CapitalService::class)->record([
            'person_id' => $this->person('Branch Partner')->id, 'contributor_type' => CapitalEntry::OWNER,
            'entry_type' => CapitalEntry::CONTRIBUTION, 'trx_date' => now()->toDateString(), 'amount' => '50000',
        ]);

        $this->choose('NTK');
        $posted = app(CapitalService::class)->post($entry->fresh(), $this->cash());

        $this->assertRowsIn((int) $posted->voucher_id, 'MMS', 'মূলধন');

        // ⓘ রসিদ MMS-এ, সই/পোস্ট হলো NTK দেখানো অবস্থায় — মূলধনের সারি রসিদের শাখায়
        $vouchers = app(VoucherService::class);
        $receipt = $vouchers->create([
            'type' => Voucher::RECEIPT, 'branch_id' => $this->branch('MMS')->id, 'trx_date' => now()->toDateString(),
            'narration' => 'capital by receipt', 'party_type' => 'person', 'party_id' => $this->person('Receipt Partner')->id,
        ], [
            ['account_id' => $this->cash()->id, 'debit' => '20000', 'credit' => '0'],
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => '0', 'credit' => '20000'],
        ]);
        $vouchers->post($receipt);

        $row = CapitalEntry::query()->where('voucher_id', $receipt->id)->first();
        $this->assertNotNull($row, 'দৃশ্যটাই বানানো যায়নি — রসিদ থেকে মূলধনের সারি বসেনি');
        $this->assertSame($this->branch('MMS')->id, (int) $row->branch_id, '⛔ রসিদের মূলধনের সারি হেডারের শাখায় বসল');
    }

    public function test_a_profit_declaration_lands_in_the_head_branch_whichever_branch_the_header_shows(): void
    {
        $this->earn('100000');
        $this->choose('MMS');
        app(CapitalService::class)->post(app(CapitalService::class)->record([
            'person_id' => $this->person('Profit Partner')->id, 'contributor_type' => CapitalEntry::OWNER,
            'entry_type' => CapitalEntry::CONTRIBUTION, 'trx_date' => now()->subDay()->toDateString(), 'amount' => '50000',
        ]), $this->cash());

        $head = (int) $this->company->defaultBranch()->id;
        $other = $head === $this->branch('NTK')->id ? 'MMS' : 'NTK';
        $this->choose($other);

        $shares = app(ProfitDistribution::class)->declare(['profit' => '10000', 'trx_date' => now()->toDateString()]);

        $this->assertNotEmpty($shares, 'দৃশ্যটাই বানানো যায়নি — কোনো ভাগ বসেনি');
        $this->assertSame([$head], LedgerEntry::query()->whereIn('source_type', array_values(Voucher::SOURCE_TYPES))->where('source_id', $shares[0]->voucher_id)
            ->distinct()->pluck('branch_id')->map(fn ($b) => (int) $b)->all(), '⛔ লাভ ঘোষণার খাতার সারি হেডারের শাখায় বসল');
        $this->assertSame([$head], ProfitShare::query()->where('voucher_id', $shares[0]->voucher_id)->distinct()->pluck('branch_id')
            ->map(fn ($b) => (int) $b)->all(), '⛔ ভাগের সারি আর খাতা আলাদা শাখায়');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function assertRowsIn(int $voucherId, string $branch, string $what): void
    {
        $branches = LedgerEntry::query()->whereIn('source_type', array_values(Voucher::SOURCE_TYPES))->where('source_id', $voucherId)
            ->distinct()->pluck('branch_id')->map(fn ($b) => (int) $b)->all();

        $this->assertNotSame([], $branches, "দৃশ্যটাই বানানো যায়নি — {$what}-এর খাতার সারি নেই");
        $this->assertSame([$this->branch($branch)->id], $branches, "⛔ {$what}-এর খাতার সারি হেডারের শাখায় বসল, কাগজের শাখায় নয়");
    }

    private function choose(string $code): void
    {
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => (string) $this->branch($code)->id])->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function earn(string $amount): void
    {
        app(PostingEngine::class)->post(
            sourceType: 'test:earned',
            sourceId: random_int(1, 999999),
            trxDate: now()->subDays(2)->toDateString(),
            lines: [
                ['account_id' => $this->cash()->id, 'debit' => $amount],
                ['account_id' => StandardChart::find(StandardChart::RETAINED_EARNINGS)->id, 'credit' => $amount],
            ],
        );
    }

    private function policy(): InsurancePolicy
    {
        $insurer = Institution::query()->create([
            'company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Branch Insurer',
        ]);

        return app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'BR-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '4500',
            'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonths(10)->toDateString(),
        ]);
    }

    private function person(string $name): Person
    {
        return Person::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'name_en' => $name],
            ['code' => 'P-'.mb_substr(md5($name), 0, 6), 'name_bn' => $name, 'is_active' => true],
        );
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }
}
