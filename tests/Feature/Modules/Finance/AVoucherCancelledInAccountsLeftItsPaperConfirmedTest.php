<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Models\RentalAdjustment;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * Accounts থেকে ভাউচার বাতিল, অথচ অর্থের কাগজ "নিশ্চিত" — পুরো-ERP অডিট, অর্থ M22, ১০ অক্টোবর ২০২৬।
 *
 * ⛔ উত্তোলন, লাভের ভাগ আর ভাড়ার মাসের ভাউচার ভাউচারের পর্দা থেকে বাতিল করলে খাতা উল্টাত, কাগজ থাকত আগের অবস্থায় — উত্তোলন
 * মাসের গোনায়, লাভের ভাগ "ঘোষিত"-এ, ভাড়ার মাস "দেওয়া" আর জামানত কাটা।
 * ⭐ এখন একই লেনদেনে কাগজও ফেরে ([[UndoThePaperOfACancelledVoucher]]): উত্তোলন খসড়া, ভাগ বাতিল, ভাড়ার মাস সরে যায়।
 */
final class AVoucherCancelledInAccountsLeftItsPaperConfirmedTest extends TestCase
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
        $this->putMoneyIn($this->cash(), '1000000', now()->startOfMonth()->subMonths(3)->toDateString());
    }

    public function test_a_cancelled_withdrawal_voucher_takes_the_withdrawal_back_to_draft(): void
    {
        $withdrawal = app(WithdrawalService::class)->request([
            'person_id' => $this->person('Cancel Owner')->id, 'amount' => '7000', 'trx_date' => now()->toDateString(),
        ]);
        $posted = app(WithdrawalService::class)->post($withdrawal->fresh(), $this->cash());
        $this->assertSame(DocumentStatus::CONFIRMED, $posted->status, 'দৃশ্যটাই বানানো যায়নি — উত্তোলন পোস্ট হয়নি');

        app(VoucherService::class)->cancel(Voucher::acrossBranches()->findOrFail($posted->voucher_id), 'ভুল উত্তোলন');

        $after = $posted->fresh();
        $this->assertSame(DocumentStatus::DRAFT, $after->status, '⛔ ভাউচার বাতিল, উত্তোলন এখনো নিশ্চিত — মাসের গোনায় থেকে গেল');
        $this->assertNull($after->voucher_id, '⛔ উত্তোলন এখনো বাতিল ভাউচারে বাঁধা');
    }

    public function test_a_cancelled_profit_voucher_cancels_the_shares(): void
    {
        $this->earn('100000');
        app(CapitalService::class)->post(app(CapitalService::class)->record([
            'person_id' => $this->person('Cancel Partner')->id, 'contributor_type' => CapitalEntry::OWNER,
            'entry_type' => CapitalEntry::CONTRIBUTION, 'trx_date' => now()->subDay()->toDateString(), 'amount' => '50000',
        ]), $this->cash());

        $shares = app(ProfitDistribution::class)->declare(['profit' => '10000', 'trx_date' => now()->toDateString()]);
        $this->assertNotEmpty($shares, 'দৃশ্যটাই বানানো যায়নি — কোনো ভাগ বসেনি');
        $voucherId = (int) $shares[0]->voucher_id;
        $this->assertSame([ProfitShare::POSTED], ProfitShare::query()->where('voucher_id', $voucherId)->distinct()->pluck('status')->all(),
            'দৃশ্যটাই বানানো যায়নি — ভাগগুলো পোস্ট হয়নি');

        app(VoucherService::class)->cancel(Voucher::acrossBranches()->findOrFail($voucherId), 'ভুল ঘোষণা');

        $this->assertSame([ProfitShare::CANCELLED], ProfitShare::query()->where('voucher_id', $voucherId)->distinct()->pluck('status')->all(),
            '⛔ ঘোষণার ভাউচার বাতিল, অথচ ভাগগুলো এখনো "পোস্ট" — লাভ ভাগ হয়েছে বলে গোনা চলত');
    }

    public function test_a_cancelled_rent_month_voucher_takes_the_month_and_the_deposit_cut_back(): void
    {
        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Cancel Landlord', 'subject' => 'Cancel godown', 'deposit_amount' => '30000', 'monthly_rent' => '10000',
            'monthly_adjustment' => '0', 'term_months' => 12, 'starts_on' => now()->startOfMonth()->subMonths(2)->toDateString(), 'rent_day' => 5,
            'money_account_id' => $this->cash()->id,
        ]);
        $month = app(RentalContractService::class)->adjustMonth($contract->fresh(), [
            'for_month' => now()->startOfMonth()->subMonth()->toDateString(), 'rent' => '10000', 'from_deposit' => '4000',
            'money_account_id' => $this->cash()->id,
        ]);
        $this->assertSame('26000', bcadd($contract->fresh()->depositLeft(), '0', 0), 'দৃশ্যটাই বানানো যায়নি — জামানত কাটা হয়নি');

        app(VoucherService::class)->cancel(Voucher::acrossBranches()->findOrFail($month->voucher_id), 'ভুল মাস');

        $this->assertNull(RentalAdjustment::query()->find($month->id), '⛔ মাসের ভাউচার বাতিল, অথচ মাসটা এখনো "দেওয়া"');
        $this->assertSame('30000', bcadd($contract->fresh()->depositLeft(), '0', 0), '⛔ বাতিল মাসের জামানত-কাটা এখনো গোনা হচ্ছে');

        // ⓘ মাসটা আবার দেওয়া যায়
        app(RentalContractService::class)->adjustMonth($contract->fresh(), [
            'for_month' => now()->startOfMonth()->subMonth()->toDateString(), 'rent' => '10000', 'from_deposit' => '0',
            'money_account_id' => $this->cash()->id,
        ]);
        $this->assertSame(1, RentalAdjustment::query()->where('rental_contract_id', $contract->id)->count());
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function earn(string $amount): void
    {
        app(PostingEngine::class)->post(sourceType: 'test:earned', sourceId: random_int(1, 999999), trxDate: now()->subDays(2)->toDateString(), lines: [
            ['account_id' => $this->cash()->id, 'debit' => $amount],
            ['account_id' => StandardChart::find(StandardChart::RETAINED_EARNINGS)->id, 'credit' => $amount],
        ]);
    }

    private function person(string $name): Person
    {
        return Person::query()->firstOrCreate(
            ['company_id' => CompanyContext::id(), 'name_en' => $name],
            ['code' => 'P-'.mb_substr(md5($name), 0, 6), 'name_bn' => $name, 'is_active' => true],
        );
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();
    }
}
