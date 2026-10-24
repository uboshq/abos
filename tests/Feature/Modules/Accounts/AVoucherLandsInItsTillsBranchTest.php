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
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\MovesTheHeaderBranch;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ বাক্সের টাকা বাক্সের শাখায় — RCV-0143 / মালিক: কোনোভাবেই অন্য শাখায় নয়।
 *
 * ⚠️ যা ভাঙা ছিল: ময়মনসিংহের (A) বাক্স থেকে নগদে খরচ লেখা হলো, অথচ অন্য ট্যাবে উপরের কাজের শাখা নেত্রকোনা (B)। ভাউচার,
 * তার নম্বর আর খাতার সারি — তিনটাই বসত B-তে: A-র নগদ কমল B-র খাতায়, আর নম্বর এল B-র (উপরের) সিরিজ থেকে।
 * ⭐ এখন তিনটাই বাক্সের শাখায়। বাক্স নেই এমন জাবেদা আগের মতো উপরের শাখায় (তার পাহারা ফর্মের শাখা-ছাপ)।
 *
 * ec, ১৯ অক্টোবর ২০২৬।
 */
final class AVoucherLandsInItsTillsBranchTest extends TestCase
{
    use MovesTheHeaderBranch;
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $a;

    private Branch $b;

    private CashTill $till;

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

        $this->till = app(CashTillService::class)->create(['name_en' => 'A box', 'holder_id' => $this->owner->id]);
        CashTill::query()->withoutGlobalScopes()->whereKey($this->till->id)->update(['branch_id' => $this->a->id]);
        $this->putMoneyIn($this->till->account, '10000');

        // ⓘ A-র নিজের খরচ-ভাউচার সিরিজ — নম্বরটা কোন সিরিজের, উপসর্গে পড়া যায়
        $this->giveTheBranchItsOwnSeries($this->a, 'accounts', Voucher::DOC_TYPES[Voucher::EXPENSE], 'AEV');

        // ⓘ অন্য ট্যাবে: উপরের কাজের শাখা B, দেখা সব শাখায় (A-র বাক্স তাই পর্দায় আছে)
        $this->owner = $this->moveHeaderTo($this->owner, $this->b->id);
        $this->owner = $this->moveHeaderTo($this->owner, 'all');
        $this->assertSame((int) $this->b->id, (int) $this->owner->current_branch_id, 'প্রস্তুতি: উপরের কাজের শাখা B নয়।');
    }

    public function test_a_cash_paid_expense_lands_with_its_number_and_rows_in_the_tills_branch(): void
    {
        $this->post(route('accounts.voucher.store', 'expense'), [
            'type' => Voucher::EXPENSE,
            'trx_date' => now()->toDateString(),
            'narration' => 'A-র বাক্স থেকে খরচ',
            'amount' => '700',
            'to_account_id' => $this->expense()->id,
            'from_account_id' => $this->till->account_id,
        ])->assertSessionHasNoErrors();

        $voucher = Voucher::acrossBranches()->withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame((int) $this->a->id, (int) $voucher->branch_id, '⛔ A-র বাক্সের খরচ উপরের শাখা B-তে বসল।');
        $this->assertStringStartsWith('AEV-', (string) $voucher->document_no, '⛔ নম্বর A-র সিরিজ থেকে নয় — উপরের শাখার সিরিজ থেকে।');
        $this->assertTrue($voucher->isPosted());

        $branches = LedgerEntry::query()->withoutGlobalScopes()
            ->where('source_type', Voucher::SOURCE_TYPES[Voucher::EXPENSE])->where('source_id', $voucher->id)
            ->pluck('branch_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $this->assertSame([(int) $this->a->id], $branches, '⛔ খাতার সারি A-র বাইরে।');
    }

    public function test_a_journal_with_no_box_stays_in_the_header_branch(): void
    {
        [$x, $y] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        $this->post(route('accounts.voucher.store', 'journal'), [
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => 'বাক্স ছাড়া জাবেদা',
            'save_as_draft' => '1',
            'lines' => [['account_id' => $x->id, 'debit' => '300', 'credit' => '0'], ['account_id' => $y->id, 'debit' => '0', 'credit' => '300']],
        ])->assertSessionHasNoErrors();

        $voucher = Voucher::acrossBranches()->withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame((int) $this->b->id, (int) $voucher->branch_id, 'বাক্স নেই — শাখা আগের মতো উপরেরটাই।');
    }

    public function test_choosing_another_branch_for_the_boxs_money_is_refused(): void
    {
        $before = Voucher::acrossBranches()->withoutGlobalScopes()->count();

        $this->post(route('accounts.voucher.store', 'expense'), [
            'type' => Voucher::EXPENSE,
            'trx_date' => now()->toDateString(),
            'narration' => 'A-র বাক্স, B-র নামে',
            'amount' => '700',
            'branch_id' => (string) $this->b->id,
            'to_account_id' => $this->expense()->id,
            'from_account_id' => $this->till->account_id,
        ])->assertSessionHasErrors('branch_id');

        $this->assertSame($before, Voucher::acrossBranches()->withoutGlobalScopes()->count(), '⛔ A-র বাক্সের টাকা B-র নামে লেখা গেল।');
    }

    /**
     * ⓘ কাগজের নিজের শাখা স্পষ্ট পাঠানো পথ (বিক্রয়ের কাউন্টার, ক্রয়ের পরিশোধ): একই শাখার বাক্সে বসে, অন্য শাখার বাক্সে থামে, কিছুই
     * লেখা হয় না — B-র কাগজে A-র নগদ নয়।
     */
    public function test_an_explicit_branch_takes_a_box_of_its_own_branch_and_refuses_a_box_of_another(): void
    {
        $service = app(VoucherService::class);
        $lines = [
            ['account_id' => $this->expense()->id, 'debit' => '500', 'credit' => '0'],
            ['account_id' => $this->till->account_id, 'debit' => '0', 'credit' => '500'],
        ];
        $data = fn (Branch $branch) => ['type' => Voucher::EXPENSE, 'trx_date' => now()->toDateString(), 'narration' => 'কাগজের শাখা',
            'branch_id' => $branch->id];

        $mine = $service->create($data($this->a), $lines);
        $this->assertSame((int) $this->a->id, (int) $mine->branch_id);

        $before = Voucher::acrossBranches()->withoutGlobalScopes()->count();

        try {
            $service->create($data($this->b), $lines);
            $this->fail('⛔ B-র কাগজে A-র বাক্সের নগদ বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('branch_id', $e->errors());
        }

        $this->assertSame($before, Voucher::acrossBranches()->withoutGlobalScopes()->count(), '⛔ থামার পরেও ভাউচার লেখা রইল।');
    }

    private function expense(): Account
    {
        // ⓘ কেবল-সফটওয়্যারের খাত বাদ — খরচের ভাউচারে ওগুলো বাছা যায় না (প্রথমটা ৫১০০; StandardChart::ENGINE_ONLY_EXPENSES)
        return Account::query()->postable()->active()->ofType([Account::EXPENSE])->whereNull('money_kind')
            ->whereNotIn('code', \App\Modules\Accounts\Services\StandardChart::ENGINE_ONLY_EXPENSES)->orderBy('code')->firstOrFail();
    }
}
