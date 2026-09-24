<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নতুন কারণটা চলমান কোম্পানিতে কোনোদিন পৌঁছাত না।
 *
 * ── ⛔ ফাঁকটা ─────────────────────────────────────────────────────────
 * [[MasterListService::installDefaults()]] চলে কেবল নতুন কোম্পানি তৈরির
 * সময়, আর ভিতরের `seed()` শুরুতেই থেমে যায় যদি তালিকায় **একটাও** সারি
 * থাকে। ⚠️ তাই পরে যোগ করা কোনো কারণ পুরনো কোম্পানিতে বসত না।
 *
 * ── ⚠️ আর ব্যর্থতাটা সম্পূর্ণ নীরব ───────────────────────────────────
 * কিছুই ভাঙত না: কোড না পেলে [[StockTransferService::onTheWay()]] চুপচাপ
 * `null` ফেরায়, আর আটকানোর রিপোর্টে কারণের ঘরটা ফাঁকা থাকে — ঠিক যেমন
 * আগে ছিল। ⛔ অর্থাৎ ফিচারটা লাইভে **কিছুই করত না**, আর কোথাও লাল হত না।
 *
 * ── ⭐ এই ফাইল যা পাহারা দেয় ─────────────────────────────────────────
 *   ১. ভরা তালিকা থেকে একটা কারণ হারিয়ে গেলে সিঙ্ক সেটা ফিরিয়ে আনে
 *   ২. বসানো কারণগুলোয় হাত পড়ে না — নাম বদলালেও নয়
 *   ৩. দুইবার চালালে দ্বিতীয়বার কিছুই যোগ হয় না
 *
 * ⓘ (১)-এর ছকটাই আসল: তালিকাটা **খালি নয়**, কেবল একটা সারি কম। ⚠️ খালি
 * তালিকা দিয়ে মাপলে পাহারাটা সবুজ থাকত অথচ আসল অবস্থাটা কোনোদিন ছোঁয়া
 * হত না — কারণ `seed()` খালি তালিকাতেও কাজ করে, ভরা তালিকাতেই থামে।
 */
final class TheNewReasonNeverReachedTheRunningCompaniesTest extends TestCase
{
    use RefreshDatabase;

    /** ⓘ ২৫ সেপ্টেম্বরে যোগ হওয়া কারণ — এটাই ফাঁকটা ধরিয়ে দিয়েছিল। */
    private const LATE_ARRIVAL = 'HOLD-TRN';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    // ── ১ · হারানো সারিটা ফিরে আসে ───────────────────────────────────

    public function test_a_reason_missing_from_a_full_list_is_put_back(): void
    {
        /*
         * ⭐ **ভরা তালিকা থেকে একটা মুছে** ঠিক লাইভের অবস্থাটা বানানো
         * হয়: বাকি সব কারণ আছে, কেবল নতুনটা নেই।
         *
         * ⛔ তালিকাটা খালি করে মাপলে কিছুই প্রমাণ হত না — `seed()` খালি
         * তালিকায় এমনিতেই কাজ করে, আর যে অবস্থাটা ভাঙে সেটা ছোঁয়াই হত না।
         */
        ReasonCode::query()->where('code', self::LATE_ARRIVAL)->forceDelete();

        $this->assertSame(0, ReasonCode::query()->where('code', self::LATE_ARRIVAL)->count(),
            'পরীক্ষার শুরুর অবস্থাটাই বসেনি — কারণটা মুছতেই পারেনি।');

        $this->assertGreaterThan(0, ReasonCode::query()->count(),
            'তালিকাটা খালি হয়ে গেছে, অথচ আসল বিপদটা **ভরা** তালিকায়।');

        $added = app(MasterListService::class)->installMissingReasons();

        $this->assertSame(1, $added);

        $this->assertSame(1, ReasonCode::query()->where('code', self::LATE_ARRIVAL)->count(),
            'ভরা তালিকায় অনুপস্থিত কারণটা সিঙ্কের পরেও বসেনি — তাহলে '
            .'চলমান কোম্পানিতে ফিচারটা চিরকাল চুপচাপ কিছুই করত না।');
    }

    public function test_the_restored_reason_keeps_the_meaning_it_was_given(): void
    {
        /*
         * ⓘ কেবল সারিটা থাকলেই হয় না — ⚠️ `returns_to_stock` মিথ্যা হলে
         * ট্রাক ফিরে আসা মালটা অবিক্রেয় ধরা হত, অথচ মালে কোনো দোষ নেই।
         */
        ReasonCode::query()->where('code', self::LATE_ARRIVAL)->forceDelete();

        app(MasterListService::class)->installMissingReasons();

        $back = ReasonCode::query()->where('code', self::LATE_ARRIVAL)->firstOrFail();

        $this->assertSame(ReasonCode::HOLD, $back->context);
        $this->assertTrue((bool) $back->returns_to_stock);
    }

