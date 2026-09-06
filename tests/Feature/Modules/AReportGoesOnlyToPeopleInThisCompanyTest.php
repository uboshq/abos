<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\ReportSchedule;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * রিপোর্ট কেবল এই কোম্পানির মানুষের কাছেই যায়।
 *
 * ── ⛔ কী ভাঙা ছিল, ৭ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * নির্ধারিত রিপোর্ট প্রতি সপ্তাহে নিজে থেকে ইমেইলে যায় — বিক্রি, বকেয়া,
 * মজুদ, সব। ⚠️ কারা পাবেন তা ঠিক হয় একটা আইডির তালিকায়, আর সেই তালিকায়
 * **দুইটা তালা দরকার ছিল, একটাও ছিল না**:
 *
 *     ড্রপডাউন            ✅ ছাঁকা (৬ সেপ্টেম্বর বসানো)
 *     যাচাই                ⛔ ['integer'] — যেকোনো আইডি
 *     পাঠানোর মুহূর্ত       ⛔ whereIn('id', $ids) — ছাঁকনি নেই
 *
 * ⓘ পর্দা ছেঁকে দেওয়ায় মনে হয়েছিল কাজ শেষ। ⛔ কিন্তু **তালিকা ছাঁকা ভুল
 * ঠেকায়, দরজা পাহারা আক্রমণ ঠেকায়** — হাতে একটা অনুরোধ বানালে অন্য
 * কোম্পানির যে কাউকে প্রাপক বসিয়ে দেওয়া যেত।
 *
 * ── ⚠️ আর কোডে লেখা ছিল যে তালাটা আছে ───────────────────────────────
 * `ReportSchedule::recipientUsers()`-এ দাবি ছিল *"global scope-ই ছেঁকে
 * বাদ দেয়"*। ⛔ মেপে দেখা গেল **এমন কোনো scope নেই** — `User extends
 * Authenticatable`, [[BaseEntity]] নয়, নিজের `company_id` কলামও নেই।
 *
 * ⭐ এই ছাঁচটাই এই রিপোর সবচেয়ে ব্যয়বহুল: **নিয়মটা মন্তব্যে আছে, কোডে
 * নেই**, আর মন্তব্যটা এতই নিশ্চিত ভঙ্গিতে লেখা যে পড়ে কেউ যাচাই করেন না।
 * ⓘ একই দিনে এটা দুইবার ধরা পড়েছে — অন্যটা ধারের সীমায়, নগদ বিক্রি নিয়ে
 * ([[NoLimitMeansNoCreditNotNoSaleTest]])।
 *
 * ── কেন দুইটা আলাদা দাবি ────────────────────────────────────────────
 * ⚠️ যাচাই কেবল **আজকের পরে** সংরক্ষিত সারিগুলোকে ধরে। ⓘ আগে বসানো
 * সময়সূচিগুলো ওই যাচাই কোনোদিন দেখেনি, আর সেগুলো **প্রতি সপ্তাহে নিজে
 * থেকে চলে** — কেউ কিছু না চাপলেও। তাই দ্বিতীয় তালাটা পাঠানোর মুহূর্তে।
 */
class AReportGoesOnlyToPeopleInThisCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Company $mine;

    private Company $theirs;

    /** ⚠️ কেবল `$theirs`-এ আছেন — `$mine`-এ নেই। */
    private User $outsider;

    private User $insider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        /*
         * ⓘ FMART-কে "আমার" ধরা, TDEPOT-কে "অন্যের"।
         *
         * ⚠️ সিডারে হিসাবরক্ষক ও বিক্রয়কর্মী **কেবল TDEPOT-এ**; মালিক
         * দুইটাতেই। ⭐ তাই বাইরের লোক আর ভেতরের লোক দুইজনকেই সত্যিকারের
         * সারি থেকে পাওয়া যায় — পরীক্ষার জন্য বানানো নয়।
         */
        $this->mine = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->theirs = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $this->outsider = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->insider = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /*
         * ⚠️ `CompanyContext::set()` HTTP অনুরোধে টেকে না — ৭ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ প্রথমে কেবল `set()` করেছিলাম, আর দাবিটা **ভুলভাবে পাশ করেছিল**:
         * অনুরোধটা মিডলওয়্যারে প্রসঙ্গটা ব্যবহারকারীর `current_company_id`
         * থেকে **আবার বসায়**, আর সেটা তখনো TDEPOT ছিল। ⛔ ফলে "বাইরের লোক"
         * আসলে ভেতরেরই ছিলেন, আর ছাঁকনিটা ঠিকই তাঁকে ছেড়ে দিয়েছিল।
         *
         * ⭐ `switchCompany()` সারিটাও বদলায়, প্রসঙ্গও — তাই অনুরোধের পরেও
         * টেকে। ⚠️ পাশাপাশি সে নিজেই যাচাই করে ব্যবহারকারী ওই কোম্পানিতে
         * আছেন কি না, তাই সেটআপটা নিঃশব্দে মিথ্যা হতে পারে না।
         */
        $this->insider->switchCompany($this->mine->id);
        $this->actingAs($this->insider);
    }

    /** ⓘ ভিত্তিটা সত্যি কি না — নাহলে নিচের সবগুলো অর্থহীন। */
    public function test_the_outsider_really_is_outside_this_company(): void
    {
        $this->assertTrue(
            $this->outsider->companies()->whereKey($this->theirs->id)->exists(),
            'বাইরের লোকটি অন্য কোম্পানিতেই নেই — তাহলে এই পরীক্ষাগুলো কিছুই মাপছে না।',
        );

        $this->assertFalse(
            $this->outsider->companies()->whereKey($this->mine->id)->exists(),
            "বাইরের লোকটি চলতি কোম্পানিতেও আছেন, অর্থাৎ তিনি বাইরের নন।\n"
            .'সিডার বদলে থাকলে অন্য একজন বাছুন — দাবিগুলো তুলবেন না।',
        );

        $this->assertTrue(
            $this->insider->companies()->whereKey($this->mine->id)->exists(),
            'ভেতরের লোকটিই চলতি কোম্পানিতে নেই — তাহলে "ছাঁকনি সবাইকে বাদ দেয়নি" প্রমাণ হয় না।',
        );
    }

    // ── তালা ১ · যাচাই ─────────────────────────────────────────────────

    /**
     * ⛔ অন্য কোম্পানির একজনকে প্রাপক বানানোর অনুরোধ ফিরিয়ে দেওয়া হয়।
     *
     * ⚠️ অনুরোধটা হাতে বানানো — ঠিক যেভাবে কেউ পর্দা এড়িয়ে যেতেন। ⓘ
     * ড্রপডাউনে নামটা ছিলই না, তাই এই পথটাই একমাত্র পথ।
     */
    public function test_an_outsider_cannot_be_saved_as_a_recipient(): void
    {
        $this->post(route('system_admin.reports.schedule.store'), [
            'report_key' => $this->someReportKey(),
            'format' => 'csv',
            'frequency' => 'daily',
            'at_time' => '06:00',
            'recipients' => [$this->outsider->id],
        ])->assertSessionHasErrors('recipients.0');

        $this->assertSame(
            0,
            ReportSchedule::query()->count(),
            'অনুরোধটা ভুল বলা হয়েছে অথচ সময়সূচিটা তবু বসে গেছে।',
        );
    }

    /** ⭐ আর নিজের কোম্পানির একজন দিব্যি বসেন — ছাঁকনিটা সবাইকে বাদ দেয় না। */
    public function test_someone_in_this_company_is_accepted(): void
    {
        $this->post(route('system_admin.reports.schedule.store'), [
            'report_key' => $this->someReportKey(),
            'format' => 'csv',
            'frequency' => 'daily',
            'at_time' => '06:00',
            'recipients' => [$this->insider->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, ReportSchedule::query()->count());
    }

    // ── তালা ২ · পাঠানোর মুহূর্ত ───────────────────────────────────────

    /**
     * ⛔ **আসল দাবিটা এটাই।**
     *
     * ⓘ আজকের যাচাই আগামীকালের সারিগুলো ধরে; কিন্তু যেগুলো **আগেই বসে
     * আছে** সেগুলো ওই যাচাই কোনোদিন দেখেনি। ⚠️ আর সেগুলো প্রতি সপ্তাহে
     * নিজে থেকে চলে — কেউ কিছু না চাপলেও।
     *
     * ⭐ তাই সারিটা এখানে **সরাসরি বসানো**, যাচাইকে পাশ কাটিয়ে — ঠিক
     * যেভাবে ৭ সেপ্টেম্বরের আগের সারিগুলো আছে।
     */
    public function test_an_outsider_already_saved_still_never_receives_the_file(): void
    {
        $schedule = ReportSchedule::query()->create([
            'company_id' => $this->mine->id,
            'report_key' => $this->someReportKey(),
            'format' => 'csv',
            'frequency' => 'daily',
            'at_time' => '06:00',
            'recipients' => [$this->outsider->id, $this->insider->id],
            'created_by' => $this->insider->id,
            'is_active' => true,
        ]);

        $got = $schedule->recipientUsers()->pluck('id')->all();

        $this->assertNotContains(
            $this->outsider->id,
            $got,
            "আগে থেকে বসানো একটা সময়সূচি এখনো অন্য কোম্পানির একজনকে প্রাপক ধরছে।\n"
            ."যাচাই কেবল নতুন সারি ধরে; এই সারিগুলো প্রতি সপ্তাহে নিজে থেকেই চলে,\n"
            .'আর সাথে পুরো রিপোর্টটা — বিক্রি, বকেয়া, মজুদ — ইমেইলে চলে যায়।',
        );

        $this->assertContains(
            $this->insider->id,
            $got,
            "ছাঁকনিটা নিজের কোম্পানির লোককেও বাদ দিয়েছে — অর্থাৎ রিপোর্ট কারও কাছেই যেত না।\n"
            .'⚠️ সব বন্ধ করে দেওয়া ছাঁকনিও "নিরাপদ" দেখায়, আর ব্যর্থতাটা ধরা পড়ে অনেক পরে।',
        );
    }

    /**
     * ⚠️ ছাঁকনিটা **সারির নিজের কোম্পানি** ধরে, চলতি প্রসঙ্গ ধরে নয়।
     *
     * ⓘ [[ScheduledReportRunner]] প্রতিটা সময়সূচির পর `CompanyContext::
     * clear()` করে। ⛔ প্রসঙ্গ ধরে ছাঁকলে ক্রনে ওটা খালি থাকত, ছাঁকনি
     * **সবাইকে** বাদ দিত, আর রিপোর্ট কারও কাছে যেত না — কোনো ত্রুটিবার্তা
     * ছাড়াই।
     */
    public function test_it_works_with_no_company_in_context_the_way_the_cron_runs(): void
    {
        $schedule = ReportSchedule::query()->create([
            'company_id' => $this->mine->id,
            'report_key' => $this->someReportKey(),
            'format' => 'csv',
            'frequency' => 'daily',
            'at_time' => '06:00',
            'recipients' => [$this->outsider->id, $this->insider->id],
            'created_by' => $this->insider->id,
            'is_active' => true,
        ]);

        CompanyContext::clear();

        $got = $schedule->recipientUsers()->pluck('id')->all();

        $this->assertSame(
            [$this->insider->id],
            $got,
            "প্রসঙ্গ খালি থাকলে ছাঁকনিটা ভুল উত্তর দিচ্ছে — আর ক্রন ঠিক এভাবেই চলে।\n"
            .'সবাইকে বাদ দিলে রিপোর্ট বন্ধ; কাউকে বাদ না দিলে বাইরের লোকও পেয়ে যান।',
        );
    }

    /**
     * ⚠️ একটা রিপোর্ট **যার ঘোষিত অনুমতি আছে** — আর সেটা হাতে বসাতে হয়।
     *
     * ── ⛔ কেন, ৭ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
     * প্রথমে `keys()[0]` নিয়েছিলাম, আর দাবিটা ব্যর্থ হয়েছিল **অন্য একটা
     * কারণে**: [[ScheduleService::assertRecipientsAllowed()]] বলে *"এই
     * রিপোর্টের কোনো ঘোষিত দর্শক নেই, তাই এটা কেবল নিজের জন্য সূচি করা
     * যায়"*।
     *
     * ⭐ মেপে দেখা গেল **আজ একটা রিপোর্টও `permission` ঘোষণা করে না** —
     * পুরো রিপোতে `new ReportDefinition(... permission: ...)` শূন্যবার।
     * ⓘ অর্থাৎ আজ কোনো সূচিতেই নির্মাতা ছাড়া অন্য কেউ প্রাপক হতে পারেন
     * না, আর দরজাটা **অন্য একটা ছিটকিনিতে** বন্ধ।
     *
     * ── ⚠️ তবু ছাঁকনিটা রাখা হচ্ছে, আর পরীক্ষাটাও ─────────────────────
     * ⓘ ঘরটা নকশাতেই আছে, অর্থাৎ একদিন কেউ একটা রিপোর্টে অনুমতি বসাবেন —
     * আর সেই দিন দরজাটা খুলে যাবে, নীরবে। ⛔ তখন যে পাহারাটা থাকবে সেটা
     * **অনুমতি** দেখে, আর অনুমতি সব কোম্পানিতে এক (spatie teams বন্ধ) —
     * তাই একই ভূমিকাধারী বাইরের লোক দিব্যি পাশ করে যেতেন।
     *
     * ⭐ তাই এখানে সেই দিনটা **বানিয়ে নেওয়া হয়** — একটা অনুমতিওয়ালা
     * রিপোর্ট বসিয়ে। ⚠️ নাহলে পরীক্ষাটা সবুজ থাকত অথচ কিছুই মাপত না,
     * কারণ প্রত্যাখ্যানটা আসত সম্পূর্ণ অন্য নিয়ম থেকে।
     */
    private function someReportKey(): string
    {
        $key = 'test.shareable';
        $engine = app(ReportEngine::class);

        if (! in_array($key, $engine->keys(), true)) {
            $engine->register(new ReportDefinition(
                key: $key,
                title: 'core.table.document',
                filters: [],
                query: fn (array $f) => DB::table('companies')->select(['id', 'code']),
                columns: [['key' => 'code', 'label' => 'core.table.document']],
                permission: 'accounts.report',
            ));
        }

        return $key;
    }
}
