<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Services\BankFacilityService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ব্যাংক বলত কত তোলা হয়েছে, খাতা কিছুই বলত না।
 *
 * ── ⓘ অর্থের মানচিত্র §১৪গ, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────
 * *"ব্যবহৃত অঙ্ক ও বকেয়া"* লাইনটার টীকাই নিয়মটা বলে দিয়েছিল: সংখ্যাটা
 * **খতিয়ানে থাকে, সারিতে দ্বিতীয় কপি নয়**। ⓘ তাই সুবিধার তালিকা এখন
 * খতিয়ান থেকে গুনে দেখায় কত তোলা হয়েছে আর সীমার কতটা বাকি।
 *
 * ── ⚠️ দুই ধরনের সুবিধায় দেনাটা দুই জায়গায় ───────────────────────────
 * মেয়াদি ঋণে নিজের দায়ের খাত (ক্রেডিটে বাড়ে); CC-তে আলাদা খাত নেই,
 * দেনাটা ব্যাংক হিসাবের ঋণাত্মক জের।
 */
final class TheBankSaidDrawnAndTheBookSaidNothingTest extends TestCase
{
    use RefreshDatabase;

    private BankFacilityService $service;

    private int $bank;

    private int $liability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->service = app(BankFacilityService::class);

        /*
         * ⚠️ গ্রুপ খাত নয় — গ্রুপে দাখিলা বসে না, আর বসাতে গেলে ভাউচার
         * থামায়। ⓘ ব্যাংকের একটা সত্যিকারের পাতা-খাত এখানেই বানানো,
         * কারণ ডেমো ছকে কেবল মাথাটা (১১০২) থাকে।
         */
        $head = Account::query()->where('code', StandardChart::BANK)->firstOrFail();

        $this->bank = (int) Account::query()->create([
            'parent_id' => $head->id,
            'code' => '110291',
            'name_en' => 'Facility Test Bank A/C',
            'type' => $head->type,
            'nature' => $head->nature,
            'is_group' => false,
            'money_kind' => Account::BANK,
        ])->id;

        $this->liability = (int) Account::query()->postable()
            ->where('type', Account::LIABILITY)->value('id');
    }

    /**
     * ⭐ মেয়াদি ঋণ — দায়ের খাতে যা বসেছে, ততটাই তোলা।
     */
    public function test_a_term_loan_reads_its_liability_account(): void
    {
        $facility = $this->service->open([
            'kind' => BankFacility::TERM,
            'bank' => 'Islami Bank Bangladesh PLC',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '500000.0000',
            'liability_account_id' => $this->liability,
            'instalments' => 12,
        ]);

        // ⓘ ব্যাংক ৩ লাখ ছাড় করল: Dr ব্যাংক / Cr দায়
        $this->post300k();

        $standing = $this->service->standing(collect([$facility]));

        $this->assertSame(0, bccomp($standing[$facility->id]['used'], '300000', 4),
            'দায়ের খাতে বসা টাকাটা "ব্যবহৃত" হিসেবে আসেনি।');

        $this->assertSame(0, bccomp($standing[$facility->id]['left'], '200000', 4),
            'বাকি সীমা সীমা − ব্যবহৃত হওয়ার কথা।');
    }

    /**
     * ⭐ CC — ব্যাংক হিসাবের ঋণাত্মক জেরই দেনা।
     *
     * ⛔ খরচের মতো `credit − debit` না উল্টে নিলে CC-র ব্যবহৃত অঙ্ক
     * ঋণাত্মক দেখাত, আর বাকি সীমা সীমার চেয়েও বেশি।
     */
    public function test_a_cash_credit_reads_the_overdrawn_bank(): void
    {
        $facility = $this->service->open([
            'kind' => BankFacility::CC,
            'bank' => 'Islami Bank Bangladesh PLC',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '500000.0000',
            'money_account_id' => $this->bank,
            'stock_value' => '900000.0000',
            'margin_percent' => '30.00',
        ]);

        // ⓘ ব্যাংক থেকে ২ লাখ খরচে গেল — হিসাবটা ঋণাত্মক হলো
        $vouchers = app(VoucherService::class);
        // ⓘ সক্রিয় খাত — নিষ্ক্রিয়তে নতুন লেনদেন বসে না, আর ডেমো ছকে পুরনো খাতও আছে
        $expense = (int) Account::query()->postable()->active()
            ->where('type', Account::EXPENSE)->value('id');

        $vouchers->post($vouchers->create(
            ['type' => 'expense', 'trx_date' => now()->toDateString(),
                'narration' => 'seed', 'instrument_no' => 'CC-DRAW-1'],
            $vouchers->twoLineEntry('expense', $this->bank, $expense, '200000.00', 'seed'),
        ));

        $standing = $this->service->standing(collect([$facility]));

        $this->assertSame(0, bccomp($standing[$facility->id]['used'], '200000', 4),
            'CC-র তোলা টাকাটা ব্যাংকের ঋণাত্মক জের থেকে গোনা হয়নি।');
    }

    /**
     * ⛔ গ্যারান্টিতে টাকা তোলাই হয় না — ব্যবহৃত শূন্য, "—" নয় ভুল সংখ্যা।
     */
    public function test_a_guarantee_draws_nothing(): void
    {
        $bg = $this->service->open([
            'kind' => BankFacility::GUARANTEE,
            'bank' => 'Islami Bank Bangladesh PLC',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '200000.0000',
            'margin_percent' => '15.00',
        ]);

        $standing = $this->service->standing(collect([$bg]));

        $this->assertSame(0, bccomp($standing[$bg->id]['used'], '0', 4));
        $this->assertSame(0, bccomp($standing[$bg->id]['left'], '200000', 4));
    }

    /**
     * ⭐ পর্দাতেও সংখ্যাগুলো — তালিকা খুললেই দেখা যায়।
     */
    public function test_the_list_shows_used_and_left(): void
    {
        $this->service->open([
            'kind' => BankFacility::TERM,
            'bank' => 'Islami Bank Bangladesh PLC',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '500000.0000',
            'liability_account_id' => $this->liability,
            'instalments' => 12,
        ]);

        $this->post300k();

        $page = $this->get(route('finance.bank_facility.index'))->assertOk();

        $page->assertSee(__('finance::field.facility_used'));
        $page->assertSee(\App\Core\Support\Money::format('300000'));
        $page->assertSee(\App\Core\Support\Money::format('200000'));
    }

    /**
     * ব্যাংক তিন লাখ ছাড় করল — Dr ব্যাংক / Cr দায়।
     *
     * ⓘ `twoLineEntry`-তে "to" ডেবিট, "from" ক্রেডিট — টাকা যেখানে গেল
     * সেটা বাড়ে। ⚠️ ব্যাংক ছুঁলে লেনদেনের নম্বর লাগে, নাহলে পোস্ট থামে
     * (একই লেনদেন দুইবার খাতায় ওঠা আটকানোর পাহারা)।
     */
    private function post300k(): void
    {
        $vouchers = app(VoucherService::class);

        $vouchers->post($vouchers->create(
            ['type' => 'journal', 'trx_date' => now()->toDateString(),
                'narration' => 'ঋণ ছাড়', 'instrument_no' => 'LOAN-DRAW-1'],
            $vouchers->twoLineEntry('journal', $this->liability, $this->bank, '300000.00', 'ঋণ ছাড়'),
        ));
    }
}
