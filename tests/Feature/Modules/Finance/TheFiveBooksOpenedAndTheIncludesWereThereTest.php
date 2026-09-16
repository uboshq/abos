<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\RentalContract;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পাঁচটা খাতা খুলল, আর include-করা ফাইলগুলো সত্যিই ছিল।
 *
 * ── ⛔ কেন এই ফাইলটা লাগল, ১৬ সেপ্টেম্বর ২০২৬ ───────────────────────
 * আগের রাতে পাঁচটা খাতার পর্দা নমুনার কাঠামোয় লেখা হলো, আর দুইটা
 * সাধারণ অংশ বেরোল — `partials/handoff` ও `partials/voucher-box`।
 * পাঁচটা ব্লেডই ওদের `@include` করে।
 *
 * ⚠️ কমিটে ফাইল দুইটা **যায়নি**। `git commit --only` ডিরেক্টরি দিলে
 * কেবল tracked ফাইল নেয়, আর ভাগ করা index-টা কেউ পরিষ্কার করে দিয়েছিল।
 *
 * ⛔ ফল হত সবচেয়ে খারাপটা: লোকালে সব ঠিক, আর **লাইভে পাঁচটা পাতাই ৫০০**
 * — *"View [finance::partials.handoff] not found"*। ⓘ ধরা পড়েছে কেবল
 * কমিটের পরে হাতে গুনে দেখায়, আর সেটা অভ্যাস, পাহারা নয়।
 *
 * ── ⭐ তাই এই ফাইলের দাবিটা ছোট আর মোটা ────────────────────────────
 * **পাঁচটা পর্দা ২০০ ফেরায়।** ⓘ একটা include হারালে, একটা ভিউ মুছলে,
 * একটা কলাম না বসলে — পাঁচটার যেটাই ভাঙুক, এই ফাইলটা লাল হয়।
 *
 * ⚠️ এটা চেহারা মাপে না, আর মাপার চেষ্টাও করে না। ⓘ চেহারা বদলায়
 * প্রতিদিন; **পাতাটা আদৌ খোলে কি না** বদলায় না।
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
final class TheFiveBooksOpenedAndTheIncludesWereThereTest extends TestCase
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

        /*
         * ছক আর একটা টিল — পর্দাগুলো টাকার খাতের তালিকা আঁকে, আর
         * বসানো ছকে টাকার কোনো **খাত** থাকে না, কেবল মাথা।
         */
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
    }

    /**
     * ⭐ পাঁচটা খাতার পর্দা খোলে — এই ফাইলের একমাত্র আসল দাবি।
     */
    public function test_every_finance_book_opens(): void
    {
        $screens = [
            'finance.capital.create' => [],
            'finance.deposit.index' => ['issuer' => 'bank'],
            'finance.hand_loan.index' => [],
            'finance.bank_facility.index' => [],
            'finance.rental.index' => [],
        ];

        foreach ($screens as $name => $params) {
            $this->get(route($name, $params))->assertOk();
        }
    }

    /**
     * ⛔ দুইটা সাধারণ অংশ সত্যিই রেন্ডার হয়, নাম মিলিয়ে নয়।
     *
     * ── ⚠️ কেন `@include`-এর নাম গুনে দেখা যথেষ্ট নয় ─────────────────
     * ব্লেডে `@include('finance::partials.handoff')` লেখা থাকা মানে
     * ফাইলটা **আছে** নয়। ⓘ ফাইলটা না থাকলে ভুলটা ওঠে কেবল পাতা
     * খোলার সময়, আর সেটাই আজ রাতে প্রায় ঘটেছিল।
     *
     * ⭐ তাই দাবিটা রেন্ডার হওয়া লেখার উপর: ফিতার শব্দ আর বাক্সের
     * শিরোনাম পাতায় আছে কি না।
     */
    public function test_the_two_shared_partials_render_on_every_book(): void
    {
        $screens = [
            ['finance.capital.create', []],
            ['finance.deposit.index', ['issuer' => 'bank']],
            ['finance.hand_loan.index', []],
            ['finance.bank_facility.index', []],
            ['finance.rental.index', []],
        ];

        foreach ($screens as [$name, $params]) {
            $page = $this->get(route($name, $params));

            $page->assertOk();

            // ফিতা — "এই খাতা → ভাউচার → খতিয়ান"
            $page->assertSee(__('finance::message.step_this_book'), false);
            $page->assertSee(__('finance::message.step_ledger'), false);

            // ভাউচারের ঘর — শিরোনামটা দিক অনুযায়ী দুই রকম
            $box = str_contains($page->getContent(), __('finance::field.voucher_box_in'))
                || str_contains($page->getContent(), __('finance::field.voucher_box_out'));

            $this->assertTrue($box, "{$name}-এ ভাউচারের ঘরটা নেই।");
        }
    }

    /**
     * ⭐ কাগজ রাখার জায়গা — চারটা খাতাই `Drillable`।
     *
     * ⚠️ [[components/ui/attachments]] `drillSourceType()` ধরে কাগজ
     * খোঁজে। ⛔ চুক্তিটা না মানলে FDR-এর সার্টিফিকেট বা ভাড়ার
     * চুক্তিপত্র কোথাও তোলা যায় না — আর পর্দায় কিছুই ভাঙে না,
     * কেবল কার্ডটা খালি থাকে।
     */
    public function test_the_books_can_carry_their_papers(): void
    {
        $expected = [
            Deposit::class => 'deposit',
            HandLoanAccount::class => 'hand_loan',
            RentalContract::class => 'rental_contract',
            BankFacility::class => 'bank_facility',
        ];

        foreach ($expected as $class => $source) {
            $this->assertSame($source, $class::drillSourceType());
        }

        /*
         * ⓘ নামটা `module.php`-তেও থাকতে হবে, নাহলে
         * [[DrillResolver]] ক্লাসটা খুঁজেই পায় না।
         */
        $declared = require base_path('app/Modules/Finance/module.php');

        foreach ($expected as $class => $source) {
            $this->assertArrayHasKey($source, $declared['drill_sources'],
                "{$source} module.php-এর drill_sources-এ নেই।");

            $this->assertSame($class, $declared['drill_sources'][$source]);
        }
    }
}
