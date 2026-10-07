<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ড্রপডাউন পুরো ছকটা দিত, আর ভুল খাত বাছা সহজ ছিল।
 *
 * ── ⓘ কেন প্রথমে ছাঁকনি বসানো হয়নি ─────────────────────────────────
 * ঋণের নিজস্ব খাতগুলো (`2170` · `2211` · `2212`) তখন চার্টে ছিল না।
 * ⛔ ছেঁকে দিলে **খালি ড্রপডাউন** পড়ত, আর ব্যবহারকারী বুঝতেন না কী
 * হারিয়ে গেছে। ⭐ দৃশ্যমান গোলমাল নীরব ব্যর্থতার চেয়ে ভালো।
 *
 * ── ⚠️ আর এখন ছাঁকনিটার নিজের ঝুঁকি ────────────────────────────────
 * কোড তিনটা `BankFacilityController`-এ ধ্রুবক হিসেবে লেখা, অথচ ছকের
 * মালিক `Accounts/`। ⛔ কেউ কোড বদলালে ছাঁকনিটা **নীরবে খালি** হয়ে
 * যাবে — কোনো ভুল উঠবে না, শুধু তালিকায় কিছু থাকবে না।
 *
 * ⭐ তাই এই ফাইলের দাবি দুইমুখী: **ছেঁকে নেয়, আর ছেঁকে নিয়ে খালি হয় না।**
 *
 * ── ⛔ এই ফাইলটা একবারও চালানো হয়নি, ১৬ সেপ্টেম্বর ২০২৬ ─────────────
 * লেখার রাতে এই ল্যাপটপে নয়টা phpunit একসাথে চলছিল, আর `RefreshDatabase`-এর
 * `migrate:fresh` মাঝপথে থেমে যাচ্ছিল (একটা টেবিলে দুই মিনিট)। ⓘ abos-e8-এর
 * সুইটেও তিনবার হুবহু একই ৩১০টা লাল এসেছে, আর লাইভে ঐ একই মাইগ্রেশন
 * ৩১ মিলিসেকেন্ডে চলেছে — অর্থাৎ কোড নয়, মেশিন।
 *
 * ⚠️ তাই **সকালে লাল দেখলে প্রথমে ধরে নিও পাহারাটারই ভুল**, কোড ভাঙেনি।
 * ⭐ প্রথম আসল রান Mac Mini-তে।
 *
 * ══ ⛔ এই ফাইলের পুরনো দাবিগুলোর দুর্বলতা — পড়ে নাও, ২৭ সেপ্টেম্বর ২০২৬ ══
 *
 * উপরের `test_the_liability_dropdown_offers_only_the_loan_accounts` আর
 * `test_the_money_dropdown_offers_only_money_accounts` মাপে **ড্রপডাউনের
 * ভিতরে কী আছে** — তিনটা ঋণের খাত, আর অ-টাকার খাত নেই। ⛔ কিন্তু ওরা
 * বলতে পারে না ঘরটা ব্যবহারকারী **কোনোদিন দেখেছিলেন কি না**।
 *
 * ⚠️ আর ঠিক এই ফাঁকটাতেই একটা ভুল মাসখানেক বেঁচে ছিল। দায়ের ঘরটা
 * `<template x-if=...>`-এর ভিতরে বসানো ছিল, শর্ত `kind !== cc && kind
 * !== bg`, অথচ `kind`-এর শুরুর মান `cc`। ⓘ Blade একটা `template`-এর
 * ভিতরটাও HTML-এ ছেপে দেয় — ওটা নিষ্ক্রিয় থাকে DOM-এ, Alpine ক্লোন না
 * করা পর্যন্ত। ⛔ ফলে `getContent()`-এ `<select>`-টা ছিল, `<option>`
 * তিনটাও ছিল, দাবিটা সবুজ ছিল — আর পর্দায় ঘরটা ছিলই না।
 *
 * ⛔ তাই উপরের দুইটা দাবির উপর **দৃশ্যমানতার** প্রশ্নে ভরসা কোরো না।
 * ⭐ নিচের দুইটা দাবি সেই প্রশ্নটাই আলাদা করে করে: ঘরটা কোনো
 * `template` গেটের ভিতরে নেই তো?
 *
 * ── ⚠️ নিচের দাবিগুলোর নিজেরও একটা সীমা আছে ─────────────────────────
 * PHP-র অনুরোধে Alpine চলে না। ⓘ তাই `x-bind:disabled` বা `x-show`
 * সত্যিই চলল কি না, সেটা এখান থেকে প্রমাণ করা যায় না — কেবল **বাঁধনটা
 * লেখা আছে** এটুকু দেখা যায়। ⭐ আসল ভরসাটা সার্ভার থেকে বসানো
 * `disabled` অ্যাট্রিবিউটে, কারণ ওটা Alpine ছাড়াই HTML-এ থাকে।
 * ⓘ Alpine নিজে চলল কি না তার পাহারা `resources/js`-এর vitest-এ।
 */
