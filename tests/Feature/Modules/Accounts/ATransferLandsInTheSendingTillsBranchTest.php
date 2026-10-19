<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MovesTheHeaderBranch;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ হস্তান্তরের কাগজ দাতার বাক্সের শাখায় — RCV-0143 / মালিক: কোনোভাবেই অন্য শাখায় নয়।
 *
 * ⚠️ যা ভাঙা ছিল: ময়মনসিংহের (A) বাক্স থেকে টাকা পাঠানো হলো, অথচ অন্য ট্যাবে উপরের কাজের শাখা নেত্রকোনা (B)। পা দুটো বাক্স ধরে
 * ঠিক শাখায় বসত, কিন্তু কাগজটা আর তার নম্বর বসত B-তে — B-র তালিকায় A-র টাকা, নম্বর B-র সিরিজ থেকে।
 *
 * ec, ১৯ অক্টোবর ২০২৬।
 */
final class ATransferLandsInTheSendingTillsBranchTest extends TestCase
{
    use MovesTheHeaderBranch;
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    public function test_a_transfer_from_a_till_of_a_lands_in_a_with_a_number_of_as_series(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $a->id);
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $from = app(CashTillService::class)->create(['name_en' => 'A box', 'holder_id' => $owner->id]);
        $to = app(CashTillService::class)->create(['name_en' => 'A safe', 'holder_id' => $owner->id]);
        CashTill::query()->withoutGlobalScopes()->whereKey([$from->id, $to->id])->update(['branch_id' => $a->id]);
        $this->putMoneyIn($from->account, '5000');

        $this->giveTheBranchItsOwnSeries($a, 'accounts', 'MT', 'AMT');

        // ⓘ অন্য ট্যাবে: উপরের কাজের শাখা B, দেখা সব শাখায়
        $owner = $this->moveHeaderTo($owner, $b->id);
        $owner = $this->moveHeaderTo($owner, 'all');
        $this->assertSame((int) $b->id, (int) $owner->current_branch_id, 'প্রস্তুতি: উপরের কাজের শাখা B নয়।');

        $this->post(route('accounts.transfer.store'), [
            'trx_date' => now()->toDateString(),
            'from_till_id' => $from->id,
            'destination' => 'till:'.$to->id,
            'amount' => '1200',
            'narration' => 'A-র বাক্স থেকে A-র সিন্দুকে',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $transfer = MoneyTransfer::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame((int) $a->id, (int) $transfer->branch_id, '⛔ A-র বাক্স থেকে পাঠানো কাগজ উপরের শাখা B-তে বসল।');
        $this->assertStringStartsWith('AMT-', (string) $transfer->document_no, '⛔ নম্বর A-র সিরিজ থেকে নয় — উপরের শাখার সিরিজ থেকে।');
    }
}
