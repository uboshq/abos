<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\LoanSchedule;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MovesTheHeaderBranch;
use Tests\TestCase;

/**
 * ⛔ চেক পক্ষের শাখায়, ঋণ যে বাক্সে টাকা ঢুকল তার শাখায় — RCV-0143 / মালিক: কোনোভাবেই অন্য শাখায় নয়।
 *
 * ⚠️ যা ভাঙা ছিল: অন্য ট্যাবে উপরের কাজের শাখা নেত্রকোনা (B) থাকা অবস্থায় ময়মনসিংহের (A) সরবরাহকারীকে চেক দেওয়া হলে চেক আর
 * তার নম্বর B-র, আর দেওয়ার দিনের দাখিলা B-তে চাইত (খাতার দরজা তখন থামাত — A-র পক্ষের সারি B-তে)। পরে পাশ বা ফেরতের দাখিলাও
 * বসত তখনকার উপরের শাখায়। A-র বাক্সে ঢোকা ঋণও বসত B-তে। ⭐ এখন চেক, নম্বর আর সারি A-তে; ঋণও A-তে।
 *
 * ec, ১৯ অক্টোবর ২০২৬।
 */
final class AChequeAndALoanLandInTheirOwnBranchTest extends TestCase
{
    use MovesTheHeaderBranch;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $a;

    private Branch $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->a->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_an_issued_cheque_to_a_supplier_of_a_lands_with_its_number_and_rows_in_a(): void
    {
        $supplier = Supplier::query()->create(['code' => 'SUP-BR-A', 'name_en' => 'Branch A Supplier', 'name_bn' => 'Branch A Supplier',
            'branch_id' => $this->a->id, 'is_active' => true]);
        $this->giveTheBranchItsOwnSeries($this->a, 'accounts', 'CHQ', 'ACHQ');
        $this->toBWatchingAll();

        $this->post(route('accounts.cheque.store'), [
            'direction' => Cheque::ISSUED,
            'cheque_no' => 'BR-A-1',
            'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->addDays(5)->toDateString(),
            'amount' => '9000',
            'party' => 'supplier:'.$supplier->id,
        ])->assertSessionHasNoErrors();

        $cheque = Cheque::query()->withoutGlobalScopes()->where('cheque_no', 'BR-A-1')->firstOrFail();

        $this->assertSame((int) $this->a->id, (int) $cheque->branch_id, '⛔ A-র সরবরাহকারীর চেক উপরের শাখা B-তে বসল।');
        $this->assertStringStartsWith('ACHQ-', (string) $cheque->document_no, '⛔ নম্বর A-র সিরিজ থেকে নয়।');

        $rows = LedgerEntry::query()->withoutGlobalScopes()->where('source_type', Cheque::STOCK_SOURCE)->where('source_id', $cheque->id)
            ->pluck('branch_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $this->assertSame([(int) $this->a->id], $rows, '⛔ চেক দেওয়ার দিনের সারি A-র বাইরে।');
    }

    public function test_a_loan_taken_into_a_till_of_a_lands_in_a(): void
    {
        $till = app(CashTillService::class)->create(['name_en' => 'A box', 'holder_id' => $this->owner->id]);
        CashTill::query()->withoutGlobalScopes()->whereKey($till->id)->update(['branch_id' => $this->a->id]);
        $this->giveTheBranchItsOwnSeries($this->a, 'accounts', 'LN', 'ALN');
        $this->toBWatchingAll();

        $this->post(route('accounts.loan.store'), [
            'lender' => 'Janata Bank',
            'kind' => Loan::TERM,
            'interest_method' => LoanSchedule::FLAT,
            'sanctioned' => '600000',
            'interest_rate' => '10',
            'tenure_months' => 12,
            'start_date' => now()->toDateString(),
            'principal_account_id' => Account::query()->where('code', '2210')->firstOrFail()->id,
            'interest_account_id' => Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->firstOrFail()->id,
            'into_account_id' => $till->account_id,
        ])->assertSessionHasNoErrors();

        $loan = Loan::query()->withoutGlobalScopes()->where('lender', 'Janata Bank')->firstOrFail();

        $this->assertSame((int) $this->a->id, (int) $loan->branch_id, '⛔ A-র বাক্সে ঢোকা ঋণ উপরের শাখা B-তে বসল।');
        $this->assertStringStartsWith('ALN-', (string) $loan->document_no, '⛔ নম্বর A-র সিরিজ থেকে নয়।');
    }

    /** ⓘ অন্য ট্যাবে: উপরের কাজের শাখা B, দেখা সব শাখায় */
    private function toBWatchingAll(): void
    {
        $this->owner = $this->moveHeaderTo($this->owner, $this->b->id);
        $this->owner = $this->moveHeaderTo($this->owner, 'all');
        $this->assertSame((int) $this->b->id, (int) $this->owner->current_branch_id, 'প্রস্তুতি: উপরের কাজের শাখা B নয়।');
    }
}
