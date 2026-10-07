<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\RentalContractService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * সারিটার কোনো নম্বর ছিল না, আর কেউ ওটার নাম বলতে পারত না।
 *
 * ── ⛔ কী পাওয়া গেছে, ১৫ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * লোকালে একটা ভাড়ার চুক্তি জমা দিয়ে সারিটা পড়ে দেখা গেল
 * `document_no` **NULL**। ⓘ কলামটা `fillable`-এ ছিল, টেবিলে ছিল,
 * কিন্তু কোনো সার্ভিস কোনোদিন নম্বর চায়নি।
 *
 * ⚠️ ফল কাগজে: বাড়িওয়ালা ফোন করে বললেন *"আমার চুক্তিটা দেখেন"*, আর
 * উত্তরে বলার মতো কিছু নেই — আইডি কাগজে ছাপা থাকে না।
 *
 * ── ⛔ আর ব্যাংক সুবিধারটা আমার নিজের, আর সেটা লুকানো ছিল ────────────
 * `drillDocumentNo()` লিখেছিলাম `document_no ?? sanction_no ?? id`।
 * ⚠️ ফলব্যাকটা কাজ করত, তাই **কিছুই ভাঙত না**, আর প্রথম ঘরটা যে
 * কোনোদিন ভরত না সেটা ধরাও পড়ত না।
 *
 * ⭐ এই ফাইলের দাবি তাই সরল: **সারি বসলে নম্বর বসে।** ফলব্যাক নয়,
 * আসল নম্বর।
 *
 * ── ⛔ এই ফাইলটা একবারও চালানো হয়নি, ১৬ সেপ্টেম্বর ২০২৬ ─────────────
 * লেখার রাতে এই ল্যাপটপে নয়টা phpunit একসাথে চলছিল, আর `RefreshDatabase`-এর
 * `migrate:fresh` মাঝপথে থেমে যাচ্ছিল (একটা টেবিলে দুই মিনিট)। ⓘ abos-e8-এর
 * সুইটেও তিনবার হুবহু একই ৩১০টা লাল এসেছে, আর লাইভে ঐ একই মাইগ্রেশন
 * ৩১ মিলিসেকেন্ডে চলেছে — অর্থাৎ কোড নয়, মেশিন।
 *
 * ⚠️ তাই **সকালে লাল দেখলে প্রথমে ধরে নিও পাহারাটারই ভুল**, কোড ভাঙেনি।
 * ⭐ প্রথম আসল রান Mac Mini-তে।
 */
final class TheRowHadNoNumberAndNobodyCouldNameItTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($user);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
    }

    /**
     * ⭐ ভাড়ার চুক্তি নম্বর নিয়ে জন্মায়।
     */
    public function test_a_rental_contract_is_born_with_a_number(): void
    {
        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Landlord One',
            'subject' => 'Godown',
            'deposit_amount' => '200000',
            'monthly_rent' => '25000',
            'starts_on' => now()->toDateString(),
            'term_months' => 24,
        ]);

        $this->assertNotNull($contract->document_no,
            'ভাড়ার চুক্তি নম্বর ছাড়াই বসেছে — বাড়িওয়ালাকে বলার মতো কিছু থাকবে না।');

        $this->assertStringStartsWith('RNT', $contract->document_no);
    }

    /**
     * ⭐ ব্যাংক সুবিধাও — আর এখানে ফলব্যাকটা যেন না ঢাকে।
     *
     * ⚠️ `sanction_no` **ইচ্ছাকৃতভাবে খালি** রাখা হয়েছে। ⓘ ওটা ভরা
     * থাকলে `drillDocumentNo()` ওটাই ফেরাত, আর `document_no` খালি
     * থাকলেও দাবিটা সবুজ দেখাত — ঠিক যেভাবে বাগটা এতদিন লুকিয়ে ছিল।
     */
    public function test_a_bank_facility_is_born_with_a_number(): void
    {
        $facility = app(BankFacilityService::class)->open([
            'kind' => BankFacility::CC,
            'bank' => 'Islami Bank Bangladesh PLC',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '5000000',
            'stock_value' => '8000000',
            'margin_percent' => '30',
            'money_account_id' => $this->moneyAccountId(),
        ]);

        $this->assertNotNull($facility->document_no,
            'ব্যাংক সুবিধা নম্বর ছাড়াই বসেছে।');

        $this->assertStringStartsWith('BFC', $facility->document_no);

        $this->assertSame($facility->document_no, $facility->drillDocumentNo(),
            'ড্রিলের নম্বরটা আসল নম্বর নয় — ফলব্যাক ঢেকে দিচ্ছে।');
    }

    /**
     * ⛔ দুইটা নাম `module.php`-তে ঘোষিত থাকতে হবে।
     *
     * ⓘ [[NumberSeriesEngine]] অঘোষিত ধরনে সিরিজ বসায় না, আর তখন
     * নম্বরটা নীরবে `null` থেকে যেত — ঠিক যা সারানো হলো।
     */
    public function test_the_two_document_types_are_declared(): void
    {
        $declared = require base_path('app/Modules/Finance/module.php');

        foreach (['RNT', 'BFC'] as $type) {
            $this->assertArrayHasKey($type, $declared['doc_types'],
                "{$type} module.php-এর doc_types-এ নেই — সিরিজ বসবে না।");
        }
    }

    /**
     * ⭐ পাঁচটা খাতার নীতি সত্যিই খুঁজে পাওয়া যায়।
     *
     * ── ⛔ কেন এটা আলাদা করে মাপা ────────────────────────────────────
     * Laravel নীতি খোঁজে `Models` → `Policies` ছাঁচ ধরে। ⚠️ নীতি না
     * থাকলে উত্তর **নীরবে `false`** — কোনো ভুল ওঠে না, শুধু বোতামটা
     * থাকে না। ⓘ কাগজপত্রের কার্ডে ঠিক সেটাই ঘটেছিল: কার্ড আসত,
     * ফাইল তোলার ঘর আসত না, মালিকের জন্যও নয়।
     */
    public function test_every_finance_book_has_a_policy(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        foreach ([CapitalEntry::class, Deposit::class, HandLoanAccount::class,
            RentalContract::class, BankFacility::class] as $model) {

            $policy = Gate::getPolicyFor($model);

            $this->assertNotNull($policy,
                "{$model}-এর কোনো নীতি নেই — `can('create', …)` নীরবে না বলবে।");

            $this->assertTrue($owner->can('create', $model),
                "মালিক {$model} তৈরি করতে পারছেন না — কাগজ তোলার ঘরটাও আসবে না।");
        }
    }

    private function moneyAccountId(): int
    {
        return (int) Account::query()
            ->money()->where('is_group', false)->value('id');
    }
}
