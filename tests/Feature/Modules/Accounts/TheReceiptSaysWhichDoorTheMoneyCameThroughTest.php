<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিক্রয়ের পর্দা থেকে নেওয়া জমার রসিদ নিজের নামেই ডাকা হয়।
 *
 * ── ⭐ মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"ei আদায় ভাউচার er nam hobe sales added deposit"*।
 *
 * ── ⓘ কেন নামটা কাজের, সাজসজ্জা নয় ─────────────────────────────────
 * খাতায় *"আদায় ভাউচার"* নামে বহু কাগজ থাকে: গ্রাহকের চেক, ব্যাংকের
 * জমা, পুরনো বকেয়া আদায়। ⚠️ বিক্রয়ের পর্দায় হাতে নেওয়া টাকাটাও ঠিক
 * একই নামে বসত, তাই কাগজটা খুলে বলা যেত না সে কোন দরজা দিয়ে এসেছে।
 *
 * ⛔ আর মালিকের আগের প্রশ্নটা (*"INV-0004 ekta deposit diyechi ta haralo
 * keno?"*) ঠিক এই না-বলা থেকেই এসেছিল: টাকা হারায়নি, **কাগজের পরিচয়**
 * হারিয়েছিল।
 */
final class TheReceiptSaysWhichDoorTheMoneyCameThroughTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_counter_receipt_is_called_a_sales_deposit(): void
    {
        $voucher = new Voucher(['type' => Voucher::RECEIPT, 'origin' => Voucher::ORIGIN_COUNTER]);

        $this->assertSame(__('accounts::voucher.origin_sales_deposit'), $voucher->originLabel(),
            '⛔ বিক্রয়ের জমার রসিদটা এখনো সাধারণ "আদায় ভাউচার" নামেই ডাকা হচ্ছে।');
    }

    /**
     * ⛔ আর ক্রয়ের পরিশোধে সেই নামটা **বসে না** — এটাই আসল দাবি।
     *
     * ⓘ `counter` লেখাটা দুই জায়গায় বসে: বিক্রয়ে জমা নেওয়ার সময় আর
     * ক্রয়ে পরিশোধ করার সময়। ⚠️ কেবল `origin` ধরে নাম দিলে ক্রয়ের
     * কাগজেও "বিক্রয়ে যোগ করা জমা" উঠত — টাকার কাগজে ভুল দিক।
     */
    public function test_a_counter_payment_is_not_called_a_deposit(): void
    {
        $voucher = new Voucher(['type' => Voucher::PAYMENT, 'origin' => Voucher::ORIGIN_COUNTER]);

        $this->assertSame(__('accounts::voucher.origin_purchase_payment'), $voucher->originLabel(),
            '⛔ ক্রয়ের পরিশোধে বিক্রয়ের নামটা বসেছে — কাগজটা উল্টো দিক দেখাচ্ছে।');
    }

    /**
     * ⓘ বাকি সব ভাউচারে কিছুই বদলায় না।
     *
     * ⚠️ এই দাবিটা না থাকলে নামকরণটা চুপচাপ **প্রতিটা** আদায়ের কাগজে
     * ছড়িয়ে পড়তে পারত, আর হাতে লেখা সাধারণ রসিদও নিজেকে বিক্রয়ের জমা
     * বলে দাবি করত।
     */
    public function test_an_ordinary_receipt_keeps_its_plain_name(): void
    {
        $voucher = new Voucher(['type' => Voucher::RECEIPT, 'origin' => null]);

        $this->assertNull($voucher->originLabel(),
            '⛔ সাধারণ আদায় ভাউচারও নিজেকে বিক্রয়ের জমা বলছে।');
    }

    /**
     * ⭐ আর নামটা সত্যিই কাগজে ওঠে — কেবল মডেলে নয়।
     *
     * ⓘ উপরের তিনটা দাবি মডেলের ভিতরের কথা। ⛔ পর্দা যদি `typeLabel()`
     * ডাকত, তিনটাই সবুজ থাকত আর মালিক পাতায় কোনো বদল দেখতেন না — ঠিক
     * সেই ফাঁক, যেটা আজ রাতে বারবার ফিরেছে: কাজটা হয়েছে, জোড়াটা নয়।
     */
    public function test_the_name_reaches_the_page(): void
    {
        /*
         * ⚠️ রসিদটা পরীক্ষা নিজেই বানায়, `markTestSkipped` নয়।
         *
         * ⛔ প্রথম চালে ডেমোর উপর ভরসা করা হয়েছিল, আর ডেমোতে একটাও
         * আদায় ভাউচার নেই — অর্থাৎ **এই ফাইলের একমাত্র দাবি যেটা পর্দা
         * ছোঁয়** সেটাই কোনোদিন চলত না, অথচ রিপোর্ট বলত "passed"।
         *
         * ⓘ ঠিক এই ফাঁদটার কথা আজ রাতেই সহকর্মীদের লিখেছি, আর তারপরেও
         * নিজে তাতেই পড়েছি — তাই লাইনগুলো এখানে থাকল।
         */
        $receivable = Account::query()->postable()
            ->where('code', StandardChart::RECEIVABLE)->firstOrFail();

        $cash = Account::query()->postable()
            ->whereKeyNot($receivable->id)->firstOrFail();

        $voucher = app(VoucherService::class)->create(
            [
                'type' => Voucher::RECEIPT,
                'trx_date' => now()->toDateString(),
                'narration' => 'পরীক্ষার জমা',
                'origin' => Voucher::ORIGIN_COUNTER,
            ],
            app(VoucherService::class)->twoLineEntry(
                Voucher::RECEIPT,
                (int) $receivable->id,
                (int) $cash->id,
                '500',
            ),
        );

        $this->assertStringContainsString(
            __('accounts::voucher.origin_sales_deposit'),
            (string) $this->get(route('accounts.voucher.show', $voucher))->assertOk()->getContent(),
            '⛔ নামটা মডেলে বসেছে, কিন্তু ভাউচারের পাতায় ওঠেনি।',
        );
    }
}
