<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * একই কাগজ বারবার চাইলে ডাটাবেসে বারবার যাওয়া হয় না।
 *
 * ── কেন, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ⛔ [[drill]] কম্পোনেন্ট **প্রতিটা সারিতে** `describe()` ডাকে, আর সেটা
 * প্রতিবার `find()` করত। ⓘ ১০০ সারির খতিয়ানে ১০০টা কোয়েরি, আর
 * `drillLabel()` সম্পর্ক ছুঁলে আরও ১০০।
 *
 * ⚠️ একই কাগজের একাধিক সারি খুবই সাধারণ — একটা ভাউচারের ছয়টা লাইন
 * ছয়বার একই ডকুমেন্ট চাইত।
 *
 * ── ⓘ কেন কেবল স্মৃতি, দলবদ্ধ তোলা নয় ──────────────────────────────
 * ব্লেড সারি ধরে ডাকে, তাই `whereIn` দিয়ে একবারে তুলতে হলে রিপোর্ট
 * ইঞ্জিনকে আগে থেকে জানতে হত কোন কাগজগুলো লাগবে। ⭐ স্মৃতিটা তার
 * চেয়ে ছোট বদল, কোনো আচরণ বদলায় না, আর পুনরাবৃত্তিটুকু কেটে দেয় —
 * যা আসল খতিয়ানে সারির বড় অংশ।
 *
 * ⛔ আর বাঁধনটা ছাড়া স্মৃতিটা অর্থহীন ছিল: `app(DrillResolver::class)`
 * প্রতিবার নতুন খালি বস্তু বানাত, তাই স্মৃতি জন্মাত আর মরত একই সারিতে।
 */
final class TheLedgerAskedForEveryDocumentOneAtATimeTest extends TestCase
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

    /**
     * ⛔ একই কাগজ দশবার চাইলে ডাটাবেসে যাওয়া হয় একবার।
     */
    public function test_the_same_document_is_fetched_once_however_often_it_is_asked_for(): void
    {
        [$type, $id] = $this->aRealDocument();

        $resolver = app(DrillResolver::class);

        /*
         * ⭐ ধনাত্মক নিয়ন্ত্রণ, আর এটাই এই পরীক্ষার মেরুদণ্ড।
         *
         * ⛔ প্রথম খসড়ায় ফিক্সচার ছিল খতিয়ানের প্রথম সারিটা, আর ডেমোতে
         * সেটা `opening_stock` — যা কোনো মডিউল ড্রিল-উৎস হিসেবে ঘোষণা
         * করে না। ⚠️ তাই `resolve()` কোয়েরির **আগেই** থেমে যেত, আর
         * "দশবারে শূন্য কোয়েরি" দাবিটা স্মৃতির কারণে নয়, **কিছুই ঘটে
         * না বলে** সত্যি হত।
         *
         * ⓘ তাই আগে প্রমাণ: প্রথম ডাকে ডাটাবেসে সত্যিই যাওয়া হয়।
         */
        $first = 0;
        DB::listen(function () use (&$first) {
            $first++;
        });

        $resolver->describe($type, $id);

        $this->assertGreaterThan(0, $first, implode(PHP_EOL, [
            '⛔ প্রথম ডাকেই কোনো কোয়েরি যায়নি।',
            '',
            '⚠️ তাহলে নিচের "দশবারে শূন্য" দাবিটা কিছুই প্রমাণ করে না —',
            'যে কাজ কখনো ডাটাবেসে যায় না, সে দশবারেও যায় না।',
        ]));

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        for ($i = 0; $i < 10; $i++) {
            $resolver->describe($type, $id);
        }

        $this->assertSame(0, $queries, implode("\n", [
            "⛔ একই কাগজ দশবার চাওয়ায় {$queries}টা কোয়েরি গেছে।",
            '',
            'ⓘ স্মৃতিটা কাজ করছে না। প্রথম সন্দেহ: `DrillResolver` scoped',
            'হিসেবে বাঁধা আছে তো? বাঁধন ছাড়া প্রতিটা `app(...)` নতুন খালি',
            'বস্তু বানায়, আর স্মৃতি জন্মায় ও মরে একই সারিতে।',
        ]));
    }

    /**
     * ⛔ আর উত্তরটা আগের মতোই — স্মৃতি যেন নীরবে অন্য কিছু ফেরায় না।
     */
    public function test_the_remembered_answer_is_the_same_answer(): void
    {
        [$type, $id] = $this->aRealDocument();

        $resolver = app(DrillResolver::class);

        $first = $resolver->describe($type, $id);
        $again = $resolver->describe($type, $id);

        $this->assertSame($first, $again, 'দ্বিতীয়বার অন্য উত্তর এসেছে — স্মৃতিটা মিথ্যা বলছে।');
    }

    /**
     * ⭐ এক অনুরোধে এক বস্তু — নাহলে উপরের দাবিটা কিছুই মানে না।
     *
     * ⓘ `scoped`, `singleton` নয়: উৎস-ডকুমেন্টগুলো কোম্পানি-স্কোপে বাছা,
     * আর পরের অনুরোধ অন্য কোম্পানির হতে পারে।
     */
    public function test_one_resolver_serves_the_whole_request(): void
    {
        $this->assertSame(
            app(DrillResolver::class),
            app(DrillResolver::class),
            '⛔ দুইবার `app()` ডাকায় দুইটা আলাদা বস্তু — বাঁধনটা নেই।'
        );
    }

    /** ⓘ ভুলিয়ে দেওয়ার দরজাটা সত্যিই কাজ করে। */
    public function test_forgetting_makes_it_ask_again(): void
    {
        [$type, $id] = $this->aRealDocument();

        $resolver = app(DrillResolver::class);
        $resolver->describe($type, $id);

        $resolver->forget();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $resolver->describe($type, $id);

        $this->assertGreaterThan(0, $queries, 'ভুলিয়ে দেওয়ার পরেও ডাটাবেসে যাওয়া হয়নি।');
    }

    /**
     * এমন একটা কাগজ যা সত্যিই **নিবন্ধিত ড্রিল-উৎস**, আর ডেমোতে আছে।
     *
     * ⓘ গ্রাহক বাছা হয়েছে কারণ ডেমোতে গ্রাহক আছে আর `customer` প্রতিটা
     * মডিউলের `drill_sources`-এ ঘোষিত। ⚠️ খতিয়ানের যেকোনো সারি ধরলে
     * ডেমোতে `opening_stock` আসত, যা ঘোষিত নয় — আর তখন পরীক্ষাটা
     * কিছুই মাপত না।
     *
     * @return array{0: string, 1: int}
     */
    private function aRealDocument(): array
    {
        $customer = Customer::query()->orderBy('id')->first();

        $this->assertNotNull($customer, 'ডেমোতে একটাও গ্রাহক নেই — ফিক্সচারটাই নেই।');

        return [Customer::drillSourceType(), (int) $customer->id];
    }
}
