<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsReversalService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsuranceClaim;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Reports\InsuranceReports;
use App\Modules\Finance\Services\InsuranceClaimService;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ বীমার দাবির খাতা — অর্থ-মডিউলের পরিকল্পনা ৬.৪, ৬ অক্টোবর ২০২৬ ([[InsuranceClaimService]]; সমন্বয়কের উত্তর প্র৩,
 * IAS 37: খাতায় কেবল টাকা এলে বা লিখিত অনুমোদনে; জমা দেওয়া দাবি তালিকায়)।
 *
 * ⭐ দাবি:
 *   · জমা দেওয়া দাবি খাতায় নেই (1152, 4370 শূন্য), কিন্তু দাবির খাতায় আছে — চাওয়া অঙ্কই বাকি
 *   · অনুমোদন ছাড়া টাকা এলে Cr 4370; কয়েক দফায়: আংশিক → নিষ্পন্ন; বাকির বেশি নয়
 *   · লিখিত অনুমোদন (চিঠির নম্বর ছাড়া নয়) — Dr 1152 / Cr 4370; তারপরের টাকা Cr 1152; বন্ধ করলে না-আসা অংশ উল্টো, 1152 শূন্য
 *   · আগে কিছু এসে পরে অনুমোদন — কেবল বাকিটা প্রাপ্যে; শেষে আয় = মোট পাওয়া
 *   · ভাউচারের পর্দা থেকে ভুল খাতে রসিদ — থামে, খাতায় বসে না; রসিদ বাতিল হলে পাওয়া কমে, অবস্থা ফেরে
 *   · নাকচ — কিছুই না এলে; অনুমোদন খাতায় থাকলে পুরোটা উল্টো
 *   · কাজ কেবল বীমা চালানোর চাবিতে
 */
final class AnInsuranceClaimIsBookedOnlyWhenItIsRealTest extends TestCase
{
    use RefreshDatabase;

