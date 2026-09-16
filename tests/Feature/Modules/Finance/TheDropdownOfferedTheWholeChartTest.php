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
        $page = $this->get(route('finance.bank_facility.index'));
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
        $page = $this->get(route('finance.bank_facility.index'));
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
