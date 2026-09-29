<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\MasterData\Models\PaymentMethod;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * রসিদের তালিকায় "মাধ্যম" কলামে চাবিটাই ছাপা হত।
 *
 * ── ⛔ কী দেখা গিয়েছিল, ২৯ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * লাইভের প্রতিটা মেনু পাতা হেঁটে দেখতে গিয়ে `/accounts/vouchers/receipt`
 * পাতায় মাধ্যমের ঘরে `accounts::instrument.CASH` — তিনটা ভূমিকাতেই।
 * ⓘ ভাউচারের নিজের পাতাটাও একই মান পড়ে, তাই সেখানেও।
 *
 * ── ⚠️ আর কলামটায় দুইটা অর্থ জমা আছে, উৎস অনুযায়ী ────────────────────
 * ⓘ হাতে লেখা ভাউচারে কোডবদ্ধ তালিকা ([[Voucher::INSTRUMENTS]], দরজায়
 * `Rule::in`), আর **কাউন্টারের** ভাউচারে পেমেন্ট-পদ্ধতির **কোড**
 * (`CASH`, `CHQ`)।
 *
 * ── ⛔ আর ঐ তফাতটা দুর্ঘটনা নয়, ওটাই দুই পথকে আলাদা করে ───────
 * [[VoucherService::assertNoChequeReceived()]] ঠিক `instrument === 'cheque'`
 * আটকায়: *"চেক কেবল রেজিস্টারের মধ্য দিয়ে"*। ⓘ কোড-বহনকারী
 * রসিদ ঐ পাহারার নিচ দিয়ে যায়, আর সেটাও ইচ্ছাকৃত
 * ([[NoChequeReachesTheBooksByHandTest]] ঠিক এটাই মাপে)।
 *
 * ⚠️ এখানে একটা কথা গুলিয়ে ফেলা সহজ, আমি নিজে ফেলেছিলাম।
 * **কাউন্টার আজ আর নতুন চেক নেয় না** — তার নিজের দরজায়
 * ফিরিয়ে দেয় ([[DirectSaleChequeTest]])। ⓘ খোলা রয়েছে **নিচের**
 * দরজাটা: ভাউচারের স্তরে কোড-বহনকারী রসিদ, আর পুরনো
 * কাউন্টারের সারিগুলো ঠিক ঐ আকৃতির।
 *
 * ⛔ একবার এই সারাইটা **লেখার** জায়গায় করতে গিয়েছিলাম — একটা
 * পাহারা, যা [[Voucher::INSTRUMENTS]]-এর বাইরে কিছু বসতে দেয় না —
 * আর তাতে ঐ নিচের দরজাটাই বন্ধ হয়ে যেত। ⓘ ধরা পড়েছে কেবল
 * মিউট্যান্টের সারভাইভার তাড়া করতে গিয়ে।
 *
 * ⭐ তাই সারাই কেবল **পড়ার** দিকে, আর এই ফাইলের দুইটা দাবি
 * **একসাথে** থাকে: পর্দায় চাবি নয়, **আর** ভাউচারের স্তরে
 * কোড-বহনকারী রসিদ এখনও বসে। ⓘ একসাথে, যাতে পরের বার
 * কেউ একটা সারাতে গিয়ে অন্যটা না ভাঙে।
 */
final class TheModeColumnPrintedItsOwnKeyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $cash;

    private Account $party;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $cash = Account::query()->where('is_group', false)
            ->where('money_kind', Account::CASH)->first();
        $party = Account::query()->where('is_group', false)
            ->whereNull('money_kind')->first();

        $this->assertNotNull($cash, 'ডেমোতে কোনো নগদ খাত নেই।');
        $this->assertNotNull($party, 'ডেমোতে টাকা-বহির্ভূত কোনো খাত নেই।');

        $this->cash = $cash;
        $this->party = $party;
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_payment_method_code_reads_as_a_word_not_a_key(): void
    {
        /*
         * ⭐ কাউন্টারের আসল দশা: কলামে পদ্ধতির কোড। ⓘ কোডটা হাতে লেখা
         * হয় না, ডেমোর পদ্ধতি থেকেই তোলা — নিজের টাইপ করা কোডে বানানো
         * দাবি কোনোদিন ব্যর্থ হতে পারত না।
         *
         * ⚠️ আর শব্দটা আসে **টাকার খাতের** ধরন থেকে, পদ্ধতির নয় —
         * সেটাই হিসাব মডিউলের নিজস্ব জ্ঞান। ⓘ তাই সারিগুলো তোলা
         * থাকতে হয়, আর দাবিটা ওটাও মাপে।
         */
        $method = PaymentMethod::query()->where('kind', 'cash')->firstOrFail();

        $this->actingAs($this->owner);

        $voucher = app(VoucherService::class)->create(
            [
                'type' => Voucher::RECEIPT,
                'trx_date' => now()->toDateString(),
                'instrument' => 'cash',
            ],
            $this->lines(),
        );

        $voucher->forceFill(['instrument' => $method->code])->save();

        $words = (string) Voucher::query()
            ->with('lines.account')
            ->findOrFail($voucher->id)
            ->wayInWords();

        $this->assertNotSame('', $words);
        $this->assertStringNotContainsString('::', $words,
            $method->code.' কোডটা চাবি হিসেবেই ফেরত এল।');
        $this->assertNotSame($method->code, $words,
            'কোডটাই পর্দায় বসেছে, শব্দ নয়।');
        $this->assertSame((string) __('accounts::instrument.cash'), $words);
    }

    public function test_the_lines_must_be_loaded_or_nothing_is_guessed(): void
    {
        /*
         * ⛔ সারি থেকে ধরন পড়া মানে সারিগুলো তোলা থাকতে হবে।
         *
         * ⚠️ তোলা না থাকলে স্থানীয়ভাবে `preventLazyLoading` পাতাটা
         * ভাঙত, আর লাইভে প্রতি সারিতে একটা কোয়ারি — দুইটাই বাগ।
         * ⭐ তাই তোলা না থাকলে মডেল অনুমান করে না, যা লেখা আছে তাই দেয়।
         *
         * ⓘ এই দাবিটা তাই একসাথে দুইটা কথা বলে: কোনো অবস্থাতেই
         * চাবি নয়, আর অনুমানের জন্য কোনো কোয়ারি নয়।
         */
        $method = PaymentMethod::query()->where('kind', 'cash')->firstOrFail();

        $this->actingAs($this->owner);

        $voucher = app(VoucherService::class)->create(
            [
                'type' => Voucher::RECEIPT,
                'trx_date' => now()->toDateString(),
                'instrument' => 'cash',
            ],
            $this->lines(),
        );

        $voucher->forceFill(['instrument' => $method->code])->save();

        $bare = Voucher::query()->findOrFail($voucher->id);

        $this->assertFalse($bare->relationLoaded('lines'),
            'সারিগুলো তোলাই আছে, তাই এই দাবিটা অন্য কিছু মাপছে।');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $words = (string) $bare->wayInWords();

        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame(0, $queries,
            'শব্দটা বের করতে '.$queries.'টা কোয়ারি গেল — পঞ্চাশ সারির '
            .'তালিকায় ওটাই পঞ্চাশটা হত।');

        $this->assertStringNotContainsString('::', $words,
            'সারি তোলা না থাকলে কাঁচা চাবি বেরিয়ে আসে।');

        $this->assertSame($method->code, $words,
            'অনুমান করা হয়েছে বলে মনে হচ্ছে — সারি না থাকলে যা লেখা আছে '
            .'তাই ফেরার কথা।');
    }


    public function test_the_receipt_list_and_the_voucher_page_print_words(): void
    {
        $this->actingAs($this->owner);

        $method = PaymentMethod::query()->where('kind', 'cash')->firstOrFail();

        $voucher = app(VoucherService::class)->create(
            [
                'type' => Voucher::RECEIPT,
                'trx_date' => now()->toDateString(),
                'instrument' => 'cash',
            ],
            $this->lines(),
        );

        /* ⓘ কাউন্টারের আকৃতিটা সরাসরি বসানো — সেবা কোড নেয় না, কিন্তু
             কাউন্টার ওটাই লেখে, আর পর্দাকে সেটাও পড়তে হবে */
        $voucher->forceFill(['instrument' => $method->code])->save();

        foreach (['/accounts/vouchers/receipt', '/accounts/vouchers/'.$voucher->id] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertSame(0, preg_match('/accounts::instrument\./', $html),
                $url.' পাতায় মাধ্যমের ঘরে কাঁচা চাবি ছাপা হচ্ছে।');

            $this->assertStringContainsString(__('accounts::instrument.cash'), $html,
                $url.' পাতায় মাধ্যমের শব্দটাই নেই — দাবিটা তখন একটা খালি পাতাও মেনে নিত।');
        }
    }

    public function test_and_a_voucher_carrying_a_method_code_still_posts(): void
    {
        /*
         * ⛔ এই দাবিটা আগেরটার পাশেই থাকে, আর সেটা ইচ্ছাকৃত।
         *
         * ⓘ পর্দার চাবিটা সারাতে গিয়ে একবার **লেখার** জায়গায় হাত
         * দিয়েছিলাম: সেবায় একটা পাহারা বসালাম যা
         * [[Voucher::INSTRUMENTS]]-এর বাইরে কিছু বসতে দেয় না। ⚠️
         * কিন্তু কাউন্টারের আকৃতির রসিদ পদ্ধতির **কোড** (`CHQ`) বহন
         * করে, আর ঐ পাহারা গুলোকেই ফিরিয়ে দিত —
         * [[NoChequeReachesTheBooksByHandTest]] ঠিক এই দরজাটা
         * খোলা থাকা মাপে।
         *
         * ⛔ স্পষ্ট করে বলি, কারণ আমি নিজেই একবার গুলিয়ে ফেলেছিলাম:
         * **কাউন্টার আজ আর নতুন চেক নেয় না** — সেটা
         * [[DirectSaleChequeTest::test_the_counter_no_longer_takes_a_cheque]]-এর
         * কাজ। ⓘ এখানে মাপা হয় **নিচের** দরজাটা: ভাউচারের
         * স্তরে কোড-বহনকারী রসিদ আগের মতোই বসে — পুরনো
         * কাউন্টারের সারিগুলো ঠিক এই আকৃতির।
         *
         * ⭐ পাশে রাখা হলো যাতে পরের বার কেউ পর্দার চাবি সারাতে
         * গিয়ে লেখার দিকে হাত দিলে **এই ফাইলেই** লাল হয়।
         */
        $method = PaymentMethod::query()->where('kind', 'cheque')->first();

        $this->assertNotNull($method, 'ডেমোতে চেকের কোনো পদ্ধতি নেই।');

        $this->actingAs($this->owner);

        $voucher = app(VoucherService::class)->create(
            [
                'type' => Voucher::RECEIPT,
                'trx_date' => now()->toDateString(),
                'instrument' => $method->code,
            ],
            $this->lines(),
        );

        $this->assertSame($method->code, $voucher->instrument,
            'কোড-বহনকারী রসিদ আর বসছে না — নিচের দরজাটা বন্ধ হয়ে গেছে।');
    }


    public function test_the_signing_sheet_names_the_way_the_money_came(): void
    {
        /*
         * ⛔ তৃতীয় দরজা, আর এটায় কেউ তাকায়নি।
         *
         * ⓘ দুইটা পর্দা সারানোর পর গুনে দেখি কলামটা অন্তত ছয়
         * জায়গায় পড়া হয়, আর তার মধ্যে এই একটায় অনুবাদ না মিললে
         * কোডটাই ছাপা হত — তাই সইকারী `BKASH` পড়তেন।
         *
         * ⚠️ সংখ্যা গুনলে এটা ধরা পড়ত না: "দুই পাতায় শব্দ ছাপে"
         * দাবিটা সবুজ থেকেই গেছে। ⭐ তাই দরজাগুলো একটা-একটা গনা।
         *
         * ⓘ আশা করা শব্দটা **টাকার খাতের** ধরন ধরে, পদ্ধতির নয় —
         * সারিতে নগদ খাত বসে। ⚠️ পদ্ধতির ধরন ধরে লিখলে দাবিটা
         * ভুল কারণে সবুজ হত, আর ডেমোর কোড বদলালেই লাল।
         */
        $method = PaymentMethod::query()->where('kind', 'mfs')->first()
            ?? PaymentMethod::query()->where('kind', 'cash')->firstOrFail();

        $this->actingAs($this->owner);

        $voucher = app(VoucherService::class)->create(
            [
                'type' => Voucher::RECEIPT,
                'trx_date' => now()->toDateString(),
                'instrument' => 'cash',
            ],
            $this->lines(),
        );

        /* ⓘ কাউন্টারের আকৃতি, দরজা পেরুনোর পর — সেবা কোড নেয় না */
        $voucher->forceFill(['instrument' => $method->code])->save();

        $sheet = $voucher->fresh()->signingSheet();

        $label = (string) __('accounts::field.instrument');

        $row = null;

        foreach ($sheet['facts'] as $fact) {
            if ($fact['label'] === $label) {
                $row = $fact['value'];
            }
        }

        $this->assertNotNull($row,
            'সইয়ের কাগজে মাধ্যমের ঘরটাই নেই — দাবিটা তখন কিছুই মাপত না।');

        $this->assertNotSame($method->code, $row,
            'সইকারী কোড ('.$method->code.') পড়ছেন, শব্দ নয়।');

        $this->assertStringNotContainsString('::', $row);

        $this->assertSame((string) __('accounts::instrument.cash'), $row,
            'সইয়ের কাগজে টাকার খাতের ধরন অনুসারে শব্দটা বসেনি।');
    }


    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        return [
            ['account_id' => $this->cash->id, 'debit' => '100', 'credit' => '0'],
            ['account_id' => $this->party->id, 'debit' => '0', 'credit' => '100'],
        ];
    }
}
