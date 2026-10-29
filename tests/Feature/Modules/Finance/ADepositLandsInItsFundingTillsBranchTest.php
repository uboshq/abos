<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Services\DepositKindInstaller;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MovesTheHeaderBranch;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ জমা যে বাক্স থেকে টাকা গেল তার শাখায় — RCV-0143 / মালিক: কোনোভাবেই অন্য শাখায় নয়।
 *
 * ⚠️ যা ভাঙা ছিল: ময়মনসিংহের (A) বাক্স থেকে টাকা দিয়ে ব্যাংকে জমা খোলা হলো, অথচ অন্য ট্যাবে উপরের কাজের শাখা নেত্রকোনা (B)।
 * জমা, তার নম্বর, খোলার ভাউচার আর খাতার সারি — সব বসত B-তে: A-র নগদ কমল B-র খাতায়, আর জমাটা B-র তালিকায়।
 * ⭐ এখন সবটা A-তে, নম্বর A-র সিরিজ থেকে।
 *
 * ec, ১৯ অক্টোবর ২০২৬।
 */
final class ADepositLandsInItsFundingTillsBranchTest extends TestCase
{
    use MovesTheHeaderBranch;
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    public function test_a_deposit_funded_from_a_till_of_a_lands_with_its_vouchers_in_a(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $a->id);
        $this->actingAs($owner);
        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();

        $till = app(CashTillService::class)->create(['name_en' => 'A box', 'holder_id' => $owner->id]);
        CashTill::query()->withoutGlobalScopes()->whereKey($till->id)->update(['branch_id' => $a->id]);
        $this->putMoneyIn($till->account, '200000');

        $this->giveTheBranchItsOwnSeries($a, 'finance', 'DEP', 'ADEP');

        // ⓘ অন্য ট্যাবে: উপরের কাজের শাখা B, দেখা সব শাখায়
        $owner = $this->moveHeaderTo($owner, $b->id);
        $owner = $this->moveHeaderTo($owner, 'all');
        $this->assertSame((int) $b->id, (int) $owner->current_branch_id, 'প্রস্তুতি: উপরের কাজের শাখা B নয়।');

        $kind = DepositKind::query()->where('code', 'FDR')->firstOrFail();

        $this->post(route('finance.deposit.store', ['issuer' => $kind->issuer]), [
            'kind_id' => $kind->id,
            'institution_new' => 'সোনালী ব্যাংক',
            'held_by' => Deposit::BUSINESS,
            'return_word' => 'interest',
            'principal' => '100000',
            'opened_on' => now()->toDateString(),
            'funded_from_account_id' => $till->account_id,
        ])->assertSessionHasNoErrors();

        $deposit = Deposit::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame((int) $a->id, (int) $deposit->branch_id, '⛔ A-র বাক্সের টাকায় খোলা জমা উপরের শাখা B-তে বসল।');
        $this->assertStringStartsWith('ADEP-', (string) $deposit->document_no, '⛔ নম্বর A-র সিরিজ থেকে নয় — উপরের শাখার সিরিজ থেকে।');

        $voucherIds = DepositMovement::query()->withoutGlobalScopes()->where('deposit_id', $deposit->id)->pluck('voucher_id')->filter()->all();
        $this->assertNotSame([], $voucherIds, 'প্রস্তুতি: খোলার ভাউচার নেই।');

        foreach (Voucher::acrossBranches()->withoutGlobalScopes()->whereKey($voucherIds)->get() as $voucher) {
            $this->assertSame((int) $a->id, (int) $voucher->branch_id, "⛔ জমার ভাউচার {$voucher->document_no} A-র বাইরে।");
        }

        $rows = LedgerEntry::query()->withoutGlobalScopes()->where('account_id', $till->account_id)
            ->where('document_no', Voucher::acrossBranches()->withoutGlobalScopes()->whereKey($voucherIds)->value('document_no'))
            ->pluck('branch_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $this->assertSame([(int) $a->id], $rows, '⛔ A-র বাক্স থেকে বেরোনো টাকার খাতার সারি A-র বাইরে।');
    }
}
