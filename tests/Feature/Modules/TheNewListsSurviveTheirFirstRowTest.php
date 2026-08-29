<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\BankReconciliationService;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Services\DepositClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নতুন তালিকাগুলো তাদের প্রথম সারিটা সয়ে নেয়।
 *
 * ── কেন এই পরীক্ষাটা আলাদা করে দরকার ────────────────────────────────
 * ২০ আগস্ট পাঁচটা নতুন পর্দা লেখা হয়েছিল `x-ui.table`-এর ভেতরে হাতে
 * `<tr>` বসিয়ে। কম্পোনেন্ট স্লট পড়ে না, আর কলামে `key` চায়।
 *
 * প্রতিটা পর্দায় ছিল `@if ($rows->isEmpty()) … @else <x-ui.table>`।
 * ডেমো ডাটায় তালিকাগুলো খালি, তাই `@else` শাখাটা **কখনো চলেনি**।
 * পর্দা খুলত, শিরোনাম দেখাত, লাইভে ছবিও তোলা গিয়েছিল — আর ভাঙার কথা
 * ছিল প্রথম সারিটা তৈরি হওয়ার মুহূর্তে, ৫০০ হয়ে।
 *
 * অর্থাৎ **"পাতাটা খোলে" যথেষ্ট প্রমাণ নয়**। খালি তালিকা আর ভরা
 * তালিকা দুইটা আলাদা পথ, আর ভুলটা ছিল দ্বিতীয়টায়।
 *
 * এই ফাইলটা তাই প্রতিটা নতুন তালিকায় **একটা সারি বসিয়ে** পাতাটা
 * খোলে, আর সারির ভেতরের লেখাটা সত্যিই ছাপা হয়েছে কি না দেখে।
 */
class TheNewListsSurviveTheirFirstRowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-FIRSTROW',
            'name_en' => 'City Bank',
            'name_bn' => 'সিটি ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'is_bank' => true,
        ]);
    }

    public function test_the_reconciliation_list_survives_its_first_row(): void
    {
        app(BankReconciliationService::class)->open([
            'bank_account_id' => $this->bank->id,
            'statement_date' => '2026-08-31',
            'statement_balance' => '125000',
        ]);

        $this->get(route('accounts.reconciliation.index'))
            ->assertOk()
            ->assertSee('1102-FIRSTROW');
    }

    /**
     * কাজের পর্দাটাও তার প্রথম **লাইন** সয়ে নেয়।
     *
     * এখানে সত্যিই একটা পাশ করা ভাউচার বসানো হয়, নাহলে তালিকাটা খালি
     * থেকে যেত আর পরীক্ষাটা ঠিক সেই পথটাই এড়িয়ে যেত যেখানে ভুল ছিল।
     */
    public function test_the_reconciliation_page_survives_its_first_line(): void
    {
        $vouchers = app(VoucherService::class);
        $cash = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1101-FIRSTROW',
            'name_en' => 'Main till',
            'name_bn' => 'প্রধান ক্যাশ',
            'parent_id' => StandardChart::find(StandardChart::CASH_IN_HAND)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'is_cash' => true,
        ]);

        $voucher = $vouchers->create(
            [
                'type' => Voucher::CONTRA,
                'trx_date' => '2026-08-10',
                'instrument' => 'transfer',
                'instrument_no' => 'REF-FIRSTROW',
            ],
            $vouchers->twoLineEntry(Voucher::CONTRA, $cash->id, $this->bank->id, '50000'),
        );
        $vouchers->post($voucher);

        $recon = app(BankReconciliationService::class)->open([
            'bank_account_id' => $this->bank->id,
            'statement_date' => '2026-08-31',
            'statement_balance' => '50000',
        ]);

        /*
         * কাগজের নম্বর ধরে দেখা, লেনদেন নম্বর ধরে নয়।
         *
         * পর্দাটা `document_no` ছাপে — `instrument_no` নয়, আর সেটাই
         * ঠিক: মিলকরণের সময় মানুষ খাতার কাগজ খোঁজেন, ব্যাংকের
         * রেফারেন্স নয়।
         */
        $this->get(route('accounts.reconciliation.show', $recon))
            ->assertOk()
            ->assertSee($voucher->document_no);
    }

    public function test_the_asset_list_survives_its_first_row(): void
    {
        $this->asset();

        $this->get(route('accounts.asset.index'))
            ->assertOk()
            ->assertSee('First row van');
    }

    public function test_the_asset_page_survives_its_first_depreciation(): void
    {
        $asset = $this->asset();
        app(FixedAssetService::class)->depreciate($asset, '2026-08-31');

        $this->get(route('accounts.asset.show', $asset))
            ->assertOk()
            ->assertSee('Aug 2026');
    }

    public function test_the_deposit_claim_list_survives_its_first_row(): void
    {
        $dealer = Customer::query()->firstOrFail();

        app(DepositClaimService::class)->raise($dealer, [
            'claimed_on' => '2026-08-10',
            'amount' => '50000',
            'method' => DepositClaim::BANK,
            'reference' => 'TRX-FIRSTROW',
            'bank_account_id' => $this->bank->id,
        ]);

        $this->get(route('sales.claim.index'))
            ->assertOk()
            ->assertSee('TRX-FIRSTROW');
    }

    private function asset(): FixedAsset
    {
        return app(FixedAssetService::class)->register([
            'name' => 'First row van',
            'asset_account_id' => Account::query()->where('code', '1202')->value('id'),
            'accumulated_account_id' => Account::query()
                ->where('code', StandardChart::ACCUMULATED_DEPRECIATION)->value('id'),
            'expense_account_id' => Account::query()
                ->where('code', StandardChart::DEPRECIATION_EXPENSE)->value('id'),
            'cost' => '600000',
            'salvage' => '0',
            'acquired_on' => '2026-08-01',
            'method' => FixedAsset::STRAIGHT_LINE,
            'life_months' => 60,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }
}