final class TheDropdownOfferedTheWholeChartTest extends TestCase
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
     * ⭐ দায়ের খাতে ঠিক তিনটা — পুরো ছক নয়।
     */
    public function test_the_liability_dropdown_offers_only_the_loan_accounts(): void
    {
        $page = $this->get(route('finance.bank_facility.create'));
        $page->assertOk();

        $options = $this->optionsOf($page->getContent(), 'liability_account_id');

        $this->assertNotEmpty($options,
            'দায়ের ড্রপডাউনটা খালি — ছাঁকনির কোডগুলো ছকের সাথে আর মেলে না।');

        foreach (['2170', '2211', '2212'] as $code) {
            $this->assertTrue(
                (bool) array_filter($options, fn (string $o): bool => str_contains($o, $code)),
                "{$code} তালিকায় নেই।",
            );
        }

        /*
         * ⚠️ আর যেগুলো **থাকা উচিত নয়** — নাহলে "ছেঁকেছি" কথাটা
         * মিথ্যা হত। ⓘ `1101` টাকার খাত, দায় নয়; ওটা তালিকায় এলে
         * একটা ঋণ নগদের খাতে বসে যেত।
         */
        $this->assertFalse(
            (bool) array_filter($options, fn (string $o): bool => str_contains($o, '1101')),
            'টাকার খাত দায়ের তালিকায় ঢুকেছে — ছাঁকনিটা কাজ করছে না।',
        );

        $this->assertLessThan(
            Account::query()->where('is_group', false)->count(),
            count($options),
            'তালিকাটা পুরো ছকের সমান — ছাঁকনিটা আসলে বসেনি।',
        );
    }

    /**
     * ⛔ তিনটা কোড সত্যিই ছকে আছে — ধ্রুবক আর বাস্তবের মিল।
     *
     * ⓘ এটাই ঐ নীরব ব্যর্থতার পাহারা: কোড বদলে গেলে এই দাবিটা লাল
     * হয়, আর উপরের দাবিটা "খালি তালিকা" বলে লাল হয় — দুই দিক থেকে।
     */
    public function test_the_three_loan_accounts_exist_in_the_chart(): void
    {
        foreach (['2170', '2211', '2212'] as $code) {
            $account = Account::query()->where('code', $code)->first();

            $this->assertNotNull($account, "ছকে {$code} খাতটাই নেই।");
            $this->assertFalse((bool) $account->is_group,
                "{$code} একটা গ্রুপ — ওতে সরাসরি দাখিলা বসে না।");
        }
    }

    /**
     * ⭐ টাকার তালিকায় কেবল টাকার খাত।
     *
     * ⓘ `money()` স্কোপ `money_kind` ধরে বাছে, কোড ধরে নয় — তাই নতুন
     * ব্যাংক হিসাব খুললে সেটা নিজে থেকেই আসে, আর এই দাবিটা তখনো টেকে।
     */
    public function test_the_money_dropdown_offers_only_money_accounts(): void
    {
        $page = $this->get(route('finance.bank_facility.create'));
        $page->assertOk();

        $options = $this->optionsOf($page->getContent(), 'money_account_id');

        $this->assertNotEmpty($options, 'টাকার ড্রপডাউনটা খালি।');

        $money = Account::query()->money()->where('is_group', false)
            ->pluck('code')->all();

        foreach ($options as $option) {
            $found = (bool) array_filter($money, fn (string $c): bool => str_contains($option, $c));
            $this->assertTrue($found, "টাকার তালিকায় একটা অ-টাকার খাত: {$option}");
        }
    }

    /**
     * ⭐ ঘরটা কোনো `template` গেটের ভিতরে নেই — পাঁচ ট্যাবেই আঁকা।
     *
     * ── ⛔ কেন দাবিটা এইভাবে লেখা ───────────────────────────────────
     * `assertSee('liability_account_id')` এই ভুলটা ধরত না: গেটের ভিতরে
     * থাকলেও লেখাটা পাতায় থাকে। ⓘ তাই মাপা হয় **অবস্থান**: `<select>`-টার
     * আগে যতগুলো `<template>` খোলা হয়েছে আর যতগুলো বন্ধ, দুইটা সমান
     * কি না। ⚠️ সমান না হলে ঘরটা একটা বন্ধ খামের ভিতরে বসে আছে।
     */
    public function test_the_liability_field_sits_outside_every_template_gate(): void
    {
        $page = $this->get(route('finance.bank_facility.create'));
        $page->assertOk();

        $depth = $this->templateDepthAt($page->getContent(), 'liability_account_id');

        $this->assertNotNull($depth,
            'পাতায় liability_account_id নামের কোনো select-ই নেই।');

        $this->assertSame(0, $depth,
            'দায়ের ঘরটা এখনো একটা template গেটের ভিতরে — শুরুর ট্যাবে (cc) '
            .'Alpine ওটা ক্লোন করে না, তাই ব্যবহারকারী ঘরটা দেখেনই না।');

        /*
         * ⓘ টাকার ঘরটা ইচ্ছে করেই CC-র বাক্সে, তাই ওটা গেটের ভিতরে
         * থাকাই ঠিক। ⭐ দাবিটা এখানে রাখা হলো যাতে পরে কেউ
         * "সব ঘর গেটের বাইরে আনি" বলে ওটাও টেনে বের না করে।
         */
        $this->assertSame(1, $this->templateDepthAt($page->getContent(), 'money_account_id'),
            'টাকার ঘরটা CC-র ড্রয়িং পাওয়ার বাক্সের ভিতরেই থাকার কথা।');
    }

    /**
     * ⭐ CC-তে ঘরটা নিষ্ক্রিয়, আর কারণটা পর্দাতেই লেখা।
     *
     * ⓘ শুরুর ট্যাব `cc`, তাই সার্ভারই `disabled` বসিয়ে পাঠায় — Alpine
     * বুট হওয়ার অপেক্ষা না করেই। ⚠️ এই দাবিটাই আসল, কারণ এটা Alpine
     * ছাড়াই সত্য; নিচের `x-bind` দাবিটা কেবল বাঁধনটা আছে কি না দেখে।
     */
    public function test_the_liability_field_is_disabled_and_explained_on_the_cash_credit_tab(): void
    {
        $page = $this->get(route('finance.bank_facility.create'));
        $page->assertOk();
        $html = $page->getContent();

        $tag = $this->openTagOf($html, 'select', 'liability_account_id');

        $this->assertNotNull($tag, 'পাতায় liability_account_id নামের কোনো select-ই নেই।');

        /*
         * ⛔ এই দাবিটা প্রথমে `assertStringContainsString('disabled', $tag)`
         * ছিল, আর সেটা **অন্ধ**: ট্যাগে `x-bind:disabled=...` আছে, যার
         * ভিতরেই "disabled" শব্দটা বসে আছে। ⚠️ ফলে সার্ভার থেকে বসানো
         * `disabled` একেবারে উঠে গেলেও দাবিটা সবুজ থাকত — অর্থাৎ ঠিক যে
         * জিনিসটা মাপার কথা, সেটাই মাপা হত না।
         *
         * ⭐ তাই Alpine-এর বাঁধনগুলো আগে কেটে ফেলা হয়, তারপর খোঁজা হয়
         * একলা দাঁড়ানো `disabled` অ্যাট্রিবিউট। ⓘ Laravel bool `true`-কে
         * খালি `disabled` হিসেবেই ছাপে (ComponentAttributeBag), তাই
         * `disabled="..."` নয়, একলা শব্দটাই আসল রূপ।
         */
        /*
         * ⚠️ দুইটা রূপ আলাদা করে লেখা, আর সেটা মেপে জানা: Laravel-এর
         * [[ComponentAttributeBag]] bool `true`-কে `disabled="disabled"`
         * বানায়, খালি `disabled` নয়। ⛔ প্রথমে খালি রূপটা ধরে লিখেছিলাম
         * আর হাতে লেখা একটা নমুনায় "প্রমাণ" করেছিলাম — নমুনাটাই আসল
         * রেন্ডারের মতো ছিল না। ⓘ তাই ছাঁকনিটা কেবল Alpine-এর রূপ কাটে
         * (`:disabled` আর `x-bind:disabled`), আর দুইটা রূপের যেটাই আসুক
         * তাই মেলে।
         */
        $plain = preg_replace('/\s(?:x-bind:|:)disabled="[^"]*"/', ' ', $tag);

        $this->assertMatchesRegularExpression('/\sdisabled(?:="[^"]*")?(?=[\s>])/', $plain,
            'শুরুর ট্যাব cc, অথচ দায়ের ঘরটা খোলা — CC-তে ওটা ভরার কথা নয়। '
            .'(Alpine-এর বাঁধন কেটে দেখা হয়েছে, তাই x-bind এটাকে সবুজ করতে পারে না।)');

        /*
         * ⚠️ ধরন বদলালে ঘরটা আবার খুলতে হবে, নাহলে মেয়াদি ঋণেও ওটা
         * নিষ্ক্রিয় থেকে যেত আর বাগটা উল্টো দিকে ফিরে আসত।
         */
        $this->assertStringContainsString('x-bind:disabled', $tag,
            'ধরন বদলালে ঘরটা খুলবে কীসে — কোনো Alpine বাঁধনই নেই।');

        foreach (['cc', 'bg'] as $kind) {
            $this->assertStringContainsString("'{$kind}'", $tag,
                "বাঁধনটা {$kind} ধরনের কথা বলে না।");
        }

        /*
         * ⛔ নিষ্ক্রিয় ঘর কারণ না বললে সেটা ভাঙা ঘরের মতো দেখায়।
         * ⓘ লেখাটা Bengali, আর এখানে হরফ মিলিয়ে দেখা হয় না — ঐ ফাইলে
         * `য়` যুক্তবর্ণে ভাঙা থাকে, তাই টাইপ করা বাংলা কোনোদিন মেলে না।
         * ⭐ তার বদলে দেখা হয় লাইনটা আছে, খালি নয়, আর ধরনের সাথে বাঁধা।
         */
        $this->assertMatchesRegularExpression(
            '/<p\b[^>]*data-liability-not-applicable[^>]*>(.*?)<\/p>/s',
            $html,
            'CC-তে ঘরটা কেন নিষ্ক্রিয়, সেটা পর্দায় কোথাও লেখা নেই।',
        );

        preg_match('/<p\b([^>]*data-liability-not-applicable[^>]*)>(.*?)<\/p>/s', $html, $m);

        $this->assertGreaterThan(20, mb_strlen(trim(strip_tags($m[2]))),
            'ব্যাখ্যার লাইনটা আছে, কিন্তু ভিতরে কিছু নেই।');

        $this->assertStringContainsString('x-show', $m[1],
            'ব্যাখ্যাটা সব ট্যাবেই দেখা যাবে — ধরনের সাথে বাঁধা নেই।');
    }

    /**
     * একটা নামের ইনপুট/সিলেক্টের আগে কয়টা `template` খোলা আছে।
     *
     * ⓘ `null` মানে ঘরটা পাতাতেই নেই — শূন্য গভীরতার সাথে গুলিয়ে
     * যাওয়ার সুযোগ রাখা হয়নি, কারণ দুইটা সম্পূর্ণ আলাদা ব্যর্থতা।
     */
    private function templateDepthAt(string $html, string $name): ?int
    {
        if (! preg_match('/<select\b[^>]*name="'.preg_quote($name, '/').'"/s', $html, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $before = substr($html, 0, (int) $m[0][1]);

        return preg_match_all('/<template\b/i', $before)
            - preg_match_all('#</template\s*>#i', $before);
    }

    /**
     * একটা নামের ট্যাগের খোলা অংশটা — অ্যাট্রিবিউটগুলো দেখার জন্য।
     */
    private function openTagOf(string $html, string $tag, string $name): ?string
    {
        $found = preg_match(
            '/<'.preg_quote($tag, '/').'\b[^>]*name="'.preg_quote($name, '/').'"[^>]*>/s',
            $html,
            $m,
        );

        return $found ? $m[0] : null;
    }

    /**
     * রেন্ডার হওয়া পাতা থেকে একটা select-এর বিকল্পগুলো।
     *
     * ⚠️ পাতার লেখা ধরে মাপা হয়, ব্লেড পড়ে নয় — আজ ঠিক এই তফাতেই
     * একটা খালি ড্রপডাউন ধরা পড়েছিল: ঘরটা ছিল, ভিতরে কিছু ছিল না।
     *
     * @return list<string>
     */
    private function optionsOf(string $html, string $name): array
    {
        if (! preg_match('/<select\b[^>]*name="'.preg_quote($name, '/').'".*?<\/select>/s', $html, $m)) {
            return [];
        }

        preg_match_all('/<option\b[^>]*>(.*?)<\/option>/s', $m[0], $opts);

        return array_values(array_filter(array_map(
            static fn (string $o): string => trim(strip_tags($o)),
            $opts[1],
        )));
    }
}
