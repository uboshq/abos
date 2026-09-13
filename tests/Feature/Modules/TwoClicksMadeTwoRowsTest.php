<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\FormIsNotSubmittedTwice;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Brand;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ দুইটা ক্লিক দুইটা সারি বানিয়েছিল — ১৩ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ঘটেছিল ────────────────────────────────────────────────────────
 * মালিক মূলধনের ফর্মে তথ্য ভরে Save-এ **দুইবার ক্লিক করেছেন**, আর
 * `CAP-0001` ও `CAP-0002` — দুইটা সারি তৈরি হয়েছে। ⚠️ ২৫,০০,০০০ টাকা
 * দুইবার। [[DuplicationEngine]] ধরেনি, আর ধরার কথাও নয়: সে মাস্টার
 * রেকর্ডের নকল ধরে, লেনদেনের নয়।
 *
 * ⓘ মেপে দেখা: ১৪৭টা ব্লেড ফাইলে POST ফর্ম, ২৬৯টা POST রুট, আর রিপোর
 * **কোনো স্তরেই** দ্বৈত-জমার পাহারা ছিল না। অর্থাৎ রোগটা এক পর্দার নয়।
 *
 * ── ⭐ কেন পরীক্ষাটা মূলধনের পর্দায় নয় ──────────────────────────────
 * ⚠️ `app/Modules/Finance/**` এই মুহূর্তে অন্য হাতে। কিন্তু সেটা আসল
 * কারণ নয় — আসল কারণ হলো **সমাধানটা মূলধনের নয়**। পাহারাটা
 * middleware-এ, `web` স্ট্যাকের সবার আগে, তাই যেকোনো একটা ফর্ম দিয়ে
 * প্রমাণ করলে সবগুলোর জন্যই প্রমাণ হয়।
 *
 * ⓘ বেছে নেওয়া হলো ব্র্যান্ড — মাস্টার ডাটার সবচেয়ে সরল পর্দা, কোনো
 * টাকা নেই, কোনো অনুমোদনের ধাপ নেই। ⭐ পাহারাটা যদি এখানে কাজ করে,
 * তবে সে পেলোডের কিছুই জানে না — আর সেটাই দেখানোর জিনিস।
 *
 * ── ⚠️ দাবিটা "ব্যতিক্রম পড়েনি" নয়, **সারি গুনে দেখা** ──────────────
 * ⛔ "দ্বিতীয় অনুরোধে ৫০০ আসেনি" প্রমাণ করে কিছুই না — সারিটা বসলেও
 * ৫০০ আসত না। তাই প্রতিটা দাবিতে **গোনা হয়**, আর গোনার আগে নিশ্চিত
 * করা হয় প্রথমটা সত্যিই বসেছে।
 */
class TwoClicksMadeTwoRowsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /**
     * একটা ফর্মের ঘরগুলো — একটা টোকেনসহ, ঠিক যেমন পর্দা পাঠাত।
     *
     * @return array<string, mixed>
     */
    private function form(string $token, string $name): array
    {
        return [
            FormIsNotSubmittedTwice::FIELD => $token,
            'name_en' => $name,
            'name_bn' => $name,
            'is_active' => '1',
        ];
    }

    private function brands(string $name): int
    {
        return Brand::query()->where('name_en', $name)->count();
    }

    /* ── রোগটা ─────────────────────────────────────────────────────── */

    /**
     * ⛔ একই টোকেনে দুইবার জমা — সারি একটাই।
     *
     * ⭐ এটাই মালিকের ঘটনাটা, হুবহু: একটা রেন্ডার, দুইটা জমা।
     */
    public function test_the_same_form_submitted_twice_makes_one_row(): void
    {
        $token = app(FormIsNotSubmittedTwice::class)->newToken();

        $this->actingAs($this->owner)
            ->post(route('master_data.brand.store'), $this->form($token, 'Ekbar Brand'))
            ->assertRedirect();

        // ⚠️ আগে নিশ্চিত হই প্রথমটা সত্যিই বসেছে — নাহলে নিচের গোনাটা
        // "শূন্য = শূন্য" মিলিয়ে মিথ্যা সবুজ হত
        $this->assertSame(1, $this->brands('Ekbar Brand'),
            'প্রথম জমাটাই বসেনি — পরীক্ষাটা তখন কিছুই মাপছে না।');

        /*
         * ⛔ দ্বিতীয় জমায় নামটা **আলাদা**, টোকেনটা এক — আর এটাই এই
         * পরীক্ষার একমাত্র কঠিন সিদ্ধান্ত।
         *
         * ── কেন একই নাম দিয়ে পরীক্ষা করা যায় না ────────────────────
         * ⚠️ ব্র্যান্ডে নকল-নামের পাহারা আগে থেকেই আছে
         * ([[DuplicationEngine]], `MasterData/module.php`-এর
         * `duplicates`)। ⛔ একই নাম দুইবার পাঠালে **সে-ই দ্বিতীয়টা
         * আটকাত**, আর আমার middleware কিছু না করলেও গোনাটা ১ দেখাত —
         * অর্থাৎ পরীক্ষাটা সবুজ হত **ভুল কারণে**, আর দ্বৈত-জমার পাহারা
         * আদৌ কাজ করছে কি না তা কেউ জানত না।
         *
         * ⭐ নাম আলাদা হলে নকল-পাহারার কোনো ভূমিকা থাকে না: middleware
         * না থাকলে দ্বিতীয় নামের একটা সারি **নিশ্চিত** বসত। তাই নিচের
         * শূন্যটা কেবল একটা জিনিসই প্রমাণ করতে পারে।
         */
        $this->actingAs($this->owner)
            ->post(route('master_data.brand.store'), $this->form($token, 'Ekbar Brand Dui'))
            ->assertRedirect();

        $this->assertSame(0, $this->brands('Ekbar Brand Dui'),
            '⛔ একই টোকেনে দ্বিতীয় জমা একটা নতুন সারি বানিয়েছে — ঠিক যে রোগটা সারানো হচ্ছে।');

        $this->assertSame(1, $this->brands('Ekbar Brand'));
    }

    /**
     * ⭐ দ্বিতীয়বারে ব্যবহারকারী প্রথমবারের ফলেই পৌঁছান।
     *
     * ── কেন এটা আলাদা দাবি ──────────────────────────────────────────
     * ⓘ সারি না বসাটা অর্ধেক কাজ। ⚠️ দ্বিতীয় ক্লিকের পর যদি তিনি একটা
     * ত্রুটি-পাতা বা ফর্মে ফেরত যেতেন, তিনি ভাবতেন কিছু হয়নি আর
     * **তৃতীয়বার চাপতেন** — অর্থাৎ রোগটা ফিরে আসত।
     */
    public function test_the_second_click_lands_on_the_first_result(): void
    {
        $token = app(FormIsNotSubmittedTwice::class)->newToken();

        $first = $this->actingAs($this->owner)
            ->post(route('master_data.brand.store'), $this->form($token, 'Duibar Brand'));

        $this->assertSame(1, $this->brands('Duibar Brand'));

        $second = $this->actingAs($this->owner)
            ->post(route('master_data.brand.store'), $this->form($token, 'Duibar Brand'));

        $second->assertRedirect($first->headers->get('Location'));
        $second->assertSessionHas('saved', __('core.form.already_saved'));
    }

    /* ── ⭐ আর যা আটকানো যাবে না ────────────────────────────────────── */

    /**
     * ⛔ বৈধ পুনরাবৃত্তি আটকায় না — ফর্ম আবার খুললে আবার বসে।
     *
     * ── কেন এই দাবিটা রোগের দাবির চেয়ে কম জরুরি নয় ─────────────────
     * মালিক আজ স্পষ্ট বলেছেন **একই মালিক আবার বিনিয়োগ করতে পারেন**।
     * ⓘ আর [[SlipIsNotUsedTwice]]-এ তাঁর নিজের কথায় নিয়মটা লেখা:
     * *"একই গ্রাহক পরপর দুই সপ্তাহে হুবহু একই মাল নিতে পারেন, হুবহু
     * একই টাকা দিতে পারেন — ওটা নকল নয়, ওটাই ব্যবসা।"*
     *
     * ⚠️ পেলোড মিলিয়ে আটকালে এই পরীক্ষাটা লাল হত — আর সেটাই প্রমাণ
     * করে নিয়মটা অঙ্কের নয়, **রেন্ডারের**: নতুন টোকেন মানে নতুন
     * রেন্ডার, মানে মানুষটা সত্যিই আবার ফর্মটা ভরেছেন।
     */
    public function test_filling_the_form_again_is_not_blocked(): void
    {
        $forms = app(FormIsNotSubmittedTwice::class);

        foreach (['Tinbar One', 'Tinbar Two'] as $name) {
            $this->actingAs($this->owner)
                ->post(route('master_data.brand.store'), $this->form($forms->newToken(), $name))
                ->assertRedirect();
        }

        $this->assertSame(1, $this->brands('Tinbar One'));
        $this->assertSame(1, $this->brands('Tinbar Two'));
    }

    /**
     * ⚠️ টোকেন ছাড়া অনুরোধ থামে না।
     *
     * ⓘ পাহারার অভাবে ব্যবহারকারীর কাজ আটকানো ভুল বিনিময় — একটা পুরনো
     * পর্দা বা একটা API ডাক তখন ৫০০ পেত, অথচ তার দোষ নেই।
     * ⭐ "প্রতিটা ফর্ম সত্যিই টোকেন পাচ্ছে কি না" — সেই প্রশ্নের উত্তর
     * দেয় [[EveryFormCarriesItsOwnTokenTest]], রানটাইমে নয়, CI-তে।
     */
    public function test_a_request_with_no_token_still_works(): void
    {
        $this->actingAs($this->owner)
            ->post(route('master_data.brand.store'), [
                'name_en' => 'Token Nei Brand',
                'name_bn' => 'Token Nei Brand',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(1, $this->brands('Token Nei Brand'));
    }

    /* ── খাতাটা নিজে ────────────────────────────────────────────────── */

    /**
     * প্রতি জমায় একটা সারি — রেন্ডারে নয়।
     *
     * ⚠️ টোকেনটা রেন্ডারের সময় টেবিলে বসালে **প্রতিটা পাতা দেখাও** একটা
     * সারি লিখত — ১৪৭টা ফর্মের একটা অ্যাপে সেটা দিনে হাজারো অর্থহীন
     * সারি। ⓘ তাই সারিটা বসে কেবল জমায়, আর এই দাবিটা সেটাই পাহারা দেয়।
     */
    public function test_the_book_gets_one_row_per_submit_not_per_render(): void
    {
        $before = DB::table('submitted_forms')->count();

        // ফর্মের পাতাটা তিনবার দেখা — তিনটা রেন্ডার, তিনটা টোকেন
        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->owner)
                ->get(route('master_data.brand.create'))
                ->assertOk();
        }

        $this->assertSame($before, DB::table('submitted_forms')->count(),
            'কেবল পাতা দেখাতেই খাতায় সারি বসেছে।');

        $token = app(FormIsNotSubmittedTwice::class)->newToken();

        $this->actingAs($this->owner)
            ->post(route('master_data.brand.store'), $this->form($token, 'Ek Sari Brand'));

        $this->assertSame($before + 1, DB::table('submitted_forms')->count());

        $row = DB::table('submitted_forms')->where('token', $token)->first();

        $this->assertNotNull($row);
        $this->assertSame($this->owner->id, (int) $row->user_id);
        $this->assertSame('master_data.brand.store', $row->route);
        $this->assertNotNull($row->result_url, 'প্রথম জমার ফলটা লেখা হয়নি — দ্বিতীয় ক্লিক তখন কোথায় যাবে?');
        $this->assertNotNull($row->completed_at);
    }

    /**
     * ছাঁটাই পুরনো সারি মোছে, নতুনগুলো রাখে।
     *
     * ⓘ `routes/console.php`-এ রোজ ২:১০-এ চলে। ⚠️ মেয়াদটা খুব ছোট হলে
     * পুরনো ট্যাব থেকে জমা দেওয়া টোকেন আর চেনা যেত না, আর নতুন সারি
     * বসত — অর্থাৎ রোগটা ফিরে আসত।
     */
    public function test_the_prune_keeps_what_is_still_useful(): void
    {
        $forms = app(FormIsNotSubmittedTwice::class);

        DB::table('submitted_forms')->insert([
            ['token' => 'purono-token', 'created_at' => now()->subDays(FormIsNotSubmittedTwice::KEEP_DAYS + 1)],
            ['token' => 'notun-token', 'created_at' => now()->subMinutes(5)],
        ]);

        $removed = $forms->prune();

        $this->assertSame(1, $removed);
        $this->assertSame(0, DB::table('submitted_forms')->where('token', 'purono-token')->count());
        $this->assertSame(1, DB::table('submitted_forms')->where('token', 'notun-token')->count());
    }
}