    // ── ২ · বসানো সারিতে হাত পড়ে না ──────────────────────────────────

    public function test_a_renamed_reason_is_left_alone(): void
    {
        /*
         * ⛔ কোম্পানি একটা কারণের নাম নিজের মতো বদলে থাকতে পারেন, আর
         * সিঙ্ক সেটা উল্টে দিলে তাঁর কাজ নষ্ট হত। ⚠️ আর নষ্টটা নীরব:
         * তিনি পরের দিন দেখতেন নামটা আবার আগেরটা।
         */
        $kept = ReasonCode::query()->where('code', 'HOLD-DMG')->firstOrFail();
        $kept->forceFill(['name_bn' => 'ভেঙে গেছে — আমাদের নিজের কথা'])->save();

        app(MasterListService::class)->installMissingReasons();

        $this->assertSame('ভেঙে গেছে — আমাদের নিজের কথা',
            $kept->fresh()->name_bn,
            'সিঙ্ক কোম্পানির নিজের লেখা নামটা মুছে দিয়েছে।');
    }

    public function test_a_reason_the_company_deleted_stays_deleted(): void
    {
        /*
         * ⭐ এটাই সবচেয়ে সহজে ভুল হওয়া ঘরটা — আর লেখার সময় আমি ভুলই
         * করেছিলাম।
         *
         * ⓘ কারণ-কোড soft-delete করে। ⛔ অনুপস্থিত কোডের তালিকাটা
         * সাধারণ কোয়েরিতে বানালে মুছে ফেলা সারিটা "নেই" মনে হয়, আর
         * সিঙ্ক ওটা **আবার বসিয়ে দেয়** — অর্থাৎ কোম্পানি যে কারণটা
         * ইচ্ছাকৃতভাবে সরিয়েছেন সেটাই পরদিন তালিকায় ফিরে আসত।
         *
         * ⚠️ ঠিক এই আচরণটা এড়াতেই `seed()` যোগমুখী করা হয়নি; একই ভুল
         * পিছনের দরজা দিয়ে ঢুকছিল।
         */
        $unwanted = ReasonCode::query()->where('code', 'HOLD-PRICE')->firstOrFail();
        $unwanted->delete();

        $this->assertSame(0, ReasonCode::query()->where('code', 'HOLD-PRICE')->count(),
            'পরীক্ষার শুরুর অবস্থাটাই বসেনি — সারিটা soft-delete হয়নি।');

        app(MasterListService::class)->installMissingReasons();

        $this->assertSame(0, ReasonCode::query()->where('code', 'HOLD-PRICE')->count(),
            'কোম্পানির সরিয়ে দেওয়া কারণটা সিঙ্ক আবার বসিয়ে দিয়েছে — '
            .'একটা ফাঁকা কলামের চেয়ে নিজে থেকে ফিরে আসা সারি অনেক খারাপ, '
            .'কারণ ওটা কেউ দেখে না।');
    }

    // ── ৩ · দুইবার চালালে দ্বিতীয়বার কিছুই নয় ────────────────────────

    public function test_running_it_twice_adds_nothing_the_second_time(): void
    {
        ReasonCode::query()->where('code', self::LATE_ARRIVAL)->forceDelete();

        $this->assertSame(1, app(MasterListService::class)->installMissingReasons());
        $this->assertSame(0, app(MasterListService::class)->installMissingReasons(),
            'দ্বিতীয়বার চালিয়েও সারি যোগ হচ্ছে — ডিপ্লয়ে প্রতিবার চলে, '
            .'তাই কয়েক দিনেই তালিকাটা নকলে ভরে যেত।');
    }

    // ── তালিকাটা সত্যিই দুই জায়গা থেকে পড়া যায় ──────────────────────

    public function test_the_canonical_list_is_readable_without_provisioning(): void
    {
        /*
         * ⓘ আগে তালিকাটা [[installDefaults()]]-এর শরীরের ভিতরে লেখা
         * ছিল, তাই বাইরে থেকে পড়ার কোনো উপায়ই ছিল না — ⛔ আর সেটাই
         * গোটা ফাঁকটার মূল কারণ।
         */
        $codes = array_column(MasterListService::reasonRows(), 0);

        $this->assertContains(self::LATE_ARRIVAL, $codes);
        $this->assertSame(array_unique($codes), $codes,
            'আদি তালিকায় একই কোড দুইবার আছে — সিঙ্ক তখন কোনটা বসাবে?');
    }
}
