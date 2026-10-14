<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * জমা (FDR/DPS) — অন্য শাখা দেখানো অবস্থায় দুবার ভাঙা, আর খাতা হেডারের শাখায় (Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
 * [[DepositService]])।
 *
 * ⭐ দাবিগুলো:
 *   · জমার প্রতিটা ভাউচার জমার নিজের শাখায় — হেডারে অন্য শাখা থাকলেও।
 *   · অপেক্ষার ভাঙা অন্য শাখা থেকেও চোখে পড়ে — দ্বিতীয় ভাঙা থামে।
 *   · জমা বন্ধ হয়ে গেলে অপেক্ষার আরেকটা চলাচলের শেষ সই আর কিছু বসায় না।
 */
final class ADepositClosedFromAnotherBranchClosedTwiceTest extends TestCase
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
        app(DepositKindInstaller::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        $this->putMoneyIn($this->cash(), '1000000', now()->subDays(5)->toDateString());
    }

    public function test_every_deposit_voucher_lands_in_the_deposits_branch(): void
    {
        $fdr = $this->fdr('FDR-BR', '100000');
        $this->choose('NTK');

        $payout = app(DepositService::class)->payout($fdr, ['amount' => '1000', 'money_account_id' => $this->cash()->id, 'moved_on' => now()->toDateString()]);
        $this->assertRowsIn((int) $payout->voucher_id, 'MMS', 'জমার মুনাফা তোলা');

        $close = app(DepositService::class)->close($fdr->fresh(), ['amount' => '100000', 'money_account_id' => $this->cash()->id, 'moved_on' => now()->toDateString()]);
        $this->assertRowsIn((int) $close->voucher_id, 'MMS', 'জমা ভাঙা');
    }

    public function test_a_waiting_closing_is_seen_from_another_branch_and_a_closed_deposit_takes_no_more(): void
    {
        $flow = ApprovalFlow::query()->create(['module' => 'finance', 'action' => 'deposit', 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => User::query()->where('email', 'accounts@abos.test')->value('id')]);
        $this->app->forgetInstance(ApprovalEngine::class);

        $fdr = $this->fdr('FDR-TWICE', '500000');
        $first = app(DepositService::class)->close($fdr, ['amount' => '500000', 'money_account_id' => $this->cash()->id, 'moved_on' => now()->toDateString()]);
        $this->assertSame(DocumentStatus::DRAFT, Voucher::acrossBranches()->find($first->voucher_id)?->status, 'দৃশ্যটাই বানানো যায়নি — ভাঙা সইয়ের অপেক্ষায় নেই');

        $this->choose('NTK');
        $this->app->forgetInstance(ApprovalEngine::class);

        try {
            app(DepositService::class)->close($fdr->fresh(), ['amount' => '500000', 'money_account_id' => $this->cash()->id, 'moved_on' => now()->toDateString()]);
            $this->fail('⛔ অন্য শাখা দেখানো অবস্থায় অপেক্ষার ভাঙা চোখ এড়াল — একই জমা দুবার ভাঙল');
        } catch (ValidationException $e) {
            $this->assertSame(__('finance::validation.awaits_signature_first'), $e->errors()['status'][0] ?? null, 'অন্য কারণে থামল');
        }
        $this->assertSame(1, DepositMovement::query()->where('deposit_id', $fdr->id)->where('kind', DepositMovement::CLOSED)->count());

        // ⓘ জমাটা অন্য পথে বন্ধ — অপেক্ষার ভাঙার শেষ সই এখন আর কিছু বসায় না
        Deposit::query()->whereKey($fdr->id)->update(['status' => Deposit::CLOSED]);

        try {
            app(DepositService::class)->finishSigned(Voucher::acrossBranches()->findOrFail($first->voucher_id));
            $this->fail('⛔ বন্ধ জমায় শেষ সই আবার মূলধন বের করল');
        } catch (ValidationException) {
        }

        $this->assertSame(DocumentStatus::DRAFT, Voucher::acrossBranches()->find($first->voucher_id)?->status, '⛔ বন্ধ জমার ভাঙা খাতায় বসল');
    }

    /** ⛔ হেডারে অন্য শাখা — বাতিলে প্রতিটা ভাউচার "নেই" হয়ে বাদ পড়ত, অথচ জমা "বাতিল"; ১১৬০-এ টাকা থেকে যেত */
    public function test_cancelling_from_another_branch_reverses_every_voucher(): void
    {
        $fdr = $this->opened('FDR-CNL', '200000');
        $this->choose('NTK');

        app(DepositService::class)->cancel($fdr->fresh(), 'ভুল করে বসানো');

        $this->assertSame(Deposit::CANCELLED, $fdr->fresh()->status);
        $this->assertSame(0, bccomp($this->balance(StandardChart::DEPOSITS_AND_INVESTMENTS), '0', 4),
            '⛔ অন্য শাখা দেখানো অবস্থায় বাতিল — জমা "বাতিল" অথচ ১১৬০-এ টাকা রয়ে গেল');
    }

    /** ⛔ Accounts থেকে জমার খোলার ভাউচার একা বাতিল হত — জমা "চালু" থাকত, অথচ ১১৬০ শূন্য */
    public function test_a_deposit_voucher_is_not_cancelled_alone_from_accounts(): void
    {
        $fdr = $this->opened('FDR-ACC', '50000');
        $opening = DepositMovement::query()->where('deposit_id', $fdr->id)->where('kind', DepositMovement::OPENED)->sole();

        try {
            app(\App\Modules\Accounts\Services\VoucherService::class)->cancel(Voucher::acrossBranches()->findOrFail($opening->voucher_id), 'ভুল');
            $this->fail('⛔ জমার খোলার ভাউচার Accounts থেকে একা বাতিল হল — জমার সারি আর খাতা আলাদা');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cancel_reason', $e->errors(), 'অন্য কারণে থামল');
        }

        $this->assertSame(0, bccomp($this->balance(StandardChart::DEPOSITS_AND_INVESTMENTS), '50000', 4));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** সেবার পথে খোলা — খোলার ভাউচারসহ */
    private function opened(string $no, string $principal): Deposit
    {
        return app(DepositService::class)->open([
            'kind_id' => DepositKind::query()->where('code', 'FDR')->value('id'), 'institution' => 'সোনালী ব্যাংক', 'reference_no' => $no,
            'held_by' => Deposit::BUSINESS, 'principal' => $principal, 'profit_rate' => '9', 'opened_on' => now()->subDays(2)->toDateString(),
            'matures_on' => now()->addYear()->toDateString(), 'funded_from_account_id' => $this->cash()->id,
        ]);
    }

    private function balance(string $code): string
    {
        return (string) LedgerEntry::query()->where('company_id', $this->company->id)->where('account_id', StandardChart::find($code)->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n');
    }

    private function fdr(string $no, string $principal): Deposit
    {
        $deposit = Deposit::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch('MMS')->id,
            'document_no' => $no, 'kind_id' => DepositKind::query()->where('code', 'FDR')->value('id'),
            'institution' => 'সোনালী ব্যাংক', 'held_by' => Deposit::BUSINESS, 'principal' => $principal,
            'profit_rate' => '9', 'tax_rate' => '10', 'return_word' => 'interest',
            'opened_on' => now()->subMonths(3)->toDateString(), 'matures_on' => now()->addYear()->toDateString(),
            'account_id' => StandardChart::find(StandardChart::DEPOSITS_AND_INVESTMENTS)->id, 'status' => Deposit::ACTIVE,
        ]);
        DepositMovement::query()->create(['company_id' => $this->company->id, 'deposit_id' => $deposit->id, 'kind' => DepositMovement::OPENED,
            'amount' => $principal, 'moved_on' => now()->subMonths(3)->toDateString()]);

        return $deposit->fresh();
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

    private function assertRowsIn(int $voucherId, string $branch, string $what): void
    {
        $branches = LedgerEntry::query()->whereIn('source_type', array_values(Voucher::SOURCE_TYPES))->where('source_id', $voucherId)
            ->distinct()->pluck('branch_id')->map(fn ($b) => (int) $b)->all();
        $this->assertNotSame([], $branches, "দৃশ্যটাই বানানো যায়নি — {$what}-এর খাতার সারি নেই");
        $this->assertSame([$this->branch($branch)->id], $branches, "⛔ {$what}-এর খাতার সারি হেডারের শাখায় বসল, জমার শাখায় নয়");
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
