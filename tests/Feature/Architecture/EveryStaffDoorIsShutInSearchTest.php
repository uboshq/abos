<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Engines\Search\SearchEngine;
use App\Core\Engines\Search\SearchHit;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ খোঁজার বাক্সে কোনো বন্ধ দরজা খোলে না — ১৪ সেপ্টেম্বর ২০২৬।
 *
 * ── কেন এই ফাইলটা সার্চের সবচেয়ে জরুরি অংশ ──────────────────────────
 * একটা সর্বজনীন খোঁজা **প্রতিটা মডিউলের উপর দিয়ে যায়**। ⛔ তাই এখানে
 * একটা ভুল মানে গোটা অনুমতি-ব্যবস্থা **একটা বাক্সে ফাঁকি দেওয়া**:
 * বিক্রয়কর্মী "রহিম" লিখে বেতনের কাগজ বা হিসাবের খাত পেয়ে গেলে
 * প্রতিটা `can:` পাহারার আর কোনো মানে থাকে না।
 *
 * ── ⭐ দাবিটা দুই দিক থেকে, আর কারণটা আজকের শেখা ─────────────────────
 * ⚠️ কেবল *"বিক্রয়কর্মী কিছু পাননি"* দেখলে **একটা সবকিছু-ভাঙা সার্চও
 * পাস করত** — যে সার্চ কাউকে কিছুই দেয় না, সে-ও কাউকে নিষিদ্ধ কিছু
 * দেয় না। ⓘ তাই আগে প্রমাণ করা হয় **মালিক জিনিসটা পান**, তারপর
 * দেখা হয় বিক্রয়কর্মী পান না।
 *
 * ⛔ এই ভুলটা আজ সত্যিই ঘটেছে, আর ধরা পড়েছে হাতে মেপে: ইঞ্জিন
 * `sourcesVisibleTo()`-এ **১৮টা উৎস** দেখাত, অথচ প্রতিটা শব্দে শূন্য
 * ফল। ⓘ কারণ ঐ ১৮টার প্রায় সবই **খালি টেবিল** — চেক, ভাউচার, ঋণ,
 * বেতন। যেগুলোয় ডেটা, সেগুলো একটা ভুল অনুমতি-পার্সিংয়ে চুপচাপ বাদ
 * পড়ত (`can:view,customer` থেকে `view` তুলে আনা হত, যা কোনো অনুমতির
 * নাম নয়)।
 *
 * ⭐ **তালিকা ঠিক থাকা আর তালিকাটা কাজে লাগা এক জিনিস নয়** — তাই নিচে
 * সংখ্যাও গোনা হয়, কেবল অনুপস্থিতি নয়।
 */
class EveryStaffDoorIsShutInSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $salesman;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
    }

    /**
     * ⓘ স্লাগ ধরে, অনূদিত নাম ধরে নয় — নাহলে ভাষা বদলালেই দাবি ভাঙত।
     *
     * @return list<string>
     */
    private function typesFound(User $user, string $term): array
    {
        return array_values(array_unique(array_map(
            fn (SearchHit $hit) => $hit->slug,
            app(SearchEngine::class)->search($term, $user),
        )));
    }

    /* ── ⭐ প্রথমে: খোঁজাটা আদৌ কিছু খুঁজে পায় তো ───────────────────── */

    /**
     * মালিক যা আছে তা পান।
     *
     * ⚠️ এই দাবিটা **নিরাপত্তার দাবির আগে**, আর সেটাই এই ফাইলের ভিত্তি:
     * নিচের প্রতিটা "পাননি" দাবির মানে আছে কেবল যদি "পান"-টা আগে
     * প্রমাণিত হয়।
     */
    public function test_the_owner_finds_what_is_actually_there(): void
    {
        $engine = app(SearchEngine::class);

        $hits = $engine->search('Rahim', $this->owner);

        $this->assertNotEmpty($hits, implode("\n", [
            '⛔ মালিক "Rahim" লিখেও কিছু পাননি — অথচ ডেমোতে ঐ নামে গ্রাহক আছে।',
            '',
            '⚠️ এটা নিরাপত্তার ব্যর্থতা নয়, খোঁজারই ব্যর্থতা — আর তাতে',
            'নিচের প্রতিটা "পাননি" দাবি অর্থহীন হয়ে যায়, কারণ কিছুই না',
            'দেওয়া সার্চও ওগুলো পাস করত।',
        ]));

        $this->assertContains('customer', $this->typesFound($this->owner, 'Rahim'),
            '"Rahim"-এ গ্রাহকটাই আসেনি — ডেমোতে "রহিম ট্রেডার্স" আছে।');
    }

    /**
     * ⭐ পাহারাটা সত্যিই অনেকগুলো উৎসে খুঁজছে তো?
     *
     * ⓘ শূন্য উৎসে "কেউ নিষিদ্ধ কিছু পায়নি" দাবিটা **শূন্যেই সত্য**।
     * ⚠️ আর সংখ্যাটা হঠাৎ অর্ধেক হয়ে গেলে সেটা "কম মডিউল" নয়, **অনুমতি
     * পড়ার ছাঁচ ভাঙার** খবর — আজ ঠিক সেটাই ঘটেছিল (৩৬ → ১৮)।
     */
    public function test_the_search_really_looks_in_many_places(): void
    {
        $engine = app(SearchEngine::class);

        $seen = $engine->sourcesVisibleTo($this->owner);

        $this->assertGreaterThanOrEqual(30, $seen, implode("\n", [
            "⛔ মালিকের জন্য খোঁজার উৎস মাত্র {$seen}টা — ১৪ সেপ্টেম্বরে ছিল ৩৬।",
            '',
            '⚠️ সংখ্যাটা পড়ে গেলে প্রথম সন্দেহ `permissionFor()`:',
            'রুটের `can:` দুই রকম (সাধারণ অনুমতি, আর পলিসি+সারি), আর',
            'একটাকে ভুল পড়লে অর্ধেক উৎস **নীরবে** বাদ পড়ে।',
        ]));

        /*
         * ⓘ বিক্রয়কর্মীও শূন্য নন — তিনিও কিছু খুঁজতে পারেন (গ্রাহক,
         * পণ্য)। ⚠️ শূন্য হলে নিচের নিরাপত্তার দাবিগুলো আবার
         * অর্থহীন হত, কারণ কিছুই না দেখা মানে নিষিদ্ধ কিছুও না দেখা।
         */
        $this->assertGreaterThan(0, $engine->sourcesVisibleTo($this->salesman));
    }

    /* ── ⛔ তারপর: বন্ধ দরজাগুলো সত্যিই বন্ধ ────────────────────────── */

    /**
     * ⛔ বিক্রয়কর্মী হিসাবের খাত খুঁজে পান না — মালিক পান।
     *
     * ⓘ "1101" হলো "হাতে নগদ" খাতের কোড, আর হিসাবের ছক দেখতে
     * `accounts.coa.view` লাগে — বিক্রয়কর্মীর সেটা নেই।
     *
     * ⚠️ দুইটা দাবি একসাথে, আর সেটাই আসল কথা: **একই শব্দে** মালিক পান
     * আর বিক্রয়কর্মী পান না। ⭐ আলাদা শব্দ ব্যবহার করলে "বিক্রয়কর্মী
     * পাননি" প্রমাণ করত কেবল যে ঐ শব্দে কিছু নেই।
     */
    public function test_a_salesman_cannot_find_the_chart_of_accounts(): void
    {
        $this->assertContains('account', $this->typesFound($this->owner, '1101'),
            'মালিকই "1101"-এ হিসাবের খাত পাননি — তাহলে নিচের দাবিটা কিছুই প্রমাণ করে না।');

        $this->assertNotContains('account', $this->typesFound($this->salesman, '1101'), implode("\n", [
            '⛔ বিক্রয়কর্মী খোঁজার বাক্সে হিসাবের খাত পেয়ে যাচ্ছেন।',
            '',
            '⚠️ পর্দাটা তাঁর জন্য বন্ধ (`accounts.coa.view`), কিন্তু খোঁজাটা',
            'সেটা মানছে না — অর্থাৎ গোটা অনুমতি-ব্যবস্থা একটা বাক্সে',
            'ফাঁকি দেওয়া গেল।',
        ]));
    }

    /* ── ⚠️ আর খরচ: খোঁজাটা যেন সাইট ধীর না করে ─────────────────────── */

    /**
     * ⚠️ ছোট শব্দে ডাটাবেজ ছোঁয়াই হয় না।
     *
     * ⓘ এক অক্ষরে প্রায় প্রতিটা সারি মেলে — ফল অর্থহীন, খরচ সর্বোচ্চ।
     * ⭐ তাই `MIN_TERM`-এর নিচে ইঞ্জিন **একটাও কোয়েরি করে না**, কেবল
     * খালি তালিকা ফেরায়।
     */
    public function test_a_one_letter_term_never_reaches_the_database(): void
    {
        $engine = app(SearchEngine::class);
        $engine->sources();   // ⓘ উৎসের মানচিত্র আগেই গরম, নাহলে ওটাই গোনায় ঢুকত

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $hits = $engine->search('r', $this->owner);

        $this->assertSame([], $hits);
        $this->assertSame(0, $queries, "এক অক্ষরের শব্দে {$queries}টা কোয়েরি গেছে — যাওয়ার কথা শূন্য।");
    }

    /**
     * ⛔ একটা খোঁজায় কোয়েরির সংখ্যা সীমার মধ্যে থাকে।
     *
     * ── কেন এই দাবিটা দরকার ─────────────────────────────────────────
     * ⓘ ৪২টা উৎস মানে সবচেয়ে খারাপ অবস্থায় ৪২টা `LIKE` কোয়েরি, আর
     * তার উপরে প্রতিটা ফলাফলের সম্পর্ক তোলা। ⚠️ ওটা বাড়তে থাকলে
     * **খোঁজাটা কাজ করবে আর সাইট ধীর হবে**, আর কেউ দুইটাকে জুড়বে না —
     * কারণ পর্দায় কিছুই ভাঙে না।
     *
     * ⭐ সীমাটা উদার (৮০), কারণ এটা কর্মক্ষমতার মাপকাঠি নয়, **পাহারা**:
     * সংখ্যাটা দ্বিগুণ হয়ে গেলে কেউ যেন টের পায়।
     */
    public function test_one_search_does_not_flood_the_database(): void
    {
        $engine = app(SearchEngine::class);
        $engine->sources();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $engine->search('ra', $this->owner);

        $this->assertLessThanOrEqual(80, $queries, implode("\n", [
            "⛔ একটা খোঁজায় {$queries}টা কোয়েরি গেছে।",
            '',
            '⚠️ প্রথম সন্দেহ: কোনো মডেলের `drillLabel()` সম্পর্ক ছুঁচ্ছে',
            'আর সেটা আগে থেকে তোলা হয়নি (`drillRelations()`)। ⓘ উন্নয়নে',
            'ওটা ব্যতিক্রম হয়ে ধরা পড়ে, কিন্তু চালু সার্ভারে **নীরব N+1**।',
        ]));
    }

    /**
     * ফলাফলের ধরনটা মানুষের ভাষায়, কাঁচা স্লাগ নয়।
     *
     * ⚠️ `drillSourceType()` ফেরায় যন্ত্রের শব্দ (`customer`), আর সেটা
     * সরাসরি দেখালে পর্দায় ইংরেজি স্লাগ বসত — বাংলা পর্দাতেও।
     * ⓘ ৪৮টা উৎসের নাম `core.source.*`-এ আছে, দুই ভাষায়।
     */
    public function test_a_result_says_what_kind_of_paper_it_is(): void
    {
        app()->setLocale('bn');

        $hits = app(SearchEngine::class)->search('Rahim', $this->owner);

        $this->assertNotEmpty($hits);
        $this->assertSame('customer', $hits[0]->slug);
        $this->assertNotSame($hits[0]->slug, $hits[0]->type,
            'ধরনটা কাঁচা স্লাগ হয়ে আসছে — `core.source.*` থেকে অনুবাদ হচ্ছে না।');
    }

    /* ── ⛔ সবচেয়ে কড়া প্রশ্ন: সারি-স্তরের সীমা ─────────────────────── */

    /**
     * ⛔ শাখা-সীমাবদ্ধ কর্মী অন্য শাখার সারি খুঁজে পান না।
     *
     * ── কেন এই দাবিটা আলাদা, আর সবচেয়ে জরুরি ────────────────────────
     * উপরের দাবিগুলো দেখে **উৎস** — "এই ধরনের কাগজ তিনি দেখতে পান
     * কি না"। ⚠️ কিন্তু `/search` রুটে কোনো `can:` নেই (পাহারার
     * তালিকায় কারণসহ ছাড় দেওয়া), আর **ঐ ছাড়টা তখনই টেকে যখন প্রতিটা
     * সারিও ছাঁকা হয়**।
     *
     * ⓘ "উৎস দেখা যায় না" আর "সারি আসে না" এক প্রশ্ন নয় — আর এই
     * রিপোতে আজ ঠিক ঐ দুইটা গুলিয়ে ফেলার দাম দেওয়া হয়েছে।
     *
     * ── ⭐ কেন বিক্রয় চালান দিয়ে, গুদাম দিয়ে নয় ─────────────────────
     * মেপে দেখা: ৪৫টা উৎসের **১৫টা** `ScopedToUserBranch` ব্যবহার করে,
     * আর ওটা একটা **গ্লোবাল স্কোপ** — তাই খোঁজার কোয়েরিতেও আপনাআপনি বসে।
     *
     * ⛔ ২১ সেপ্টেম্বর ২০২৬ পর্যন্ত এটা **গুদাম** দিয়ে মাপা হত, আর দাবিটা
     * দুই কারণে অর্থহীন ছিল:
     *   ১. বিক্রয়কর্মীর গুদাম দেখার অনুমতিই নেই (`viewAny` মিথ্যা), তাই
     *      উৎসটাই তাঁর খোঁজায় আসত না — মেপে দেখা: তিনি ৪৯টার মধ্যে ১৯টা
     *      উৎস দেখেন, গুদাম তার বাইরে।
     *   ২. `Warehouse` শাখা নয়, **গুদাম** ধরে ছাঁকে
     *      ([[ScopedToUserWarehouse]]) — আর পরীক্ষাটা বসাত শাখার সীমা।
     *
     * ⚠️ ফল: দুইটা দাবিই ফাঁকা তালিকায় সত্যি হত। নিচের "অন্য শাখা পাওয়া
     * যায় না" দাবিটা সবুজ ছিল কারণ **কিছুই** পাওয়া যেত না।
     *
     * ⭐ বিক্রয় চালান দুইটাই মেটায়: সে `ScopedToUserBranch` ব্যবহার করে,
     * আর বিক্রয়কর্মী সেটা দেখার অধিকার রাখেন — অর্থাৎ ফাঁকা তালিকা আর
     * সঠিক ছাঁকনি এখানে আলাদা করে চেনা যায়।
     *
     * ⚠️ বাকি ৩০টা উৎস শাখা-সীমা মানে না, কিন্তু সেটা খোঁজার ফাঁক নয়:
     * ওগুলো মাস্টার ডাটা (একক, কর, পদবি, গ্রাহক) যা **কোম্পানি-ব্যাপী**,
     * আর তালিকার পর্দাতেও সবাই সব দেখেন। ⭐ নিয়মটা হলো খোঁজা যেন
     * **তালিকার পর্দার চেয়ে বেশি উদার না হয়** — কম হওয়া চলে, বেশি নয়।
     */
    public function test_a_branch_limited_user_cannot_find_another_branch(): void
    {
        /*
         * ⭐ অনুমতিটা হাতে দেওয়া, আর এটাই এই পরীক্ষার মেরুদণ্ড।
         *
         * ⛔ বিক্রয়কর্মীর ভূমিকায় গুদাম দেখার অধিকার নেই, তাই উৎসটাই
         * তাঁর খোঁজায় আসত না — আর নিচের দুইটা দাবিই **ফাঁকা তালিকায়**
         * সত্যি হয়ে যেত।
         *
         * ⓘ দিয়ে দেওয়ার পর প্রশ্নটা আসল প্রশ্ন হয়: উৎস দেখতে পারেন,
         * তবু কি **অন্য গুদামের সারি** আসে?
         */
        $this->salesman->givePermissionTo('inventory.warehouse.view');
        $this->salesman->unsetRelation('permissions')->unsetRelation('roles');
        $this->salesman = $this->salesman->fresh();

        $mine = Warehouse::query()->where('code', 'WH-MMS')->firstOrFail();

        /*
         * ⓘ সীমাটা এখানে বসানো হয়, ডেমোতে নয় — ডেমোর কারও কোনো সীমা
         * নেই (সীমা না থাকা মানে সব দেখা, `UserDataScope`-এর নিজের
         * নিয়ম)। ⚠️ তাই সীমা না বসিয়ে পরীক্ষা করলে দাবিটা কিছুই
         * প্রমাণ করত না।
         */
        /*
         * ⚠️ সীমাটা **গুদামের**, শাখার নয় — আর আগে ঠিক এখানেই ভুল ছিল।
         *
         * ⛔ [[Warehouse]] `ScopedToUserWarehouse` ব্যবহার করে, অর্থাৎ
         * সে গুদামের তালিকা দেখে। শাখার সীমা বসালে ঐ ছাঁকনিটা **কিছুই
         * করত না** (`idsFor(WAREHOUSE)` খালি ফেরায় → ছাঁকনি বসেই না),
         * আর তবু পরীক্ষাটা সবুজ থাকত — কারণ অনুমতির অভাবে তালিকাটাই
         * ফাঁকা ছিল। দুইটা ভুল একে অন্যকে ঢেকে রাখছিল।
         */
        UserDataScope::query()->create([
            'company_id' => CompanyContext::id(),
            'user_id' => $this->salesman->id,
            'scope_type' => UserDataScope::WAREHOUSE,
            'scope_id' => $mine->id,
        ]);

        /*
         * ⚠️ স্কোপটা অনুরোধ-জীবনকালে ক্যাশ হয়, তাই সারি বসানোর পর
         * ভুলিয়ে দিতে হয় — নাহলে পরীক্ষাটা পুরনো (সীমাহীন) উত্তরেই
         * চলত আর **মিথ্যা সবুজ** হত।
         */
        app(DataScope::class)->forget();

        $this->actingAs($this->salesman);

        $found = array_map(
            fn (SearchHit $h) => $h->documentNo,
            app(SearchEngine::class)->search('WH-', $this->salesman),
        );

        $this->assertContains('WH-MMS', $found, implode("\n", [
            '⛔ নিজের শাখার গুদামটাই পাওয়া গেল না।',
            '',
            '⚠️ তাহলে নিচের দাবিটা কিছুই প্রমাণ করে না — যে খোঁজা কিছুই',
            'দেয় না, সে অন্য শাখার জিনিসও দেয় না।',
        ]));

        $this->assertNotContains('WH-NTK', $found, implode("\n", [
            '⛔ শাখা-সীমাবদ্ধ কর্মী খোঁজার বাক্সে **অন্য শাখার** গুদাম পাচ্ছেন।',
            '',
            '⚠️ তালিকার পর্দায় ওটা তাঁর কাছে লুকানো (`ScopedToUserBranch`),',
            'কিন্তু খোঁজাটা সেটা মানছে না — অর্থাৎ `/search`-এর অনুমতি-ছাড়টা',
            'আর টেকে না, কারণ ওটা দাঁড়িয়ে আছে "প্রতিটা সারি ছাঁকা হয়"',
            'এই প্রতিশ্রুতির উপর।',
        ]));
    }
}
