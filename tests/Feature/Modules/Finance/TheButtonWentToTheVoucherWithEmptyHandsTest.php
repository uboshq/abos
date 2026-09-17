<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বোতামটা ভাউচারে যেত খালি হাতে।
 *
 * ── ⛔ মালিকের প্রশ্ন, ১৮ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"আবার Accounts-এ গেলে তাহলে খাতা আলাদা রেখে লাভ কী?"*
 *
 * ⓘ প্রশ্নটা ন্যায্য ছিল। অর্থের খাতার পর্দায় টাকা, নাম আর বিবরণ ভরে
 * *"টাকা নিন — রসিদ ভাউচার ↗"* চাপলে **একটা খালি রসিদ** খুলত, আর
 * তিনটাই আবার টাইপ করতে হত। ⛔ তখন খাতার পর্দাটা সত্যিই নকল ছিল।
 *
 * ── ⭐ এখন কী হয় ────────────────────────────────────────────────────
 * ফিতার বোতামটা ফর্মের ঘরগুলো পড়ে ঠিকানায় জুড়ে দেয়
 * ([[finance::partials.handoff]]), আর ভাউচারের পর্দা সেগুলো বসায়
 * ([[VoucherController::prefill()]])।
 *
 * ── ⚠️ কেন এই ফাইলটা পাতা খুলে দেখে, নিয়ম মেপে নয় ───────────────────
 * abos-46-এর আজকের শিক্ষা: তার একুশটা সবুজ টেস্ট ছিল আর ফিচারটা দৃশ্যত
 * ভাঙা, কারণ পাহারাগুলো **নিয়ম** মাপত, **পর্দা** নয়।
 *
 * ⛔ এখানে ঠিক সেটাই ঘটেছিল: `Voucher::$fillable`-এ `amount` ছিল,
 * `prefill()` ওটা মডেলে বসাতও — কিন্তু ঘরটা `$debitLine?->debit` পড়ত,
 * আর নতুন ফর্মে দাখিলার লাইনই নেই। ⚠️ নিয়ম-ভিত্তিক দাবি সবুজ থাকত,
 * ঘরটা খালি থাকত। ⭐ তাই দাবিটা রেন্ডার হওয়া `value="…"`-এর উপর।
 */
final class TheButtonWentToTheVoucherWithEmptyHandsTest extends TestCase
{
    use RefreshDatabase;

    private Person $person;

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

        /*
         * ⛔ `firstOrFail()` চলে না — ১৮ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ `Person` মডেলে `BelongsToCompany` স্কোপ আছে, আর ডেমোর
         * একমাত্র মানুষটি **অন্য কোম্পানির**। ⚠️ তাই এই কোম্পানিতে
         * তালিকাটা খালি, আর তিনটা দাবিই সেটআপেই মরত।
         *
         * ⭐ তাই নিজের একজন বসানো হয়: পরীক্ষাটা ডেমোর তথ্যের উপর
         * দাঁড়িয়ে থাকে না, আর কাল ডেমো বদলালেও টেকে।
         */
        $this->person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PTEST1',
            'name_en' => 'Carry Test Person',
            'is_active' => true,
        ]);
    }

    /**
     * ⭐ টাকাটা পর্দায় বসে — এই ফাইলের আসল দাবি।
     *
     * ⓘ দুইটা ধরনেই, কারণ ব্লেডে ঘরটা **তিন জায়গায়** লেখা (পক্ষ উপরে
     * না নিচে, আর ধরন অনুযায়ী শাখা)। ⛔ একটা সারালে বাকিগুলো ভুলে
     * গেলে একই বোতাম এক ভাউচারে কাজ করত, অন্যটায় নীরবে করত না।
     */
    public function test_the_amount_reaches_the_voucher_screen(): void
    {
        foreach (['receipt', 'payment'] as $type) {
            $page = $this->get(route('accounts.voucher.create', [
                'type' => $type,
                'amount' => '250000',
                'narration' => 'carried across',
                'party_type' => 'person',
                'party_id' => $this->person->id,
            ]));

            $page->assertOk();

            /*
             * ⚠️ `escape: false` — খোঁজা হচ্ছে HTML-এর ভিতরের হুবহু লেখা,
             * আর সংখ্যাটা দশমিকসহ বসে (`250000.0000`)।
             */
            $page->assertSee('value="250000.0000"', escape: false);
            $page->assertSee('carried across', escape: false);
        }
    }

    /**
     * ⛔ খালি ঠিকানায় ঘরটা খালিই থাকে।
     *
     * ⓘ এটা উল্টো দিকের পাহারা: ফলব্যাকটা যেন কোনো সংখ্যা **বানিয়ে**
     * না বসায়। ⚠️ `$voucher->amount` না থাকলে ঘরটা ফাঁকা থাকতে হবে,
     * নাহলে ব্যবহারকারী একটা আন্দাজকে সত্যি ভেবে পোস্ট করতেন।
     */
    public function test_an_empty_link_leaves_the_amount_empty(): void
    {
        $page = $this->get(route('accounts.voucher.create', ['type' => 'receipt']));

        $page->assertOk();
        $page->assertDontSee('value="250000.0000"', escape: false);
    }

    /**
     * ⭐ ফিতার বোতামটা পাঁচটা লেখার পর্দাতেই আছে, আর ভাউচারে যায়।
     *
     * ⓘ বোতামের ঠিকানাটা `accounts/vouchers/…/create` — নামটা নয়,
     * ঠিকানাটাই মাপা হয়, কারণ ব্যবহারকারী ঐটাই পান।
     */
    public function test_every_write_screen_offers_the_way_to_the_voucher(): void
    {
        $screens = [
            ['finance.capital.create', []],
            ['finance.withdrawal.create', []],
            ['finance.deposit.index', ['issuer' => 'bank']],
            ['finance.hand_loan.index', []],
            ['finance.bank_facility.index', []],
            ['finance.rental.index', []],
        ];

        foreach ($screens as [$name, $params]) {
            $page = $this->get(route($name, $params));

            $page->assertOk();
            $page->assertSee('/accounts/vouchers/', escape: false);
        }
    }
}
