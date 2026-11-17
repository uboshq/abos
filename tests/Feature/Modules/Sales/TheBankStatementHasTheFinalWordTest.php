<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Reports\DepositRegisterReport;
use App\Modules\Sales\Services\DepositClaimService;
use App\Modules\Sales\Services\DepositMatchService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ জমার খবর, ধাপ গ — শেষ কথা ব্যাংক স্টেটমেন্ট (মালিক, ১৬ নভেম্বর ২০২৬: "ok suro koro")।
 *
 * ⓘ খবর তখনই খাতায় ওঠে যখন ব্যাংকের স্টেটমেন্টের একটা সারিতে মেলে; মেলানোর প্রস্তাব কেবল হুবহু অঙ্ক, একই খাত আর ±N দিনে;
 * এক সারি এক খবর; খাতায় তোলা আদায়ের নিজের দরজায় — অনুমোদনের ছক আর সীমাসহ; জমার খাতার মোটে কেবল পাকা টাকা।
 */
final class TheBankStatementHasTheFinalWordTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

    private Account $bank;

    private Account $otherBank;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->clerk->givePermissionTo(['sales.claim.view', 'sales.claim.decide', 'sales.claim.match']);

        $this->bank = $this->bankAccount('1102-FINAL', 'Final word bank');
        $this->otherBank = $this->bankAccount('1102-OTHER', 'Other bank');

        $this->dealer = Customer::query()->orderBy('id')->firstOrFail();
        $this->dealer->forceFill(['branch_id' => $this->company->defaultBranch()?->id])->save();

        // ⓘ ডেমোর আদায়ের ছক থাকলে বন্ধ — প্রতিটা দাবি নিজের ছক নিজে বসায়
        ApprovalFlow::query()->where('module', 'sales')->where('action', 'collection')->update(['is_active' => false]);
        ApprovalEngine::flowsChanged();

        $this->actingAs($this->clerk->fresh());
    }

    // ── ১. স্টেটমেন্ট তোলা ───────────────────────────────────────────────

    public function test_loading_the_same_statement_twice_stores_every_line_once(): void
    {
        $csv = "Sonali Bank PLC,,,,,\nStatement of account 0118-0200-4471,,,,,\n"
            ."Txn Date,Particulars,Ref No,Withdrawal,Deposit,Balance\n"
            .'16/11/2026,CASH DEP DEP-778213,DEP-778213,,"45,000.00","1,45,000.00"'."\n"
            ."16/11/2026,CASH DEP,,,500.00,145500.00\n"
            ."16/11/2026,CASH DEP,,,500.00,146000.00\n"
            ."17/11/2026,CHQ 1234,1234,2000.00,,144000.00\n"
            .",Closing balance,,,,144000.00\n";

        $first = app(DepositMatchService::class)->load($this->bank, $this->file($csv));
        $this->assertSame(['added' => 4, 'known' => 0, 'bad' => []], $first,
            '⛔ ব্যাংকের ফাইলের চারটা টাকার সারি বসেনি — একই দিনের দুই ৫০০ দুইটাই বসার কথা');

        $again = app(DepositMatchService::class)->load($this->bank, $this->file($csv));
        $this->assertSame(0, $again['added'], '⛔ একই স্টেটমেন্ট আবার তুলতে সারি দ্বিগুণ হলো');
        $this->assertSame(4, $again['known']);
        $this->assertSame(4, BankStatementLine::query()->where('bank_account_id', $this->bank->id)->count(),
            '⛔ একই ফাইল দুইবার তোলায় টেবিলে সারি দুইবার');

        $line = BankStatementLine::query()->where('reference', 'DEP-778213')->firstOrFail();
        $this->assertSame('2026-11-16', $line->trx_date->toDateString(), '⛔ ১৬/১১/২০২৬ ভুল তারিখে বসল');
        $this->assertSame(0, bccomp((string) $line->credit, '45000', 4), '⛔ "45,000.00" জমা ঠিক পড়া হয়নি');
    }

    public function test_a_bank_xlsx_is_read_like_the_csv(): void
    {
        $result = app(DepositMatchService::class)->load($this->bank, $this->xlsx([
            ['Date', 'Narration', 'Reference', 'Debit', 'Credit'],
            ['46342', 'CASH DEPOSIT', 'XL-1', '', '12500'],
        ]));

        $this->assertSame(1, $result['added'], '⛔ ব্যাংকের XLSX থেকে সারি বসেনি');
        $line = BankStatementLine::query()->where('reference', 'XL-1')->firstOrFail();
        $this->assertSame('2026-11-16', $line->trx_date->toDateString(), '⛔ এক্সেলের দিন-সংখ্যা (46342) ভুল তারিখে');
        $this->assertSame(0, bccomp((string) $line->credit, '12500', 4));
    }

    public function test_a_line_typed_by_hand_twice_is_stored_once(): void
    {
        $service = app(DepositMatchService::class);
        $data = ['trx_date' => '2026-11-16', 'amount' => '7000', 'reference' => 'APP-1'];

        $this->assertTrue($service->addByHand($this->bank, $data));
        $this->assertFalse($service->addByHand($this->bank, $data), '⛔ হাতে একই সারি দ্বিতীয়বার বসল');
        $this->assertSame(1, BankStatementLine::query()->where('reference', 'APP-1')->count());
    }

    // ── ২. মেলানোর প্রস্তাব ───────────────────────────────────────────────

    public function test_a_match_is_suggested_only_on_the_exact_amount_account_and_window(): void
    {
        $claim = $this->claim('50000', '2026-11-16');

        $this->line($this->bank, '2026-11-16', '49999.99');          // ⓘ এক পয়সা কম
        $this->line($this->otherBank, '2026-11-16', '50000');        // ⓘ অন্য খাত
        $this->line($this->bank, '2026-11-20', '50000');             // ⓘ ৪ দিন পরে — জানালার বাইরে (৩)
        $good = $this->line($this->bank, '2026-11-19', '50000');     // ⓘ ঠিক ৩ দিন পরে

        $pairs = app(DepositMatchService::class)->suggestions();

        $this->assertSame([[(int) $claim->id, (int) $good->id]], $this->pairs($pairs),
            '⛔ প্রস্তাব কেবল হুবহু অঙ্ক, একই খাত আর ৩ দিনের মধ্যের সারিতে হওয়ার কথা');
    }

    public function test_two_equal_lines_or_two_equal_claims_are_left_to_a_person(): void
    {
        $this->claim('30000', '2026-11-16');
        $this->line($this->bank, '2026-11-16', '30000');
        $this->line($this->bank, '2026-11-17', '30000', 'OTHER');

        $this->assertSame([], app(DepositMatchService::class)->suggestions(), '⛔ দুইটা সমান সারির একটা যন্ত্র নিজে বেছে নিল');

        BankStatementLine::query()->where('reference', 'OTHER')->delete();
        $this->claim('30000', '2026-11-16', 'SLIP-2');

        $this->assertSame([], app(DepositMatchService::class)->suggestions(), '⛔ একটা সারি দুই সমান খবরের — যন্ত্র একটাকে বেছে নিল');
    }

    public function test_the_reference_picks_between_two_equal_lines(): void
    {
        $claim = $this->claim('30000', '2026-11-16', 'DEP-55');
        $this->line($this->bank, '2026-11-16', '30000', 'DEP-54');
        $right = $this->line($this->bank, '2026-11-16', '30000', 'DEP-55');

        $this->assertSame([[(int) $claim->id, (int) $right->id]], $this->pairs(app(DepositMatchService::class)->suggestions()),
            '⛔ রেফারেন্স মিলেছে, তবু প্রস্তাব এল না বা ভুল সারিতে');
    }

    // ── ৩. এক সারি এক খবর ───────────────────────────────────────────────

    public function test_a_bank_line_matches_one_claim_only_and_a_claim_one_line_only(): void
    {
        $first = $this->claim('20000', '2026-11-16');
        $second = $this->claim('20000', '2026-11-16', 'SLIP-B');
        $line = $this->line($this->bank, '2026-11-16', '20000');
        $spare = $this->line($this->bank, '2026-11-16', '20000', 'SPARE');

        $service = app(DepositMatchService::class);
        $service->match($first, $line);

        $this->assertSame('line', $this->refusedField(fn () => $service->match($second->fresh(), $line->fresh())),
            '⛔ একই ব্যাংকের সারি দ্বিতীয় খবরেও মিলে গেল — একই টাকা দুইবার খাতায় উঠত');
        $this->assertSame('claim', $this->refusedField(fn () => $service->match($first->fresh(), $spare->fresh())),
            '⛔ একই খবর দ্বিতীয় সারিতেও মিলে গেল');

        $this->assertSame((int) $line->id, (int) $first->fresh()->statement_line_id);
        $this->assertNull($second->fresh()->statement_line_id);
        $this->assertSame(DepositClaim::VERIFYING, $first->fresh()->status, 'মেলানোয় খবর "যাচাই চলছে" হয়নি');
    }

    public function test_a_line_already_in_the_books_is_not_offered_to_a_claim(): void
    {
        $claim = $this->claim('15000', '2026-11-16');
        $line = $this->line($this->bank, '2026-11-16', '15000');
        // ⓘ মিলকরণে খাতার একটা সারির সাথে মেলা — ডেমোতে ভাউচার নেই, তাই একটা জাবেদা (খসড়াই যথেষ্ট: কেবল জোড়ার সারি লাগে)
        $this->actingAs($this->owner);
        $voucher = app(\App\Modules\Accounts\Services\VoucherService::class)->create(
            ['type' => \App\Modules\Accounts\Models\Voucher::JOURNAL, 'trx_date' => '2026-11-16', 'narration' => 'booked by hand'],
            [
                ['account_id' => $this->bank->id, 'debit' => '15000'],
                ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => '15000'],
            ],
        );
        $voucherLine = $voucher->lines()->where('account_id', $this->bank->id)->firstOrFail();
        $this->actingAs($this->clerk->fresh());
        $line->forceFill(['matched_line_id' => $voucherLine->id])->save();

        $this->assertSame([], app(DepositMatchService::class)->suggestions());
        $this->assertSame('line', $this->refusedField(fn () => app(DepositMatchService::class)->match($claim, $line->fresh())),
            '⛔ মিলকরণে খাতায় মেলা সারি খবরেও মিলল — একই টাকা দুইবার খাতায়');
    }

    public function test_unmatch_needs_a_reason_and_frees_the_line(): void
    {
        $claim = $this->claim('9000', '2026-11-16');
        $line = $this->line($this->bank, '2026-11-16', '9000');
        $service = app(DepositMatchService::class);
        $service->match($claim, $line);

        $this->assertSame('reason', $this->refusedField(fn () => $service->unmatch($claim->fresh(), '  ')), '⛔ কারণ ছাড়া মিল খোলা গেল');

        $service->unmatch($claim->fresh(), 'ভুল দোকানের টাকা');
        $this->assertNull($claim->fresh()->statement_line_id);
        $this->assertSame('ভুল দোকানের টাকা', $claim->fresh()->unmatch_reason);
        $this->assertContains((int) $line->id, $service->freeLines()->pluck('id')->map(fn ($i) => (int) $i)->all(), '⛔ মিল খোলার পরে সারি আবার খালি হলো না');
    }

    // ── ৪. খাতায় তোলা — কেবল মেলার পরে, একবারই ─────────────────────────────

    public function test_an_unmatched_claim_does_not_reach_a_bank_account(): void
    {
        $claim = $this->claim('25000', '2026-11-16');

        $this->post(route('sales.claim.accept', $claim), ['account_id' => $this->bank->id])
            ->assertSessionHasErrors('account_id');

        $this->assertSame(__('sales::deposit.not_in_the_bank_yet'), session('errors')?->first('account_id'),
            '⛔ মেলানো ছাড়া গ্রহণ থামল, কিন্তু অন্য কারণে');
        $this->assertNull($claim->fresh()->collection_id, '⛔ ব্যাংকে না মেলা খবরের আদায় বসল');
        $this->assertTrue($claim->fresh()->isOpen());
    }

    public function test_a_matched_claim_posts_once_with_the_banks_amount_and_date(): void
    {
        $claim = $this->claim('50000', '2026-11-16');
        $line = $this->line($this->bank, '2026-11-17', '49950');
        app(DepositMatchService::class)->match($claim, $line);
        $dueBefore = $this->dealer->fresh()->outstanding();

        $this->post(route('sales.claim.accept', $claim), ['bank_charge' => '50'])->assertSessionHasNoErrors();

        $claim = $claim->fresh();
        $this->assertSame(DepositClaim::ACCEPTED, $claim->status, '⛔ মেলানো খবর "অনুমোদন দিন"-এ খাতায় উঠল না');
        $collection = Collection::query()->findOrFail($claim->collection_id);
        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status);
        $this->assertSame((int) $this->bank->id, (int) $collection->account_id, '⛔ ব্যাংকের সারির খাতে নয়');
        $this->assertSame('2026-11-17', $collection->trx_date->toDateString(), '⛔ খাতার তারিখ ব্যাংকের তারিখ নয়');
        $this->assertSame(0, bccomp($this->net($this->bank->id, $collection), '49950', 4), '⛔ ব্যাংক খাতে ব্যাংকের অঙ্ক নয়');
        $this->assertSame(bcsub($dueBefore, '50000', 4), $this->dealer->fresh()->outstanding(), '⛔ ডিলারের পাওনা এসেছে + চার্জে কমেনি');

        $this->post(route('sales.claim.accept', $claim), ['bank_charge' => '50'])->assertSessionHasErrors('status');
        $this->assertSame(1, Collection::query()->where('customer_id', $this->dealer->id)->where('narration', 'like', '%'.$claim->public_id.'%')->count(),
            '⛔ দ্বিতীয় চাপে আরেকটা আদায়');
        $this->assertSame(1, LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)
            ->where('account_id', $this->bank->id)->count(), '⛔ ব্যাংক খাতে দুইবার');
    }

    public function test_a_claim_into_the_cash_till_needs_no_bank_line(): void
    {
        $claim = $this->claim('3000', '2026-11-16', null, DepositClaim::CASH);
        $till = Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->notAnotherBranchsTill()->active()->orderBy('id')->firstOrFail();

        $this->post(route('sales.claim.accept', $claim), ['account_id' => $till->id])->assertSessionHasNoErrors();

        $this->assertSame(DepositClaim::ACCEPTED, $claim->fresh()->status, '⛔ ক্যাশে হাতে দেওয়া টাকাও ব্যাংকের সারি চাইল');
    }

    // ── ৫. অনুমোদনের ছক আর সীমা ─────────────────────────────────────────

    public function test_above_the_limit_the_claim_waits_for_the_approver_then_books_itself(): void
    {
        $this->flow('40000');
        $claim = $this->claim('50000', '2026-11-16');
        app(DepositMatchService::class)->match($claim, $this->line($this->bank, '2026-11-16', '50000'));

        $this->post(route('sales.claim.accept', $claim))->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('sales::deposit.sent_for_approval'));

        $claim = $claim->fresh();
        $this->assertTrue($claim->isOpen(), '⛔ সীমার উপরের জমা অনুমোদন ছাড়াই খাতায়');
        $this->assertSame(DepositClaim::STAGE_AWAITING, $claim->stage());
        $collection = Collection::query()->findOrFail($claim->collection_id);
        $this->assertSame(DocumentStatus::DRAFT, $collection->status);
        $this->assertSame(0, LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)->count(),
            '⛔ অনুমোদনের আগেই খাতায় দাখিলা');

        // ⓘ আবার চাপলে নতুন আদায় নয়
        $this->post(route('sales.claim.accept', $claim));
        $this->assertSame((int) $collection->id, (int) $claim->fresh()->collection_id, '⛔ দ্বিতীয় চাপে দ্বিতীয় খসড়া আদায়');
        $this->assertSame(1, Approval::query()->where('approvable_id', $collection->id)->where('status', Approval::PENDING)->count());

        $approval = Approval::query()->where('approvable_id', $collection->id)->where('status', Approval::PENDING)->firstOrFail();
        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->fresh()->status, '⛔ অনুমোদনের পরে আদায় খাতায় উঠল না');
        $this->assertSame(DepositClaim::ACCEPTED, $claim->fresh()->status, '⛔ আদায় খাতায়, কিন্তু খবর তবু "অপেক্ষায়"');
    }

    public function test_below_the_limit_and_with_approvals_off_it_books_at_once(): void
    {
        $this->flow('40000');
        $small = $this->claim('30000', '2026-11-16');
        app(DepositMatchService::class)->match($small, $this->line($this->bank, '2026-11-16', '30000'));
        $this->post(route('sales.claim.accept', $small))->assertSessionHasNoErrors();
        $this->assertSame(DepositClaim::ACCEPTED, $small->fresh()->status, '⛔ সীমার নিচের জমাও অনুমোদনে আটকাল');

        // ⓘ "অনুমোদন বন্ধ" কোম্পানি (UB) — ছক বন্ধ
        ApprovalFlow::query()->update(['is_active' => false]);
        ApprovalEngine::flowsChanged();
        $big = $this->claim('90000', '2026-11-16', 'SLIP-BIG');
        app(DepositMatchService::class)->match($big, $this->line($this->bank, '2026-11-16', '90000', 'BIG'));
        $this->post(route('sales.claim.accept', $big))->assertSessionHasNoErrors();
        $this->assertSame(DepositClaim::ACCEPTED, $big->fresh()->status, '⛔ অনুমোদন বন্ধেও বড় জমা আটকে থাকল');
    }

    public function test_unmatching_a_waiting_claim_cancels_its_draft_and_withdraws_the_request(): void
    {
        $this->flow('0');
        $claim = $this->claim('12000', '2026-11-16');
        app(DepositMatchService::class)->match($claim, $this->line($this->bank, '2026-11-16', '12000'));
        $this->post(route('sales.claim.accept', $claim));
        $collection = Collection::query()->findOrFail($claim->fresh()->collection_id);

        app(DepositMatchService::class)->unmatch($claim->fresh(), 'ব্যাংক ভুল সারি দিয়েছে');

        $this->assertSame(DocumentStatus::CANCELLED, $collection->fresh()->status, '⛔ মিল খুলল, কিন্তু খসড়া আদায় থেকে গেল');
        $this->assertNull($claim->fresh()->collection_id);
        $this->assertSame(0, Approval::query()->where('approvable_id', $collection->id)->where('status', Approval::PENDING)->count(),
            '⛔ বাতিল আদায়ের অনুরোধ অনুমোদনকারীর কাছে থেকে গেল');
    }

    // ── ৬. জমার খাতা ───────────────────────────────────────────────────

    public function test_the_register_totals_only_what_reached_the_books(): void
    {
        $booked = $this->claim('40000', '2026-11-16');
        app(DepositMatchService::class)->match($booked, $this->line($this->bank, '2026-11-16', '40000'));
        app(DepositClaimService::class)->accept($booked->fresh(), null);

        $this->claim('7000', '2026-11-16', 'OPEN-1');
        $matched = $this->claim('8000', '2026-11-16', 'OPEN-2');
        app(DepositMatchService::class)->match($matched, $this->line($this->bank, '2026-11-16', '8000', 'M8'));
        $rejected = $this->claim('5000', '2026-11-16', 'REJ-1');
        app(DepositClaimService::class)->reject($rejected, 'ব্যাংকে নেই');

        $result = app(ReportEngine::class)->run(DepositRegisterReport::KEY, ['from' => '2026-11-16', 'to' => '2026-11-16']);

        $confirmed = DepositClaim::query()->where('status', DepositClaim::ACCEPTED)->whereBetween('claimed_on', ['2026-11-16', '2026-11-16'])
            ->with('collection')->get()->reduce(fn (string $s, DepositClaim $c) => bcadd($s, (string) $c->collection->amount, 4), '0');

        $this->assertSame(0, bccomp((string) ($result->totals['confirmed_amount'] ?? '0'), $confirmed, 4),
            '⛔ খাতার "খাতায় উঠেছে" মোট পাকা জমার যোগফল নয়');
        $this->assertSame(0, bccomp((string) ($result->totals['confirmed_amount'] ?? '0'), '40000', 4));
        $this->assertSame(0, bccomp((string) ($result->totals['waiting_amount'] ?? '0'), '15000', 4),
            '⛔ অপেক্ষার লাইনে খোলা খবর (৭,০০০ + ৮,০০০) নয় — ফেরত বা পাকা মিশেছে');
    }

    // ── ৭. শাখার দেয়াল, দরজা, দুই ভাষা ───────────────────────────────────

    public function test_a_branch_walled_clerk_neither_sees_nor_matches_another_branchs_claim(): void
    {
        $claim = $this->claim('6000', '2026-11-16');
        $line = $this->line($this->bank, '2026-11-16', '6000');
        $elsewhere = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->whereKeyNot($this->company->defaultBranch()?->id)->orderBy('id')->firstOrFail();

        $this->clerk->forceFill(['view_all_branches' => false, 'current_branch_id' => $elsewhere->id])->save();
        CompanyContext::set($this->company->id, $elsewhere->id);
        app(DataScope::class)->forget();
        $this->actingAs($this->clerk->fresh());

        $this->post(route('sales.claim.match.store', $claim), ['line' => $line->id])->assertNotFound();
        $this->assertNull($claim->fresh()->statement_line_id, '⛔ অন্য শাখার খবর দেয়ালের ওপার থেকে মেলানো গেল');
        $this->get(route('sales.claim.match.index'))->assertOk()->assertDontSee('data-match-suggested', false);
    }

    public function test_the_doors_ask_for_their_keys(): void
    {
        $nobody = User::factory()->create(['current_company_id' => $this->company->id]);
        $nobody->companies()->attach($this->company->id, ['is_active' => true]);
        $nobody->givePermissionTo('sales.claim.view');
        $claim = $this->claim('4000', '2026-11-16');
        $line = $this->line($this->bank, '2026-11-16', '4000');

        $this->actingAs($nobody->fresh());
        $this->get(route('sales.claim.match.index'))->assertForbidden();
        $this->post(route('sales.claim.match.store', $claim), ['line' => $line->id])->assertForbidden();
        $this->post(route('sales.claim.match.line'), ['account_id' => $this->bank->id, 'trx_date' => '2026-11-16', 'amount' => '1'])->assertForbidden();
        $this->get(route('sales.report.show', 'deposit-register'))->assertOk();

        $nobody->revokePermissionTo('sales.claim.view');
        $this->actingAs($nobody->fresh());
        $this->get(route('sales.report.show', 'deposit-register'))->assertForbidden();

        $this->actingAs($this->clerk->fresh());
        $this->get(route('sales.claim.match.index'))->assertOk();
        $this->post(route('sales.claim.match.store', $claim), ['line' => $line->id])->assertSessionHasNoErrors();
        $this->assertSame((int) $line->id, (int) $claim->fresh()->statement_line_id);
    }

    public function test_the_money_receipt_prints_only_for_a_booked_deposit(): void
    {
        $claim = $this->claim('11000', '2026-11-16');
        $this->get(route('sales.claim.receipt', $claim))->assertNotFound();

        app(DepositMatchService::class)->match($claim, $this->line($this->bank, '2026-11-16', '11000'));
        app(DepositClaimService::class)->accept($claim->fresh(), null);

        $this->get(route('sales.claim.receipt', $claim))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_both_languages_show_words_not_keys(): void
    {
        $claim = $this->claim('5500', '2026-11-16');
        $this->line($this->bank, '2026-11-16', '5500');
        $booked = $this->claim('6600', '2026-11-16', 'B-1');
        app(DepositMatchService::class)->match($booked, $this->line($this->bank, '2026-11-16', '6600', 'L-B'));

        foreach (['bn', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->clerk->forceFill(['locale' => $locale])->save();
            $this->actingAs($this->clerk->fresh());

            foreach ([route('sales.claim.match.index'), route('sales.claim.index', ['status' => 'all']),
                route('sales.report.show', ['slug' => 'deposit-register', 'from' => '2026-11-16', 'to' => '2026-11-16'])] as $url) {
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertDoesNotMatchRegularExpression('/sales::(deposit|menu|settings)\.[a-z_.]+/', (string) $html, "⛔ {$locale}: {$url} পাতায় কাঁচা চাবি");
                $this->assertStringContainsString(e(__('sales::deposit.stage.matched', [], $locale)), (string) $html, "⛔ {$locale}: {$url} ধাপের নাম নেই");
            }
        }
        $this->assertNotNull($claim);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function bankAccount(string $code, string $name): Account
    {
        return Account::query()->create([
            'company_id' => $this->company->id, 'code' => $code, 'name_en' => $name, 'name_bn' => $name,
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);
    }

    private function claim(string $amount, string $on, ?string $reference = null, string $method = DepositClaim::BANK): DepositClaim
    {
        return app(DepositClaimService::class)->raise($this->dealer, [
            'claimed_on' => $on, 'amount' => $amount, 'method' => $method, 'reference' => $reference,
            'bank_account_id' => $method === DepositClaim::CASH ? null : $this->bank->id,
        ]);
    }

    private function line(Account $account, string $on, string $amount, ?string $reference = null): BankStatementLine
    {
        return BankStatementLine::query()->create([
            'company_id' => $this->company->id, 'bank_account_id' => $account->id, 'trx_date' => $on,
            'description' => 'CASH DEP', 'reference' => $reference, 'debit' => '0', 'credit' => $amount,
            'fingerprint' => hash('sha256', $account->id.$on.$amount.$reference.microtime()),
        ]);
    }

    private function flow(string $threshold): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => 'sales', 'action' => 'collection', 'document_type' => '',
            'threshold_amount' => $threshold, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);
    }

    /** @return list<array{0: int, 1: int}> */
    private function pairs(array $suggestions): array
    {
        return array_map(fn (array $p) => [(int) $p['claim']->id, (int) $p['line']->id], $suggestions);
    }

    private function refusedField(\Closure $do): ?string
    {
        try {
            $do();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        return null;
    }

    private function file(string $csv): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt').'.csv';
        file_put_contents($path, $csv);

        return new UploadedFile($path, 'statement.csv', 'text/csv', null, true);
    }

    /** @param  list<list<string>>  $rows */
    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $strings = [];
        $sheet = '';
        foreach ($rows as $r => $row) {
            $sheet .= '<row r="'.($r + 1).'">';
            foreach ($row as $c => $value) {
                if ($value === '') {
                    continue;
                }
                $ref = chr(65 + $c).($r + 1);
                if (is_numeric($value)) {
                    $sheet .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } else {
                    $strings[] = $value;
                    $sheet .= '<c r="'.$ref.'" t="s"><v>'.(count($strings) - 1).'</v></c>';
                }
            }
            $sheet .= '</row>';
        }

        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheet.'</sheetData></worksheet>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .implode('', array_map(fn ($s) => '<si><t>'.htmlspecialchars($s).'</t></si>', $strings)).'</sst>');
        $zip->close();

        return new UploadedFile($path, 'statement.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function net(int $accountId, Collection $collection): string
    {
        return (string) LedgerEntry::query()
            ->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as n')->value('n');
    }
}
