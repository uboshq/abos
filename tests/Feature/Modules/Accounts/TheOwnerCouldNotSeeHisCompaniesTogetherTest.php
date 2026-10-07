<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\GroupLedgerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * এক মালিকের একাধিক কোম্পানির হিসাব এক পাতায়।
 *
 * ── ⭐ মালিকের প্রশ্ন, ২৫ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"এক মালিকের একাধিক কোম্পানি থাকলে কী হবে? সে তো একসাথে হিসাব দেখতে
 * চাইবে।"* ⓘ আগে দেখতে হত এক কোম্পানি করে, সুইচার দিয়ে বদলে বদলে।
 *
 * ── ⚠️ কেন ডেমো ডেটাই ব্যবহার করা হয় ───────────────────────────────
 * [[DemoSeeder]] ঠিক এই দৃশ্যটা বানায়: দুইটা কোম্পানি (`TDEPOT`,
 * `FMART`), মালিক **দুইটাতেই**, আর হিসাবরক্ষক **একটাতে**। ⓘ হাতে
 * বানালে আমি নিজেই ঠিক করতাম কে কোথায় আছে, আর তখন দাবিটা নিজেকেই
 * মেলাত ([[never-supply-the-name-yourself]])।
 *
 * ── ⛔ যে দাবিটা সবচেয়ে জরুরি ───────────────────────────────────────
 * ⚠️ ABOS বহু-ক্রেতার পণ্য, আর টেন্যান্ট বিচ্ছিন্নতা সুবিধা নয় —
 * **আইনি বাধ্যবাধকতা**। ⓘ তাই হিসাবরক্ষকের পাতায় দ্বিতীয় কোম্পানির
 * নাম বা অঙ্ক থাকা চলবে না, যদিও তিনি একই রিপোর্ট খুলছেন।
 */
final class TheOwnerCouldNotSeeHisCompaniesTogetherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
    }

    /**
     * মালিক দুইটা কোম্পানিই দেখেন, আর যোগফলটা সারিগুলোরই যোগ।
     */
    public function test_the_owner_sees_every_company_he_belongs_to(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set((int) Company::query()->where('code', 'TDEPOT')->value('id'));

        $group = app(GroupLedgerService::class)->build($owner);

        $this->assertCount(2, $group['companies'], implode(PHP_EOL, [
            'মালিক দুইটা কোম্পানিতে আছেন, কিন্তু পাতাটা '.count($group['companies']).'টা দেখাচ্ছে।',
            '',
            'ⓘ DemoSeeder তাঁকে TDEPOT আর FMART দুইটাতেই বসায়।',
        ]));

        $this->assertFalse($group['single'], 'দুইটা কোম্পানি, তবু পাতাটা নিজেকে একক বলছে।');

        /*
         * ⭐ যোগফলটা আলাদাভাবে গোনা — সার্ভিসের নিজের যোগফল নয়।
         *
         * ⚠️ `$group['total']` ঠিক আছে কি না সেটা `$group['total']` দিয়ে
         * মেলালে দাবিটা কিছুই প্রমাণ করত না। ⓘ তাই সারিগুলো থেকে
         * bcmath-এ নতুন করে যোগ করে মেলানো হয়।
         */
        foreach (['income', 'expense', 'asset', 'liability', 'equity'] as $key) {
            $byHand = '0';

            foreach ($group['companies'] as $row) {
                $byHand = bcadd($byHand, $row[$key], 4);
            }

            $this->assertSame(0, bccomp($byHand, $group['total'][$key], 4), implode(PHP_EOL, [
                "`{$key}`-এর যোগফল সারিগুলোর যোগের সাথে মেলে না।",
                '',
                "সারি ধরে গুনে: {$byHand}",
                "পাতা বলছে  : {$group['total'][$key]}",
                '',
                '⚠️ একটা কোম্পানি যোগফলে বাদ পড়লে সংখ্যাটা ছোট হয়, আর',
                'ছোট সংখ্যাও দেখতে সঠিকের মতোই লাগে।',
            ]));
        }
    }

    /**
     * ⛔ এক কোম্পানির মানুষ অন্য কোম্পানির কিছুই পান না।
     */
    public function test_someone_in_one_company_never_sees_the_other(): void
    {
        $alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        CompanyContext::set((int) $alpha->id);

        /*
         * ⓘ হিসাবরক্ষক — DemoSeeder তাঁকে কেবল TDEPOT-এ বসায়।
         * ⚠️ নামটা এখানে টাইপ করা হয় না; পিভট ধরে খোঁজা হয়, তাই ডেমো
         * ডেটা বদলালে দাবিটা মিথ্যা সবুজ দেবে না।
         */
        $onlyAlpha = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey($alpha->id))
            ->whereDoesntHave('companies', fn ($q) => $q->whereKey($beta->id))
            ->firstOrFail();

        $group = app(GroupLedgerService::class)->build($onlyAlpha);

        $this->assertCount(1, $group['companies'], implode(PHP_EOL, [
            'এক কোম্পানির মানুষ '.count($group['companies']).'টা কোম্পানি দেখছেন।',
            '',
            '⛔ এটা টেন্যান্টের দেয়াল ফুটো হওয়া — আইনি সমস্যা, সুবিধার নয়।',
        ]));

        $this->assertTrue($group['single'], 'একটাই কোম্পানি, তবু পাতাটা সেটা বলছে না।');

        $names = array_column($group['companies'], 'name');

        $this->assertNotContains($beta->name_en, $names, implode(PHP_EOL, [
            "অন্য কোম্পানির নাম (`{$beta->name_en}`) সারিতে চলে এসেছে।",
            '',
            'ⓘ ছাঁকনিটা `company_user` পিভট ধরে হওয়া উচিত, কখনো সব কোম্পানি নয়।',
        ]));
    }

    /**
     * ⛔ পর্দাটা নিজের চাবি ছাড়া খোলে না।
     *
     * ⚠️ এই দাবিটা আলাদা করে দরকার: বাকি প্রতিটা রিপোর্ট
     * `accounts.report.final` চায়, আর সেই চাবি থাকলেই কেউ যেন
     * স্বয়ংক্রিয়ভাবে গ্রুপের ছবি না পান।
     */
    public function test_the_group_page_needs_its_own_key(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set((int) Company::query()->where('code', 'TDEPOT')->value('id'));

        $this->actingAs($owner);

        /* ⓘ মালিক super-admin, তাই তিনি পান — এটাই প্রত্যাশিত */
        $this->get(route('accounts.group_report'))->assertOk();

        /*
         * ⓘ এখন এমন একজন, যাঁর গ্রুপের চাবি নেই।
         * ⚠️ চূড়ান্ত রিপোর্টের চাবি থাকলেও যেন না পান — ঠিক সেটাই মাপা।
         */
        $alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $lesser = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey($alpha->id))
            ->where('email', '!=', 'owner@abos.test')
            ->firstOrFail();

        $lesser->givePermissionTo('accounts.report.final');
        $lesser->forgetCachedPermissions();

        $this->actingAs($lesser);

        $this->get(route('accounts.group_report'))->assertForbidden();
    }
}
