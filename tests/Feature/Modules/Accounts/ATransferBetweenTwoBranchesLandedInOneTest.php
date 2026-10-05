<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyTransferService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ম৬ (খ) — দুই শাখার বাক্সের মধ্যে স্থানান্তরের প্রতিটা পা নিজের শাখায় (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে দুই পা-ই কাগজের শাখায় (যিনি লিখলেন তাঁর) বসত: ময়মনসিংহের বাক্স থেকে নেত্রকোনার বাক্সে টাকা গেলে নেত্রকোনার
 * খাতায় তার নগদ বাড়ত না, আর ময়মনসিংহের খাতায় দুই দিকই নড়ত।
 */
final class ATransferBetweenTwoBranchesLandedInOneTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private Branch $mymensingh;

    private Branch $netrakona;

    private CashTill $from;

    private CashTill $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->mymensingh = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->netrakona = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();

        CompanyContext::set($this->company->id, $this->mymensingh->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $this->from = app(CashTillService::class)->create(['name_en' => 'MMS box', 'holder_id' => $owner->id]);
        $this->to = app(CashTillService::class)->create(['name_en' => 'NTK box', 'holder_id' => $owner->id]);
        CashTill::query()->whereKey($this->from->id)->update(['branch_id' => $this->mymensingh->id]);
        CashTill::query()->whereKey($this->to->id)->update(['branch_id' => $this->netrakona->id]);

        $this->putMoneyIn($this->from->account, '1000');
    }

    public function test_each_leg_lands_in_its_own_branch(): void
    {
        $transfer = $this->sendAndReceive('600');

        $this->assertSame(0, bccomp($this->onTill($this->from, $this->mymensingh), '-600', 4),
            '⛔ দাতার বাক্সের টাকা দাতার শাখায় কমেনি।');
        $this->assertSame(0, bccomp($this->onTill($this->to, $this->netrakona), '600', 4),
            '⛔ গ্রহীতার বাক্সের টাকা গ্রহীতার শাখায় বাড়েনি — অন্য শাখার নামে বসেছে।');

        foreach ([$this->mymensingh, $this->netrakona] as $branch) {
            $this->assertSame(0, bccomp($this->net($transfer, $branch), '0', 4), "⛔ শাখা {$branch->code}-এর খাতা এই কাগজে নিজে মেলে না।");
        }
    }

    /** ⓘ যিনি লিখছেন তিনি গ্রহীতার শাখায় বসা — কাগজের শাখা তখন নেত্রকোনা, অথচ টাকা বেরোল ময়মনসিংহের বাক্স থেকে */
    public function test_the_sending_leg_follows_the_box_not_the_writer(): void
    {
        CompanyContext::set($this->company->id, $this->netrakona->id);

        $transfer = $this->sendAndReceive('600');

        $this->assertSame((int) $this->netrakona->id, (int) $transfer->branch_id, 'প্রস্তুতি: কাগজটা লেখকের শাখায় নয়।');
        $this->assertSame(0, bccomp($this->onTill($this->from, $this->mymensingh), '-600', 4),
            '⛔ পাঠানো পা লেখকের শাখায় বসল — দাতার শাখায় নগদ কমেনি।');
        $this->assertSame(0, bccomp($this->onTill($this->from, $this->netrakona), '0', 4));
    }

    public function test_cancelling_takes_each_leg_back_out_of_its_own_branch(): void
    {
        $transfer = $this->sendAndReceive('600');

        app(MoneyTransferService::class)->cancel($transfer->fresh(), 'ভুল বাক্স');

        $this->assertSame(0, bccomp($this->onTill($this->from, $this->mymensingh), '0', 4));
        $this->assertSame(0, bccomp($this->onTill($this->to, $this->netrakona), '0', 4));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function sendAndReceive(string $amount): MoneyTransfer
    {
        $transfer = app(MoneyTransferService::class)->initiate([
            'from_till_id' => $this->from->id, 'to_till_id' => $this->to->id,
            'amount' => $amount, 'trx_date' => now()->toDateString(),
        ]);

        return app(MoneyTransferService::class)->confirm($transfer);
    }

    /** এই স্থানান্তরের সারিতে, এক শাখায়, বাক্সের খাতে ডেবিট − ক্রেডিট */
    private function onTill(CashTill $till, Branch $branch): string
    {
        return (string) LedgerEntry::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('account_id', $till->account_id)
            ->where('branch_id', $branch->id)
            ->where('source_type', 'like', MoneyTransfer::drillSourceType().'%')
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
    }

    private function net(MoneyTransfer $transfer, Branch $branch): string
    {
        return (string) LedgerEntry::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('document_no', $transfer->document_no)
            ->where('branch_id', $branch->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
    }
}