    private InsuranceClaimService $claims;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->claims = app(InsuranceClaimService::class);
        $this->bank = $this->bankLeaf();
    }

    public function test_a_lodged_claim_is_on_the_register_but_not_in_the_books(): void
    {
        $claim = $this->lodge('100000');

        $this->assertSame(InsuranceClaim::LODGED, $claim->status);
        $this->assertSame('0', $this->balance(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '⛔ জমা দেওয়া দাবি খাতায় উঠল।');
        $this->assertSame('0', $this->balance(StandardChart::INSURANCE_CLAIM_INCOME), '⛔ জমা দেওয়া দাবি আয়ে উঠল।');

        $row = $this->register()->firstWhere('policy_no', 'CL-1');
        $this->assertNotNull($row, '⛔ জমা দেওয়া দাবি খাতার তালিকায় নেই।');
        $this->assertSame(0, bccomp((string) $row['outstanding'], '100000', 4));
        $this->assertSame(__('finance::insurance_claim.state_lodged'), $row['state_label']);
        $this->assertCount(0, $this->register(['state' => InsuranceClaim::SETTLED]), '⛔ অবস্থার ছাঁকনি অন্য অবস্থা দিল।');

        $this->get(route('finance.insurance.claim.show', $claim))->assertOk()->assertSee('data-insurance-claim', false);
        $this->get(route('finance.insurance.show', $claim->policy_id))->assertOk()->assertSee('data-insurance-claims', false);
        $this->get(route('finance.report.show', ['slug' => 'insurance-claims']))->assertOk()->assertSee('data-claim-state', false);
    }

    public function test_money_without_approval_is_income_in_parts_and_never_more_than_is_left(): void
    {
        $claim = $this->lodge('100000');

        $this->receive($claim, '40000');
        $this->assertSame(InsuranceClaim::PARTIAL, $claim->fresh()->status);
        $this->assertSame(0, bccomp($this->balance(StandardChart::INSURANCE_CLAIM_INCOME), '40000', 4), '⛔ অনুমোদন ছাড়া টাকা আয়ে বসেনি।');

        try {
            $this->receive($claim->fresh(), '60000.01');
            $this->fail('⛔ বাকির বেশি টাকা নেওয়া গেল।');
        } catch (ValidationException $e) {
            // ⓘ দাবির নিজের কথায়, "বাকি আছে …" — হিসাবের সাধারণ "অঙ্ক মেলে না" নয়
            $this->assertArrayHasKey('amount', $e->errors(), 'বাকির বেশি টাকায় দাবির নিজের বার্তা আসেনি।');
        }

        $this->receive($claim->fresh(), '60000');
        $fresh = $claim->fresh();
        $this->assertSame(InsuranceClaim::SETTLED, $fresh->status);
        $this->assertSame(0, bccomp((string) $fresh->received_amount, '100000', 4));
        $this->assertSame('0', $this->balance(StandardChart::INSURANCE_CLAIM_RECEIVABLE));
    }

    public function test_a_written_approval_books_a_receivable_and_closing_reverses_what_never_came(): void
    {
        $claim = $this->lodge('100000');

        try {
            $this->claims->approve($claim, ['approved_amount' => '80000', 'approved_on' => now()->toDateString(), 'approval_ref' => ' ']);
            $this->fail('⛔ চিঠির নম্বর ছাড়া অনুমোদন বসল।');
        } catch (ValidationException) {
        }

        $this->claims->approve($claim->fresh(), ['approved_amount' => '80000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'GD/CL/77']);
        $this->assertSame(InsuranceClaim::APPROVED, $claim->fresh()->status);
        $this->assertSame(0, bccomp($this->balance(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '80000', 4));
        $this->assertSame(0, bccomp($this->balance(StandardChart::INSURANCE_CLAIM_INCOME), '80000', 4));

        $this->receive($claim->fresh(), '50000');
        $this->assertSame(0, bccomp($this->balance(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '30000', 4),
            '⛔ অনুমোদনের পরের টাকা প্রাপ্য থেকে কমেনি — আয় দুবার?');
        $this->assertSame(0, bccomp($this->balance(StandardChart::INSURANCE_CLAIM_INCOME), '80000', 4));
        $this->assertSame(0, bccomp((string) $this->register()->firstWhere('policy_no', 'CL-1')['outstanding'], '30000', 4),
            '⛔ খাতার "বাকি" অনুমোদিত − পাওয়া নয়।');

        $this->claims->close($claim->fresh(), 'বাকিটা দেবে না', now()->toDateString());
        $this->assertSame(InsuranceClaim::SETTLED, $claim->fresh()->status);
        $this->assertSame('0', $this->balance(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '⛔ বন্ধের পরেও প্রাপ্য ঝুলে রইল।');
        $this->assertSame(0, bccomp($this->balance(StandardChart::INSURANCE_CLAIM_INCOME), '50000', 4), 'আয় = যা এল, তা নয়।');
        $this->assertSame(0, bccomp((string) $this->register()->firstWhere('policy_no', 'CL-1')['outstanding'], '0', 4),
            '⛔ বন্ধ দাবি খাতায় এখনো বাকি দেখায়।');
    }

    public function test_money_before_the_approval_is_not_booked_twice(): void
    {
        $claim = $this->lodge('100000');
        $this->receive($claim, '20000');

        $this->claims->approve($claim->fresh(), ['approved_amount' => '70000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'L-2']);
        $this->assertSame(0, bccomp($this->balance(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '50000', 4), '⛔ আগে আসা টাকাও প্রাপ্যে উঠল।');
        $this->assertSame(InsuranceClaim::PARTIAL, $claim->fresh()->status);

        $this->receive($claim->fresh(), '50000');
        $this->assertSame(InsuranceClaim::SETTLED, $claim->fresh()->status, 'অনুমোদিত পুরোটা এলে নিষ্পন্ন নয়।');
        $this->assertSame('0', $this->balance(StandardChart::INSURANCE_CLAIM_RECEIVABLE));
        $this->assertSame(0, bccomp($this->balance(StandardChart::INSURANCE_CLAIM_INCOME), '70000', 4));
    }

    public function test_a_receipt_to_the_wrong_head_stops_and_a_reversed_receipt_takes_the_money_back(): void
    {
        $claim = $this->lodge('100000');
        $vouchers = app(VoucherService::class);
        $sales = Account::query()->where('code', '4100')->firstOrFail();

        $wrong = $vouchers->create([
            'type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'wrong head', 'instrument_no' => 'TST-W1',
            'against_type' => 'insurance_claim', 'against_id' => $claim->id,
        ], [
            ['account_id' => $this->bank->id, 'debit' => '1000', 'credit' => '0'],
            ['account_id' => $sales->id, 'debit' => '0', 'credit' => '1000'],
        ]);

        try {
            $vouchers->post($wrong);
            $this->fail('⛔ দাবির টাকা বিক্রয়ের খাতে বসে গেল।');
        } catch (ValidationException) {
        }

        $this->assertTrue($wrong->fresh()->isDraft(), '⛔ ভুল খাতের রসিদ খাতায় বসল।');
        $this->assertSame(0, bccomp((string) $claim->fresh()->received_amount, '0', 4));

        $receipt = $this->receive($claim->fresh(), '30000');
        $this->assertSame(InsuranceClaim::PARTIAL, $claim->fresh()->status);

        // ⓘ চুক্তি: `unsettle` সেই রসিদটা নিজেই বাদ দেয়, ডাকার সময় ভাউচারের অবস্থা যা-ই থাকুক; আর আবার ডাকলে ফেরে
        $claim->fresh()->unsettle((int) $receipt->id);
        $this->assertSame(0, bccomp((string) $claim->fresh()->received_amount, '0', 4), '⛔ বাদ দেওয়া রসিদ তবু গোনা হলো।');
        $claim->fresh()->settleWith((int) $receipt->id);
        $this->assertSame(0, bccomp((string) $claim->fresh()->received_amount, '30000', 4));

        app(AccountsReversalService::class)->reverseVoucher($receipt, auth()->user(), 'ভুল রসিদ');
        $fresh = $claim->fresh();
        $this->assertSame(0, bccomp((string) $fresh->received_amount, '0', 4), '⛔ বাতিল রসিদের টাকা দাবিতে "পাওয়া" রয়ে গেল।');
        $this->assertSame(InsuranceClaim::LODGED, $fresh->status);
    }

    public function test_rejection_only_when_nothing_came_and_it_reverses_the_approval(): void
    {
        $paid = $this->lodge('5000', 'CL-P');
        $this->receive($paid, '1000');

        try {
            $this->claims->reject($paid->fresh(), 'না', now()->toDateString());
            $this->fail('⛔ টাকা আসার পরেও নাকচ হলো।');
        } catch (ValidationException) {
        }

        $claim = $this->lodge('100000');
        $this->claims->approve($claim, ['approved_amount' => '60000', 'approved_on' => now()->toDateString(), 'approval_ref' => 'L-3']);
        $this->claims->reject($claim->fresh(), 'পরে কোম্পানি নাকচ করল', now()->toDateString());

        $this->assertSame(InsuranceClaim::REJECTED, $claim->fresh()->status);
        $this->assertSame('0', $this->balance(StandardChart::INSURANCE_CLAIM_RECEIVABLE), '⛔ নাকচের পরেও প্রাপ্য রইল।');
        $this->assertSame(0, bccomp($this->balance(StandardChart::INSURANCE_CLAIM_INCOME), '1000', 4));

        $this->expectException(ValidationException::class);
        $this->receive($claim->fresh(), '100');
    }

    public function test_only_the_insurance_managers_key_works_the_claim(): void
    {
        $policy = $this->policy('CL-K');

        $viewer = User::factory()->create(['is_active' => true, 'current_company_id' => CompanyContext::id()]);
        $viewer->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        CompanyContext::forCompany(CompanyContext::id(), fn () => $viewer->givePermissionTo(Permission::findOrCreate('finance.insurance.view', 'web')));

        $form = ['incident_on' => now()->toDateString(), 'claimed_on' => now()->toDateString(), 'incident' => 'Fire', 'claimed_amount' => '9000'];
        $this->actingAs($viewer)->post(route('finance.insurance.claim.store', $policy), $form)->assertForbidden();
        $this->assertSame(0, InsuranceClaim::query()->count());

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner)->post(route('finance.insurance.claim.store', $policy), $form)->assertSessionHasNoErrors()->assertRedirect();
        $claim = InsuranceClaim::query()->firstOrFail();

        $this->actingAs($viewer)->get(route('finance.insurance.claim.show', $claim))->assertOk()->assertDontSee('data-claim-money-in', false);
        $this->actingAs($viewer)->post(route('finance.insurance.claim.receive', $claim), [
            'money_account_id' => $this->bank->id, 'amount' => '100', 'received_on' => now()->toDateString(), 'instrument_no' => 'X',
        ])->assertForbidden();

        $this->actingAs($owner)->get(route('finance.insurance.claim.show', $claim))->assertOk()->assertSee('data-claim-money-in', false);
        $this->actingAs($owner)->post(route('finance.insurance.claim.receive', $claim), [
            'money_account_id' => $this->bank->id, 'amount' => '9000', 'received_on' => now()->toDateString(), 'instrument_no' => 'TST-R9',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(InsuranceClaim::SETTLED, $claim->fresh()->status);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    private function receive(InsuranceClaim $claim, string $amount): Voucher
    {
        $done = $this->claims->receive($claim, [
            'money_account_id' => $this->bank->id, 'amount' => $amount, 'received_on' => now()->toDateString(),
            'instrument_no' => 'TST-'.$claim->id.'-'.str_replace('.', '', $amount).'-'.random_int(1, 999999),
        ]);
        $this->assertFalse($done['held'], 'দাবির ভিত্তি নেই — রসিদ সইয়ে আটকাল।');

        return $done['voucher'];
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function register(array $f = []): \Illuminate\Support\Collection
    {
        return collect(app(ReportEngine::class)->run(InsuranceReports::CLAIMS,
            ['from' => now()->subMonth()->toDateString(), 'to' => now()->toDateString(), ...$f], perPage: 100)->rows)
            ->map(fn ($r) => (array) $r);
    }

    private function balance(string $code): string
    {
        $id = StandardChart::find($code)->id;
        $row = LedgerEntry::query()->where('account_id', $id)->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();
        $net = $code === StandardChart::INSURANCE_CLAIM_INCOME
            ? bcsub((string) $row->c, (string) $row->d, 4)
            : bcsub((string) $row->d, (string) $row->c, 4);

        return bccomp($net, '0', 4) === 0 ? '0' : $net;
    }

    private function policy(string $no): InsurancePolicy
    {
        $insurer = Institution::query()->create([
            'company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Insurer '.$no,
        ]);

        return app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => $no, 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '4500',
            'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonths(10)->toDateString(),
        ]);
    }

    private function lodge(string $amount, string $no = 'CL-1'): InsuranceClaim
    {
        return $this->claims->lodge($this->policy($no), [
            'incident_on' => now()->subDays(3)->toDateString(), 'claimed_on' => now()->subDays(2)->toDateString(),
            'incident' => 'Godown water damage', 'claimed_amount' => $amount,
        ]);
    }

    private function bankLeaf(): Account
    {
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();
        $bank = $till->replicate(['public_id']);
        $bank->forceFill(['code' => 'TST-CLB', 'name_en' => 'Claim bank', 'name_bn' => 'Claim bank', 'money_kind' => Account::BANK,
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')])->save();

        return $bank;
    }
}
