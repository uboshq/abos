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
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ডিপোজিটর তালিকায় ছিলেন না, আর রসিদটাই কাটা যেত না।
 *
 * ── ⛔ মালিকের নির্দেশ, ১৮ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"ডিপোজিটরের ধরন / প্রাপকের ধরন — এগুলোতে others option রাখা উচিত,
 * যাতে ডিপোজিটরের নাম / প্রাপকের নাম হাতে লিখতে পারে।"*
 *
 * ⚠️ ঘর দুইটা কেবল ড্রপডাউন ছিল। ⛔ কাউন্টারে একজন অচেনা লোক টাকা দিয়ে
 * গেলে প্রথমে মাস্টার ডেটায় গিয়ে তাঁকে বসিয়ে আসতে হত, তারপর ফিরে এসে
 * রসিদ — আর ব্যস্ত কাউন্টারে সেটা কেউ করেন না, রসিদটাই কাটা হয় না।
 *
 * ── ⭐ কেন পঞ্চম একটা "অন্যান্য" ধরন বানানো হয়নি ────────────────────
 * ⛔ তাহলে নামগুলো কোনো তালিকায় থাকত না, পরের বার আবার টাইপ করতে হত,
 * আর একই মানুষ তিন বানানে তিনজন হয়ে যেতেন। ⚠️ ধরা পড়ত ছয় মাস পরে,
 * যখন কেউ জিজ্ঞেস করতেন *"করিম সাহেবকে মোট কত দিলাম?"*
 *
 * ⭐ বদলে নামটা **ব্যক্তি** হয়ে `mdm_people`-এ বসে — মালিকের নিজের
 * নির্দেশে ওখানেই ঋণদাতা, বাড়িওয়ালা, বাহক সবাই থাকেন। ⓘ সমস্যাটা
 * একবার সমাধান হয়, প্রতিবার নয়।
 */
final class TheDepositorWasNotOnTheListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
    }

    /**
     * ⭐ হাতে লেখা নাম একটা আসল পক্ষ হয়ে ভাউচারে বসে।
     */
    public function test_a_typed_name_becomes_a_real_party_on_the_voucher(): void
    {
        $before = Person::query()->count();

        $this->post(route('accounts.voucher.store', Voucher::RECEIPT), [
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'from_account_id' => $this->account(StandardChart::OWNER_CAPITAL),
            'to_account_id' => $this->moneyAccount(),
            'amount' => '1500',
            'narration' => 'WALK-IN',

            /*
             * ⭐ এই দুইটা ঘরই এই ফাইলের বিষয়: তালিকা থেকে কিছু বাছা
             * হয়নি, কেবল নামটা লেখা হয়েছে।
             */
            'party_new' => 'Walk-in Depositor',
            'party_mobile' => '01700000000',

            'save_as_draft' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame($before + 1, Person::query()->count(),
            'হাতে লেখা নামটা পক্ষের তালিকায় বসেনি — পরের বার আবার লিখতে হবে।');

        $person = Person::query()->where('name_en', 'Walk-in Depositor')->first();

        $this->assertNotNull($person, 'নামটা কোথাও নেই।');
        $this->assertSame('01700000000', $person->mobile);

        $voucher = Voucher::query()->latest('id')->firstOrFail();

        /*
         * ⛔ এটাই আসল দাবি: ভাউচারে **আসল আইডি** বসেছে, হাতে লেখা
         * একটা নাম নয়। ⓘ তাই পক্ষের খতিয়ান ভরে ওঠে, আর
         * *"তাঁকে মোট কত দিলাম"* প্রশ্নের উত্তর পাওয়া যায়।
         */
        $this->assertSame('person', $voucher->party_type);
        $this->assertSame($person->id, $voucher->party_id);
    }

    /**
     * ⛔ তালিকা থেকে বাছা থাকলে হাতে লেখা নামটা উপেক্ষিত।
     *
     * ⚠️ দুইটাই ভরা থাকলে **বাছাইটাই জেতে**, আর নতুন কোনো সারি বসে না।
     * ⓘ নাহলে প্রতিবার ফর্ম জমা দিলেই একটা করে নকল নাম জমত।
     */
    public function test_a_picked_party_wins_over_a_typed_one(): void
    {
        $picked = Person::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'PPICK1',
            'name_en' => 'Already Listed',
            'is_active' => true,
        ]);

        $before = Person::query()->count();

        $this->post(route('accounts.voucher.store', Voucher::RECEIPT), [
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'from_account_id' => $this->account(StandardChart::OWNER_CAPITAL),
            'to_account_id' => $this->moneyAccount(),
            'amount' => '900',
            'narration' => 'PICKED-WINS',
            'party_type' => 'person',
            'party_id' => $picked->id,
            'party_new' => 'Should Be Ignored',
            'save_as_draft' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame($before, Person::query()->count(),
            'বাছাই থাকা সত্ত্বেও একটা নতুন নাম বসেছে।');

        $voucher = Voucher::query()->latest('id')->firstOrFail();

        $this->assertSame($picked->id, $voucher->party_id);
    }

    /**
     * ⭐ ঘরটা পর্দাতেই আছে — রসিদ ও পরিশোধ দুইটাতেই।
     *
     * ⚠️ abos-46-এর আজকের শিক্ষা: পাহারা **নিয়ম** মাপলে সবুজ থাকে আর
     * পর্দা ভাঙা থাকে। ⓘ তাই দাবিটা রেন্ডার হওয়া ঘরের নামের উপর।
     */
    public function test_the_typed_name_box_is_on_both_screens(): void
    {
        foreach ([Voucher::RECEIPT, Voucher::PAYMENT] as $type) {
            $page = $this->get(route('accounts.voucher.create', ['type' => $type]));

            $page->assertOk();
            $page->assertSee('name="party_new"', escape: false);
        }
    }

    private function moneyAccount(): int
    {
        return (int) Account::query()->money()->where('is_group', false)->value('id');
    }

    private function account(string $code): int
    {
        return (int) Account::query()->where('code', $code)->value('id');
    }
}
