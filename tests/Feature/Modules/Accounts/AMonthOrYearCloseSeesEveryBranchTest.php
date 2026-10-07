<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MonthEndChecklist;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\YearEndService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ মাস আর বছর বন্ধের তালিকা দেখার শাখায় আটকে ছিল — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️৮)।
 *
 * ⓘ মাস আর বছর বন্ধ গোটা কোম্পানির ([[YearEndService]], [[MonthEndChecklist]] — লেজার-গার্ডে CHECKS), অথচ খসড়া, অনুমোদনের অপেক্ষা,
 * ব্যাংক মিলকরণ, টিল গোনা আর স্থায়ী সম্পদ খোঁজা হত দেখার শাখার দেয়ালে। এক শাখা বাছা থাকলে অন্য শাখার কাজ অদৃশ্য: তালিকা সবুজ,
 * বছর বন্ধ, আর ওই খসড়া আর কখনো পোস্ট হতে পারত না। এখন যে শাখাই দেখা হোক, উত্তর একই।
 */
final class AMonthOrYearCloseSeesEveryBranchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private FinancialYear $year;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->year = FinancialYear::query()->where('is_current', true)->firstOrFail();
        $this->month = CarbonImmutable::parse($this->year->starts_on)->addMonth()->startOfMonth();
    }

    public function test_a_draft_in_another_branch_stops_the_year_close(): void
    {
        $this->choose($this->branch('NTK')->id);
        $this->draft();

        $this->choose($this->branch('MMS')->id);
        $this->assertSame(0, Voucher::query()->where('status', DocumentStatus::DRAFT)->count(), 'দৃশ্যটাই বানানো যায়নি — MMS থেকে NTK-র খসড়া দেখা যায়');

        try {
            app(YearEndService::class)->assertCanClose($this->year);
            $this->fail('⛔ অন্য শাখায় খসড়া পড়ে থাকতে বছর বন্ধের ছাড় মিলল');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.year_has_drafts', ['count' => 1]), $e->errors()['year'][0] ?? null);
        }
    }

    public function test_the_month_end_checklist_says_the_same_from_every_branch(): void
    {
        $ntk = $this->branch('NTK')->id;
        $this->choose($ntk);

        // ⓘ NTK-তে মাসের প্রতিটা সারির জন্য কিছু: একটা খসড়া, একটা অপেক্ষমাণ, মেলানো ব্যাংক, গোনা টিল, অবচয়হীন সম্পদ
        $this->draft();
        $waiting = $this->draft();
        Approval::query()->create([
            'company_id' => $this->company->id, 'approvable_type' => Voucher::class, 'approvable_id' => $waiting->id,
            'module' => 'accounts', 'action' => 'journal', 'amount' => '700', 'status' => Approval::PENDING,
            'requested_by' => $this->owner->id, 'requested_at' => now(),
        ]);

        $bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-MEC', 'name_en' => 'Month End Bank', 'name_bn' => 'মাস-শেষের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
        app(PostingEngine::class)->post(sourceType: 'receipt_voucher', sourceId: random_int(1, 9_999_999), trxDate: $this->month->addDays(3)->toDateString(), lines: [
            ['account_id' => $bank->id, 'debit' => '1000'],
            ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '1000'],
        ], branchId: $ntk);
        BankReconciliation::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $ntk, 'bank_account_id' => $bank->id,
            'statement_date' => $this->month->endOfMonth()->toDateString(), 'statement_balance' => '1000',
            'status' => BankReconciliation::CONFIRMED, 'created_by' => $this->owner->id,
        ]);

        $till = app(CashTillService::class)->ensurePrimaryTill();
        CashCount::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $ntk, 'financial_year_id' => $this->year->id, 'document_no' => 'CC-MEC-1',
            'trx_date' => $this->month->addDays(5)->toDateString(), 'cash_till_id' => $till->id, 'status' => DocumentStatus::CONFIRMED,
            'counted_by' => $this->owner->id, 'created_by' => $this->owner->id,
        ]);

        FixedAsset::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $ntk, 'document_no' => 'FA-MEC-1', 'name' => 'ডেলিভারি ভ্যান',
            'asset_account_id' => $bank->id, 'accumulated_account_id' => $bank->id, 'expense_account_id' => $bank->id,
            'cost' => '120000', 'salvage' => '0', 'acquired_on' => $this->month->toDateString(), 'method' => FixedAsset::STRAIGHT_LINE,
            'life_months' => 60, 'status' => FixedAsset::ACTIVE, 'created_by' => $this->owner->id,
        ]);

        $fromNtk = $this->rows();

        $this->assertSame(2, $fromNtk['drafts'], 'প্রস্তুতিটাই ভুল — NTK থেকে দুইটা খসড়া দেখার কথা');
        $this->assertSame(1, $fromNtk['awaiting'], 'প্রস্তুতিটাই ভুল — NTK থেকে একটা অপেক্ষমাণ দেখার কথা');
        $this->assertSame(0, $fromNtk['bank_reconciled'], 'প্রস্তুতিটাই ভুল — NTK থেকে ব্যাংক মেলানো দেখার কথা');
        $this->assertSame(1, $fromNtk['depreciated'], 'প্রস্তুতিটাই ভুল — NTK থেকে অবচয়হীন সম্পদ দেখার কথা');

        $this->choose($this->branch('MMS')->id);

        foreach (['drafts', 'awaiting', 'bank_reconciled', 'cash_counted', 'depreciated'] as $key) {
            $this->assertSame($fromNtk[$key], $this->rows()[$key], "⛔ মাস-শেষের \"{$key}\" সারি MMS থেকে অন্য কথা বলে — NTK-র কাজ দেয়ালে আড়াল");
        }
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array<string, int> */
    private function rows(): array
    {
        return collect(app(MonthEndChecklist::class)->run($this->month))->mapWithKeys(fn (array $r) => [$r['key'] => $r['count']])->all();
    }

    private function draft(): Voucher
    {
        return app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $this->month->addDays(2)->toDateString(), 'narration' => 'খসড়া'],
            [
                ['account_id' => Account::query()->where('code', '5202')->value('id'), 'debit' => '700'],
                ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '700'],
            ],
        );
    }

    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => (string) $branch])->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
