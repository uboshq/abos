<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\InterestAccrual;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\InterestAccrualService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ CC-র খোলার দিনের "তোলা" দুবার গোনা হত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (⚠️১)।
 *
 * ⓘ CC-র দেনা ব্যাংক হিসাবের নিজের জেরেই থাকে ([[BankFacilityService::openingFor()]] তাই CC-র খোলা দাখিলা বসায় না)।
 * অথচ ফর্মে CC-র `opening_drawn` নেওয়া হত, আর [[BankFacilityService::standing()]] ও [[BankFacilityService::legacyOpening()]]
 * ওটা পুরো ব্যাংক জেরের ওপর যোগ করত — ব্যাংকে Cr ৫ লাখ, ফর্মে ৫ লাখ ⇒ পর্দায় "ব্যবহৃত ১০ লাখ", আর মাসের সুদ দ্বিগুণ।
 *
 * ⭐ দাবি:
 *   · ফর্মে CC-র শূন্য-নয় `opening_drawn` থামে, কারণসহ; কোনো সুবিধা বসে না
 *   · পুরনো CC সারিতে লেখা `opening_drawn` থাকলেও ব্যবহৃত = ব্যাংক জের মাত্র; সুদও তার ওপর
 */
final class ACashCreditsOpeningDrawnWasCountedTwiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_the_form_refuses_a_drawn_figure_on_a_cash_credit(): void
    {
        $bank = $this->bankLeaf();

        $this->post(route('finance.bank_facility.store'), [
            'kind' => BankFacility::CC, 'institution_new' => 'Janata Bank', 'sanctioned_on' => '2026-07-01',
            'limit_amount' => '1000000', 'interest_rate' => '12', 'money_account_id' => $bank->id,
            'stock_value' => '2000000', 'margin_percent' => '25', 'opening_drawn' => '500000',
        ])->assertSessionHasErrors('opening_drawn');

        $this->assertSame(0, BankFacility::query()->where('kind', BankFacility::CC)->count(),
            '⛔ CC-র খোলার দিনের তোলা নেওয়া হলো — ব্যাংকের জেরের সাথে দ্বিতীয়বার গোনা হত।');

        // ⓘ শূন্য বা খালি — চলে, আগের মতো
        $this->post(route('finance.bank_facility.store'), [
            'kind' => BankFacility::CC, 'institution_new' => 'Janata Bank', 'sanctioned_on' => '2026-07-01',
            'limit_amount' => '1000000', 'interest_rate' => '12', 'money_account_id' => $bank->id,
            'stock_value' => '2000000', 'margin_percent' => '25', 'opening_drawn' => '0',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_old_cash_credit_row_counts_only_the_bank_balance(): void
    {
        $bank = $this->bankLeaf();
        $cc = app(BankFacilityService::class)->open([
            'kind' => BankFacility::CC, 'bank' => 'Janata Bank', 'sanctioned_on' => '2026-07-01',
            'limit_amount' => '1000000', 'interest_rate' => '12', 'money_account_id' => $bank->id,
            'stock_value' => '2000000', 'margin_percent' => '25',
        ]);

        // ⓘ আজকের আগের নিয়মে খোলা সারি — হাতে লেখা ৫ লাখ, আর ব্যাংক হিসাবের খোলা জেরেও Cr ৫ লাখ
        $cc->forceFill(['opening_drawn' => '500000'])->save();
        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => '2026-07-01', 'narration' => 'cc opening', 'instrument_no' => 'TST-CC-OPEN',
        ], [
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => '500000', 'credit' => '0'],
            ['account_id' => $bank->id, 'debit' => '0', 'credit' => '500000'],
        ]));

        $service = app(BankFacilityService::class);
        $cc = $cc->fresh();

        $this->assertSame(0, bccomp($service->standing(collect([$cc]))[$cc->id]['used'], '500000', 4),
            '⛔ CC-র ব্যবহৃত অঙ্কে লেখা opening_drawn ব্যাংকের জেরের ওপর আবার যোগ হলো — এল '.$service->standing(collect([$cc]))[$cc->id]['used']);
        $this->assertSame(0, bccomp($service->legacyOpening($cc), '0', 4), '⛔ CC-র খাতার খোলা জেরে লেখা opening_drawn যোগ হলো।');
        $this->assertSame(0, bccomp($service->owedOn($cc, '2026-08-15'), '500000', 4), '⛔ CC-র দিনের দেনা দ্বিগুণ।');

        app(InterestAccrualService::class)->run(Carbon::parse('2026-08-01'));
        $accrual = InterestAccrual::query()->where('bank_facility_id', $cc->id)->firstOrFail();

        // ⓘ ৩১ দিন × ৫ লাখ × ১২% ÷ ৩৬৫ ≈ ৫০৯৫.৮৯ — পয়সার গোলের ফারাক এক পয়সা পর্যন্ত চলে
        $right = bcdiv(bcmul(bcmul('500000', '12', 8), '31', 8), '36500', 4);
        $gap = bcsub(bcadd((string) $accrual->amount, '0', 4), $right, 4);
        $this->assertTrue(bccomp(ltrim($gap, '-'), '0.0100', 4) <= 0,
            "⛔ CC-র আগস্টের সুদ {$accrual->amount}, হওয়া উচিত ≈ {$right} — দ্বিগুণ দেনার ওপর বসল।");
    }

    private function bankLeaf(): Account
    {
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();
        $bank = $till->replicate(['public_id']);
        $bank->forceFill(['code' => 'TST-CC', 'name_en' => 'CC account', 'name_bn' => 'CC account', 'money_kind' => Account::BANK,
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')])->save();

        return $bank;
    }
}
