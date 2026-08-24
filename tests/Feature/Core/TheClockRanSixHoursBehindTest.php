<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\Company;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * অ্যাপের ঘড়ি ছয় ঘণ্টা পিছিয়ে চলছিল।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * `config/app.php`-এ `'timezone' => 'UTC'` **হাতে বসানো** ছিল — Laravel-এর
 * নিজের কঙ্কালে ওখানে `env('APP_TIMEZONE', …)` থাকে। ফলে লাইভ ও ডেভ
 * দুই মেশিনের `.env`-এ `APP_TIMEZONE=Asia/Dhaka` লেখা থাকা সত্ত্বেও
 * অ্যাপ চলত UTC-তে।
 *
 * দুইটা ঘোষণা, দুইটাই ঠিক, আর দুইটাই কেউ পড়ত না।
 *
 * ── কেন কেউ ধরতে পারেনি ─────────────────────────────────────────────
 * কোথাও কোনো ভুল বার্তা নেই। সব সময় নিজেদের মধ্যে সঙ্গতিপূর্ণ — কেবল
 * বাস্তব ঘড়ি থেকে ছয় ঘণ্টা সরানো। ধরা পড়েছে ব্যাকআপ দেখতে গিয়ে:
 * "রোজ রাত ১:৩০-এর ব্যাকআপ" ফাইলের নামে ০১:৩০ লেখা, অথচ ফাইলটা তৈরি
 * হয়েছে ঢাকার সকাল ৭:৩০-এ।
 *
 * ── দামটা কত ────────────────────────────────────────────────────────
 * ঢাকার মধ্যরাত থেকে ভোর ৬টা পর্যন্ত অ্যাপের কাছে **আগের দিন**।
 * ডিপোর কাউন্টার ভোরে খোলে, তাই ওটা তাত্ত্বিক নয়:
 *
 *   · সকাল ১০টার বিলে ছাপা হত ভোর ৪টা
 *   · ভোরের বিক্রি আগের দিনের হিসাবে বসত
 *   · ব্যাক-ডেট লক আর দিন-বন্ধ ভুল দিনের সীমা ধরত
 *
 * আজ ২৫/৮/২০২৬ — লাইভে আসল ব্যবসার তথ্য ঢোকার প্রথম দিন। এর আগের
 * সব সারি পরীক্ষার, তাই ঘড়ি বদলানোর খরচ আজ শূন্য; কাল থেকে নয়।
 */
class TheClockRanSixHoursBehindTest extends TestCase
{
    use RefreshDatabase;

    /**
     * অ্যাপ ডিপোর ঘড়িতেই চলে।
     *
     * এটাই মূল দাবি — বাকিগুলো এর ফল।
     */
    public function test_the_app_runs_on_the_depot_clock(): void
    {
        $this->assertSame('Asia/Dhaka', config('app.timezone'));
        $this->assertSame('Asia/Dhaka', date_default_timezone_get());
        $this->assertSame('Asia/Dhaka', Carbon::now()->timezone->getName());
    }

    /**
     * ঘোষণাটা `.env` থেকেই পড়া হয়।
     *
     * ── কেন ফাইলের লেখা পড়ে দেখা, আচরণ নয় ───────────────────────────
     * ভাঙা অবস্থাতেও উপরের পরীক্ষাটা পাশ করানো যেত — কেউ `'Asia/Dhaka'`
     * হাতে বসিয়ে দিলেই। কিন্তু তখন লাইভের `.env` আবার নীরবে অগ্রাহ্য
     * হত, আর দ্বিতীয় দেশে বা দ্বিতীয় সার্ভারে ঠিক একই ভুল ফিরে আসত।
     *
     * ভুলটা ছিল **হাতে বসানো মান**, তাই পরীক্ষাটাও ওটাকেই ধরে।
     */
    public function test_the_timezone_is_read_from_the_environment(): void
    {
        $source = file_get_contents(config_path('app.php'));

        $this->assertStringContainsString(
            "'timezone' => env('APP_TIMEZONE'",
            (string) $source,
            'config/app.php আবার হাতে বসানো মানে ফিরে গেছে — .env তখন অগ্রাহ্য হবে।',
        );
    }

