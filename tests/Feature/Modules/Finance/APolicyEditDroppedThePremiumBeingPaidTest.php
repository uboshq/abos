<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePremium;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ পলিসি সম্পাদনায় যে কিস্তির পরিশোধ ভাউচার সইয়ের অপেক্ষায়, সেটা মুছে নতুন কিস্তি বসত — শেষ সইয়ে ভাউচার "অজানা কাগজ"-এ
 * আটকাত আর নতুন কিস্তি "বাকি" দেখাত (লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[InsuranceService::update()]])।
 *
 * ⭐ দাবি: পরিশোধ ভাউচার (খসড়াও) ধরে থাকা কিস্তি সম্পাদনায় থাকে, আর ভাউচারটা পরে খাতায় বসে কিস্তিটাকেই দেওয়া করে।
 */
final class APolicyEditDroppedThePremiumBeingPaidTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
    }

    public function test_a_premium_with_a_payment_on_the_way_survives_a_policy_edit(): void
    {
        $insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Insurer']);
        $terms = [
            'institution_id' => $insurer->id, 'policy_no' => 'POL-EDIT', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '500000', 'premium' => '12000', 'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addMonths(11)->toDateString(),
        ];
        $policy = app(InsuranceService::class)->create($terms);
        $premium = $policy->premiums()->sole();

        $cash = Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();
        $this->putMoneyIn($cash, '12000', now()->toDateString());
        $vouchers = app(VoucherService::class);
        $voucher = $vouchers->create([
            'type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(), 'narration' => 'premium',
            'against_type' => InsurancePremium::drillSourceType(), 'against_id' => $premium->id,
        ], [
            ['account_id' => Account::query()->where('code', StandardChart::INSURANCE_PREMIUM)->firstOrFail()->id, 'debit' => '12000', 'credit' => '0'],
            ['account_id' => $cash->id, 'debit' => '0', 'credit' => '12000'],
        ]);
        $this->assertTrue($voucher->isDraft(), 'দৃশ্যটাই বানানো যায়নি — পরিশোধ খসড়ায় নেই');

        app(InsuranceService::class)->update($policy->fresh(), ['subject' => 'Stock and godown'] + $terms);

        $this->assertNotNull(InsurancePremium::query()->find($premium->id), '⛔ পরিশোধের পথে থাকা কিস্তি মুছে গেল');

        $vouchers->post($voucher->fresh());
        $this->assertSame(InsurancePremium::POSTED, $premium->fresh()->status, '⛔ পরিশোধ খাতায় বসল, অথচ কিস্তি "দেওয়া" হল না');
    }
}
