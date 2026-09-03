<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\SystemAdmin\Services\FirstRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * প্রথম দরজা — খোলে একবার, তারপর আর নেই।
 *
 * ── এই টেস্টটা কী পাহারা দেয় ────────────────────────────────────────
 * দরজাটা `auth`-এর বাইরে, আর সেটা থাকতেই হবে: নতুন ইনস্টলে লগইন করার
 * মতো কেউ নেই। কিন্তু ওই কারণেই এটা **অ্যাপের একমাত্র জায়গা যেখানে
 * অচেনা কেউ লিখতে পারে** — আর সেখানে একটা ভুল মানে যে কেউ ঠিকানাটা
 * টাইপ করে নিজেকে মালিক বানিয়ে নিতে পারে।
 *
 * ⚠️ **প্রতিটা assertion ইচ্ছে করে ভেঙে লাল হতে দেখা হয়েছে।** বিশেষ
 * করে দুই নম্বরটা: `abort_unless`-এর শর্ত উল্টে দিলে সেটা লাল হয়, আর
 * `FirstRun::isOpen()` সবসময় `true` ফেরত দিলেও লাল হয়। সবুজ কিন্তু
 * অন্ধ গার্ড অলংকার মাত্র।
 *
 * ⓘ এখানে `DemoSeeder` **ডাকা হয়নি**, আর সেটাই আসল কথা: এই টেস্টের
 * গোটা বিষয়বস্তুই হলো "কিছুই নেই" অবস্থাটা। সিডার চালালে ব্যবহারকারী
 * বসে যেত আর দরজাটা পরীক্ষার আগেই বন্ধ হয়ে থাকত।
 */
class TheFirstDoorOpensOnlyOnceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function form(): array
    {
        return [
            'name' => 'Al-Amin Shuvo',
            'email' => 'first@example.test',
            'password' => 'FirstOwner2026',
            'password_confirmation' => 'FirstOwner2026',
            'company_name' => 'Trade Depot',
            'branch_name' => 'Head Office',
        ];
    }

    public function test_the_door_is_there_when_nobody_has_ever_logged_in(): void
    {
        // ⚠️ শূন্যটা দেখে নেওয়া — খালি সংগ্রহের উপর চালানো assertion
        //    সবসময় সবুজ, তাই শর্তটা সত্যিই শূন্য কিনা আগে প্রমাণ করা।
        $this->assertSame(0, User::query()->count());

        $this->get('/setup')->assertOk()->assertSee('ABOS');
    }

    public function test_the_door_is_gone_the_moment_one_user_exists(): void
    {
        User::create([
            'name' => 'Somebody',
            'email' => 'somebody@example.test',
            'password' => 'Somebody2026',
            'locale' => 'bn',
            'is_active' => true,
        ]);

        $this->assertSame(1, User::query()->count());

        // ৪০৩ নয়, ৪০৪ — "এখানে কিছু নেই", "তুমি পাচ্ছ না" নয়
        $this->get('/setup')->assertNotFound();
        $this->post('/setup', $this->form())->assertNotFound();
    }

    public function test_walking_through_it_leaves_a_business_that_actually_works(): void
    {
        $this->post('/setup', $this->form())->assertRedirect(route('dashboard'));

        /*
         * শুধু ব্যবহারকারী আর কোম্পানি গুনলে যথেষ্ট হত না।
         *
         * এই ছয়টার একটাও বাদ পড়লে প্রতিষ্ঠানটা **তৈরি হয় কিন্তু কাজ
         * করে না**, আর সেটা টের পাওয়া যায় অনেক পরে — প্রথম বিল লিখতে
         * গিয়ে। ঠিক এই কারণেই CompanyProvisioner একটা সার্ভিস।
         */
        $user = User::query()->firstOrFail();
        $this->assertSame('first@example.test', $user->email);

        $company = Company::query()->firstOrFail();
        $this->assertSame('Trade Depot', $company->name_en);

        // কোড নাম থেকে বসেছে, `CMP-0001` নয় — মালিকের নিয়ম
        $this->assertNotSame('', $company->code);
        $this->assertStringNotContainsString('-0001', $company->code);

        CompanyContext::forCompany($company->id, function (): void {
            $this->assertGreaterThan(0, Branch::query()->count());
            $this->assertGreaterThan(0, FinancialYear::query()->count());
        });

        /*
         * ⚠️ রোলটা — এটাই সবচেয়ে নীরব ধাপ।
         *
         * `owner` রোল কেবল `DemoSeeder`-এ তৈরি হয়, আর প্রোডাকশনে সিডার
         * চলে না। `PermissionSyncer::keepOwnerComplete()` রোলটা না পেলে
         * চুপচাপ `0` ফেরত দেয়। তাই দরজাটা রোলটা নিজে না বানালে ক্রেতা
         * লগইন করতেন আর **প্রতিটা পর্দায় ৪০৩** পেতেন, কোনো ব্যাখ্যা
         * ছাড়াই।
         */
        $this->assertTrue($user->hasRole(PermissionSyncer::OWNER_ROLE));
        $this->assertTrue($user->fresh()->companies()->where('companies.id', $company->id)->exists());

        // যিনি বসালেন, তিনি ঢুকেই গেছেন — আবার পাসওয়ার্ড লিখতে হয় না
        $this->assertTrue(Auth::check());
    }

    public function test_the_second_walk_through_is_refused_by_the_database_not_the_screen(): void
    {
        /*
         * পর্দার `abort_unless` এড়িয়ে সরাসরি সার্ভিসে ডাকা — কারণ
         * পাহারাটা পর্দায় থাকলে যথেষ্ট নয়।
         *
         * ── কেন এটা দৌড়ের প্রমাণ নয়, আর সেটা স্বীকার করা দরকার ──────
         * সত্যিকারের দৌড় দুইটা সমসাময়িক ট্রানজ্যাকশন লাগে, আর টেস্ট
         * ক্রমে চলে — **তাই দৌড়টা কোনো টেস্টেই ধরা পড়বে না**, ধরতে হয়
         * কোড পড়ে (`FirstRun::open()`-এ `lockForUpdate()`)।
         *
         * এই টেস্টটা তার চেয়ে কম, কিন্তু শূন্য নয়: সে প্রমাণ করে
         * সিদ্ধান্তটা **সার্ভিসের ভিতরে**ও আছে, কেবল কন্ট্রোলারে নয়।
         * কেউ কন্ট্রোলার এড়িয়ে গেলেও দরজা দ্বিতীয়বার খোলে না।
         */
        app(FirstRun::class)->open($this->form());

        $this->assertFalse(app(FirstRun::class)->isOpen());

        $this->expectException(\RuntimeException::class);
        app(FirstRun::class)->open([...$this->form(), 'email' => 'second@example.test']);
    }

    /**
     * পুরো বাংলা নাম — কোড বসত খালি, আর কেউ জানত না।
     *
     * ── এটা ব্রাউজারে ধরা পড়েছে, কোড পড়ে নয় (৩ সেপ্টেম্বর ২০২৬) ──────
     * ফেলনা একটা ডাটাবেসে সত্যিকারের ইনস্টল করে দরজাটা দিয়ে হেঁটে
     * দেখা হয়েছিল, আর তারপর ডাটাবেসে গুনে দেখা গেল শাখার কোডের ঘরটা
     * ফাঁকা। কারণ: `CodeFromName::base()` ইংরেজি অক্ষর ছাড়া সব ফেলে
     * দেয়, আর ফর্মে কোডের কোনো ঘর নেই (মালিকের নিয়ম — ব্যবহারকারীকে
     * কোড বানাতে দেওয়া হয় না)।
     *
     * **কোথাও কিছু ভাঙত না** — শুধু প্রতিটা ডকুমেন্টের নম্বর একটা
     * হাইফেন দিয়ে শুরু হত, আর ধরা পড়ত ছয় মাস পর।
     */
    public function test_a_name_with_no_latin_letter_is_refused_with_a_reason(): void
    {
        $this->post('/setup', [...$this->form(), 'company_name' => 'ট্রেড ডিপো'])
            ->assertSessionHasErrors('company_name');

        $this->assertSame(0, User::query()->count());

        // ⚠️ বার্তাটা কারণ বলে, "ভুল ঘর" বলে থামে না
        $this->assertStringContainsString(
            'ইংরেজি অক্ষর',
            (string) session('errors')?->first('company_name'),
        );
    }

    public function test_no_code_is_ever_stored_empty(): void
    {
        $this->post('/setup', $this->form())->assertRedirect(route('dashboard'));

        $company = Company::query()->firstOrFail();
        $this->assertNotSame('', $company->code);

        CompanyContext::forCompany($company->id, function (): void {
            $branch = Branch::query()->firstOrFail();

            // খালি কোড মানে ডকুমেন্ট নম্বর হাইফেন দিয়ে শুরু
            $this->assertNotSame('', $branch->code);
        });
    }

    public function test_a_weak_password_is_refused(): void
    {
        /*
         * এই একটা অ্যাকাউন্টের হাতে গোটা প্রতিষ্ঠান, আর এটা এমন সময়ে
         * বসে যখন দুর্বল পাসওয়ার্ড ধরার মতো কোনো প্রশাসকই নেই।
         */
        $this->post('/setup', [...$this->form(), 'password' => 'abc', 'password_confirmation' => 'abc'])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, User::query()->count());
    }

    // ── অর্থবছর ও মুদ্রা, ৪ সেপ্টেম্বর ২০২৬ ────────────────────────────

    /**
     * কিছু না বললে বাংলাদেশের চলতি বছরটাই — আগের আচরণ অক্ষত।
     */
    public function test_the_year_falls_back_to_july_june_when_nothing_is_given(): void
    {
        $this->post('/setup', $this->form())->assertRedirect(route('dashboard'));

        $company = Company::query()->firstOrFail();

        CompanyContext::forCompany($company->id, function (): void {
            $year = FinancialYear::query()->where('is_current', true)->firstOrFail();

            $this->assertSame(7, $year->starts_on->month, 'ডিফল্ট বছরটা জুলাই থেকে শুরু হয়নি।');
            $this->assertSame(6, $year->ends_on->month, 'ডিফল্ট বছরটা জুনে শেষ হয়নি।');
        });
    }

    /**
     * ছোট প্রথম বছর — যেটার জন্য ঘর দুইটা বসানো হয়েছে।
     *
     * ── কেন এটাই আসল পরীক্ষা ─────────────────────────────────────────
     * *"একটা কোম্পানির প্রথম বছর কখনোই বারো মাস নয়।"* ফেব্রুয়ারিতে আসা
     * ক্রেতার বই ফেব্রুয়ারি–জুন। আগে ঘর ছিল না, তাই জুলাই ১ বসত — আর
     * খোলার জের পাঁচ মাস আগের তারিখে বসত, কারণ
     * `OpeningBalanceService::dateFor()` বছরের প্রথম দিন ধরে।
     *
     * ⚠️ তাই assertion শুধু তারিখে থামে না — **জেরটা সত্যিই ওই দিনে বসে
     * কিনা** সেটাও দেখা হয়। তারিখ ঠিক অথচ জের অন্য দিনে, এটাই হত নীরব
     * ভুল।
     */
    public function test_a_short_first_year_is_taken_exactly_as_given(): void
    {
        /*
         * তারিখগুলো **আজকের সাপেক্ষে**, স্থির নয়।
         *
         * ⚠️ প্রথমে এখানে '2026-02-01' → '2026-06-30' লেখা ছিল, আর টেস্টটা
         * ৪০৪ দিত — কারণ ৪ সেপ্টেম্বরে ওই বছরটা **শেষ হয়ে গেছে**, আর
         * চলতি বছর হিসেবে বসানো যায় না। স্থির তারিখ লিখলে টেস্টটা
         * ক্যালেন্ডারের সাথে সাথে অর্থ বদলায়: আজ যা "চলতি", ছয় মাস পরে
         * তা "অতীত"।
         *
         * বাস্তব দৃশ্যটাও এটাই — যিনি আজ ABOS-এ আসছেন তাঁর প্রথম বই আজ
         * থেকে অর্থবছরের শেষ পর্যন্ত, বারো মাস নয়।
         */
        $starts = now()->startOfMonth();
        $ends = $starts->copy()->addMonths(4)->endOfMonth();

        $this->post('/setup', [
            ...$this->form(),
            'year_starts_on' => $starts->toDateString(),
            'year_ends_on' => $ends->toDateString(),
        ])->assertRedirect(route('dashboard'));

        $company = Company::query()->firstOrFail();

        CompanyContext::forCompany($company->id, function () use ($starts, $ends): void {
            $year = FinancialYear::query()->where('is_current', true)->firstOrFail();

            $this->assertSame($starts->toDateString(), $year->starts_on->toDateString());
            $this->assertSame($ends->toDateString(), $year->ends_on->toDateString());

            /* পাঁচ মাসের বছর — জুলাই থেকে শুরু হওয়া ডিফল্টটা নয় */
            $this->assertNotSame(7, $year->starts_on->month);
        });
    }

    /**
     * অর্ধেক বছর গ্রহণ করা হয় না।
     *
     * শুরুটা দিয়ে শেষটা না দিলে বাকি অর্ধেক ডিফল্ট থেকে আসত — ক্রেতা
     * ভাবতেন তাঁর দেওয়া তারিখেই বছর, অথচ শেষটা জুন।
     */
    public function test_half_a_year_is_refused(): void
    {
        $this->post('/setup', [...$this->form(), 'year_starts_on' => '2026-02-01'])
            ->assertSessionHasErrors('year_ends_on');

        $this->assertSame(0, User::query()->count());
    }

    /**
     * বেছে নেওয়া মুদ্রাই খাতার ভিত্তি — কোম্পানির ঘরে **আর** তালিকায়।
     *
     * ⚠️ দুইটাই দেখা হয়, আর সেটাই এই পরীক্ষার কারণ: `companies.currency`
     * ঘরটা আগে থেকেই ছিল কিন্তু **কেউ পড়ত না** — ভিত্তি মুদ্রা আলাদাভাবে
     * `BDT` কোড ধরে বসানো হত। তখন পর্দায় পছন্দ নেওয়া হত আর বই চলত টাকায়।
     * ঘরটা মিলিয়ে না দেখলে ওই স্টাবটাই ফিরে আসতে পারে।
     */
    public function test_the_chosen_currency_becomes_the_base_of_the_books(): void
    {
        $this->post('/setup', [...$this->form(), 'currency' => 'USD'])
            ->assertRedirect(route('dashboard'));

        $company = Company::query()->firstOrFail();
        $this->assertSame('USD', $company->currency);

        CompanyContext::forCompany($company->id, function (): void {
            $base = \App\Modules\MasterData\Models\Currency::query()
                ->where('is_default', true)
                ->firstOrFail();

            $this->assertSame(
                'USD',
                $base->code,
                'কোম্পানির ঘরে USD, অথচ খাতার ভিত্তি মুদ্রা অন্য — দুই সূত্র দুই কথা বলছে।',
            );
        });
    }

    /**
     * তালিকার বাইরের মুদ্রা নেওয়া হয় না।
     *
     * নিলে কোম্পানির ঘরে এমন একটা কোড বসত যার কোনো সারি কোনোদিন বসবে
     * না, আর ভিত্তি মুদ্রা চুপচাপ টাকায় ফিরে যেত।
     */
    public function test_a_currency_nobody_seeds_is_refused(): void
    {
        $this->post('/setup', [...$this->form(), 'currency' => 'XYZ'])
            ->assertSessionHasErrors('currency');

        $this->assertSame(0, User::query()->count());
    }
}