    /**
     * `.env.example`-এও ঘোষণাটা আছে।
     *
     * নতুন একটা মেশিন এই ফাইল কপি করে শুরু করে। এখানে না থাকলে পরের
     * সার্ভারটা আবার UTC-তে চলত, আর ভুলটা আবার নীরব হত।
     */
    public function test_a_new_machine_starts_on_the_same_clock(): void
    {
        $this->assertStringContainsString(
            'APP_TIMEZONE=Asia/Dhaka',
            (string) file_get_contents(base_path('.env.example')),
        );
    }

    /**
     * ভোরের একটা মুহূর্ত আগের দিনে পড়ে যায় না।
     *
     * ── কেন ঠিক এই মুহূর্তটা ─────────────────────────────────────────
     * ঢাকার ২৫ তারিখ ভোর ৫টা মানে UTC-তে ২৪ তারিখ রাত ১১টা। ভাঙা
     * অবস্থায় `today()` বলত ২৪ — অর্থাৎ ভোরে কাটা প্রতিটা বিল আগের
     * দিনের খাতায়। দিন-বন্ধ হয়ে যাওয়া একটা দিনে বিল বসা মানে হিসাব
     * আর মেলে না।
     */
    public function test_an_early_morning_moment_stays_on_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 23:00:00', 'UTC'));

        $this->assertSame('2026-08-25', Carbon::now()->toDateString(),
            'ভোরের মুহূর্তটা আগের দিনে পড়ে গেছে।');
        $this->assertSame('2026-08-25', Carbon::today()->toDateString());
        $this->assertSame('05:00', Carbon::now()->format('H:i'));

        Carbon::setTestNow();
    }

    /**
     * লেখা সারিতেও ডিপোর ঘড়িই বসে।
     *
     * উপরেরগুলো মাপে অ্যাপ কী **ভাবে**; এটা মাপে ডাটাবেজে কী **বসে** —
     * আলাদা প্রশ্ন, কারণ Eloquent লেখার সময় নিজের মতো রূপান্তর করে।
     */
    public function test_a_row_written_now_carries_the_depot_clock(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $company->touch();

        $written = Carbon::parse((string) $company->fresh()->updated_at->toDateTimeString(), 'Asia/Dhaka');

        $this->assertLessThan(120, abs($written->diffInSeconds(Carbon::now())),
            'সারিতে বসা সময়টা ডিপোর ঘড়ির সাথে মেলে না।');
    }

    /**
     * প্রতিটা কোম্পানির ঘড়ি একটাই।
     *
     * ── কেন এই ঘরটা আজ একটা ঘোষণা, বিকল্প নয় ─────────────────────────
     * `companies.timezone` ঘরটা আছে, ডিফল্ট `Asia/Dhaka`, আর আজ পর্যন্ত
     * **কোনো কোয়েরি সেটা পড়ে না**। ঘরটাকে সত্যিই মানতে গেলে প্রতি
     * অনুরোধে ঘড়ি বদলাতে হত — আর তখন একই টেবিলে দুই ঘড়ির সময় জমা হত,
     * অথচ MySQL-এর DATETIME-এ কোনো অফসেট থাকে না। কোন সারিটা কোন ঘড়ির,
     * সেটা আর কোনোদিন বলা যেত না।
     *
     * তাই ঘরটা আজ একটা **যাচাই করা ঘোষণা**: সব কোম্পানির ঘড়ি config-এর
     * ঘড়ির সমান। একদিন সত্যিই দ্বিতীয় দেশ এলে এই পরীক্ষাটাই আগে ভাঙবে,
     * আর তখন সিদ্ধান্তটা জেনেশুনে নেওয়া হবে — আবিষ্কার হবে না।
     *
     * ঘোষণা আর নীরব ফাঁকের পার্থক্য ঠিক এটুকুই।
     */
    public function test_every_company_keeps_the_same_clock(): void
    {
        $this->seed(DemoSeeder::class);

        $odd = Company::query()->withoutGlobalScopes()
            ->where('timezone', '!=', config('app.timezone'))
            ->pluck('timezone', 'code')->all();

        $this->assertSame([], $odd, implode("\n", [
            'এই কোম্পানিগুলোর ঘড়ি অ্যাপের ঘড়ির সাথে মেলে না:',
            ...array_map(fn ($c, $t) => "  {$c} → {$t}", array_keys($odd), $odd),
            '',
            'একই টেবিলে দুই ঘড়ির সময় জমা হলে কোন সারিটা কোন ঘড়ির, বলা যাবে না।',
        ]));
    }
}
