<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "সংরক্ষণ ব্যর্থ" বলে একটা খসড়া রেখে যাওয়া।
 *
 * ── HP-র রিপোর্ট, ২৯ আগস্ট ২০২৬ ─────────────────────────────────────
 * জাবেদা ভাউচারে একটা ব্যাংক/MFS খাত ছুঁয়ে "সংরক্ষণ ও পোস্ট" চাপলে
 * পর্দা একটা ভুল-বার্তা দেখাত — কিন্তু **তালিকায় গিয়ে দেখা যেত
 * ভাউচারটা খসড়া হিসেবে সেভ হয়ে গেছে**।
 *
 * ── কেন এটা কেবল বিভ্রান্তি নয় ──────────────────────────────────────
 * ব্যবহারকারী বার্তাটা পড়ে ধরে নেন কিছুই হয়নি, তাই আবার চেষ্টা করেন।
 * আর প্রতিবার আরেকটা অসম্পূর্ণ খসড়া জমা হয় — দিনের শেষে একগাদা
 * ভাউচার যেগুলো কোনো হিসাবে নেই, আর কেউ জানে না কোনটা ভুলে যাওয়া আর
 * কোনটা ইচ্ছাকৃত।
 *
 * পর্দাটার নিজের নিয়মই বলে খসড়া কেবল তখনই থাকবে যখন কেউ **"খসড়া
 * রাখুন"** চাপবেন। নীরবে তৈরি হওয়া খসড়া ওই নিয়মটাই ভাঙে।
 *
 * ── দুইটা আলাদা ভুল, দুইটাই সারানো ──────────────────────────────────
 * ১. জাবেদার ফর্মে ব্যাংকের লেনদেন নম্বরের ঘরই ছিল না — অর্থাৎ পর্দা
 *    এমন কিছু চাইত যা দেওয়ার জায়গা সে দেয়নি। ব্যাংক ছুঁলে
 *    "সংরক্ষণ ও পোস্ট" **প্রতিবারই** ব্যর্থ হত।
 * ২. সংরক্ষণ আর পোস্ট দুইটা আলাদা লেনদেনে ছিল, তাই পোস্ট আটকালেও
 *    সংরক্ষণটা রয়ে যেত।
 */
class ASaveThatFailedButLeftADraftBehindTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
    }

    /**
     * একটা ব্যাংক খাত — টাকার খাত, কিন্তু নগদ নয়।
     *
     * নিয়মটা ব্যাংকেই খাটে: নগদের কোনো TrxID নেই, তাই সেখানে নম্বর
     * চাওয়া হয় না।
     */
    private function bank(): Account
    {
        return Account::query()->firstOrCreate(
            ['code' => '1102-QA'],
            [
                'company_id' => $this->company->id,
                'parent_id' => StandardChart::find(StandardChart::BANK)?->id,
                'name_en' => 'QA Bank Account',
                'name_bn' => 'কিউএ ব্যাংক হিসাব',
                'type' => Account::ASSET,
                'nature' => Account::DEBIT,
                'is_bank' => true,
            ],
        );
    }

    private function other(): Account
    {
        return Account::query()->postable()->active()
            ->whereKeyNot($this->bank()->id)->orderBy('code')->firstOrFail();
    }

    /** @param  array<string, mixed>  $extra */
    private function submit(array $extra = []): TestResponse
    {
        return $this->post(route('accounts.voucher.store', 'journal'), array_merge([
            /*
             * ধরনটা ফর্মেও যায়, কেবল ঠিকানায় নয়।
             *
             * [[VoucherRequest]] ওটা দেখেই ঠিক করে কোন নিয়মগুলো
             * খাটবে -- জাবেদায় সারির তালিকা, বাকিগুলোয় দুই খাত আর
             * একটা অঙ্ক।
             */
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => 'ব্যাংক ছুঁয়ে একটা জাবেদা',
            'lines' => [
                ['account_id' => $this->bank()->id, 'debit' => '5000', 'credit' => '0'],
                ['account_id' => $this->other()->id, 'debit' => '0', 'credit' => '5000'],
            ],
        ], $extra));
    }

    /**
     * পোস্ট আটকালে কোনো খসড়াও থাকে না।
     *
     * এটাই HP-র ধরা ভুলটা, হুবহু।
     */
    public function test_a_failed_post_leaves_nothing_behind(): void
    {
        $this->assertSame(0, Voucher::query()->count(), 'পরীক্ষার শুরুতেই ভাউচার আছে।');

        $this->submit()->assertSessionHasErrors('instrument_no');

        $this->assertSame(0, Voucher::query()->count(),
            'সংরক্ষণ ব্যর্থ বলা হলো, অথচ একটা খসড়া রয়ে গেছে — '
            .'ব্যবহারকারী আবার চেষ্টা করলে আরেকটা জমা হবে।');
    }

    /**
     * বারবার চেষ্টা করলেও একগাদা খসড়া জমে না।
     *
     * উপরেরটা একবারের কথা বলে। আসল ক্ষতিটা হয় পুনরাবৃত্তিতে: বার্তাটা
     * বলে কিছু হয়নি, তাই মানুষ আবার চাপেন — আর সেটাই স্বাভাবিক।
     */
    public function test_trying_three_times_does_not_leave_three_drafts(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->submit()->assertSessionHasErrors('instrument_no');
        }

        $this->assertSame(0, Voucher::query()->count(),
            'তিনবার চেষ্টায় খসড়া জমেছে — ঠিক যে জিনিসটা এড়ানোর কথা।');
    }

    /**
     * নম্বরটা দিলে সেভ হয়, আর খাতায় বসে।
     *
     * ── কেন এটা লাগে ────────────────────────────────────────────────
     * উপরের দুইটা একা থাকলে "ব্যাংক ছুঁলে কিছুই সেভ হয় না" লিখেও সবুজ
     * থাকত — আর সেটা আগের অবস্থার চেয়েও খারাপ হত: অন্তত খসড়াটা
     * উদ্ধার করা যেত।
     */
    public function test_with_the_number_given_it_saves_and_posts(): void
    {
        $this->submit(['instrument_no' => 'TRX-QA-0001'])
            ->assertSessionHasNoErrors();

        $voucher = Voucher::query()->firstOrFail();

        $this->assertSame('TRX-QA-0001', $voucher->instrument_no);

        $this->assertTrue($voucher->isPosted(),
            'নম্বর দেওয়ার পরেও ভাউচারটা খসড়াই রয়ে গেছে।');
    }

    /**
     * "খসড়া রাখুন" চাপলে খসড়াই থাকে — নম্বর ছাড়াই।
     *
     * ── কেন এই ছাড়টা ঠিক ────────────────────────────────────────────
     * নম্বরটা লাগে **পোস্ট করার সময়**, কারণ পোস্ট করার মুহূর্তেই টাকা
     * সত্যিই নড়ে। খসড়া কোনো হিসাবে নেই, তাই সেখানে নম্বর চাওয়ার
     * কারণও নেই — আর চাইলে "পরে ভরব" বলে রেখে দেওয়ার পথটাই বন্ধ হত।
     *
     * পার্থক্যটা ইচ্ছার: এখানে ব্যবহারকারী নিজে খসড়া চেয়েছেন।
     */
    public function test_asking_for_a_draft_still_gives_a_draft(): void
    {
        $this->submit(['save_as_draft' => '1'])->assertSessionHasNoErrors();

        $voucher = Voucher::query()->firstOrFail();

        $this->assertFalse($voucher->isPosted(),
            '"খসড়া রাখুন" চেপেও ভাউচারটা পোস্ট হয়ে গেছে।');
    }

    /**
     * আর জাবেদার ফর্মে ঘরটা সত্যিই আছে।
     *
     * ── কেন এটার আলাদা পরীক্ষা ──────────────────────────────────────
     * উপরের সবগুলো সার্ভারে সরাসরি পাঠায়, তাই ফর্মে ঘরটা না থাকলেও
     * ওগুলো সবুজ থাকত। কিন্তু আসল ফাঁদটা ওখানেই ছিল: পর্দা এমন একটা
     * জিনিস চাইত যা দেওয়ার জায়গা সে দেয়নি, তাই ব্যাংক ছুঁলে
     * "সংরক্ষণ ও পোস্ট" প্রতিবারই ব্যর্থ হত।
     */
    public function test_the_journal_form_offers_the_transaction_number(): void
    {
        $this->get(route('accounts.voucher.create', 'journal'))
            ->assertOk()
            ->assertSee('name="instrument_no"', false);
    }
}
