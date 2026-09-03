<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Coding\CodeSuggester;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Services\LocationService;
use App\Modules\MasterData\Services\MasterListService;
use App\Modules\MasterData\Support\CodeConventions;
use App\Core\Support\CompanyContext;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কোডের ঘর ফাঁকা রেখে সেভ করলে কী হয় — মালিকের নিয়ম, ২ সেপ্টেম্বর ২০২৬।
 *
 * ── নিয়মটা তাঁর নিজের ভাষায় ─────────────────────────────────────────
 * *"abos e zoto code ache tasob auto bosbe & keu edit korte chaile taw
 * korte parbe, but auto mandatory korbe"* — কোড সবসময় নিজে থেকে বসবে,
 * আর মানুষ চাইলে বদলাতে পারবেন।
 *
 * আর তারপর দুইটা কড়া শর্ত, যেগুলো এই ফাইলের অর্ধেক:
 *
 *   *"(1010, 4010) emon korei suggest korbe … kokonoi ACC-0001 emon
 *   hobe na"*
 *   *"PCS, KG, BDT, USD egulo emonoi suggest korbe … kokonoi UNIT-0001
 *   hobena"*
 *
 * অর্থাৎ **অটো মানে সিরিয়াল নম্বর নয়** — অটো মানে "মানুষ যা লিখত, সেটাই
 * আগে থেকে বসে থাকা"। এই পার্থক্যটাই এখানে পাহারা দেওয়া হয়।
 */
class NoCodeFieldIsEverLeftForTheUserToInventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        CompanyContext::set($owner->company_id ?? $owner->companies()->first()?->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * একক — প্রচলিত রূপেই, নিয়ম মেনে বের করা রূপে নয়।
     *
     * `Kilogram` থেকে অক্ষরের নিয়মে আসে `KIL`; মালিক চেয়েছেন `KG`।
     * পার্থক্যটা অভিধানের ([[CodeConventions::UNITS]]), আর অভিধান
     * ছাড়া এটা বের করার কোনো উপায় নেই — `KG` "Kilogram"-এর প্রথম
     * দুই অক্ষরও নয়।
     */
    public function test_a_unit_gets_the_code_the_trade_actually_uses(): void
    {
        $lists = app(MasterListService::class);

        /*
         * ⚠️ নতুন কোম্পানি খালি হাতে শুরু করে না।
         *
         * [[MasterListService::installDefaults()]] এগারোটা তালিকা ভরে
         * দেয়, আর তাতে `KG` ও `CTN` আগে থেকেই থাকে। প্রথম রানে এই
         * টেস্টটা `KG2` পেয়ে লাল হয়েছিল — আর সেটা **কোডের ভুল ছিল না,
         * টেস্টের ভুল ছিল**: দ্বিতীয় কিলোগ্রাম সত্যিই `KG2` পাওয়ার কথা।
         *
         * তাই জায়গাটা ইচ্ছে করে খালি করা হয়, যাতে প্রশ্নটা পরিষ্কার
         * থাকে — "নাম থেকে কোন কোডটা বেরোয়", "দ্বিতীয়টা কী পায়" নয়।
         * ওই দ্বিতীয় প্রশ্নের নিজের টেস্ট আছে, নিচে।
         */
        Unit::query()->whereIn('code', ['KG', 'CTN'])->forceDelete();

        $kg = $lists->create(Unit::class, [
            'name_en' => 'Kilogram', 'name_bn' => 'কেজি', 'factor' => 1,
        ], 'units');

        $this->assertSame('KG', $kg->code);

        $ctn = $lists->create(Unit::class, [
            'name_en' => 'Carton', 'name_bn' => 'কার্টন', 'factor' => 1,
        ], 'units');

        $this->assertSame('CTN', $ctn->code);
    }

    /** মুদ্রা — ISO 4217, কারণ ব্যাংক আর LC ওই তিন অক্ষরই চেনে। */
    public function test_a_currency_gets_its_iso_code(): void
    {
        // একই কারণ, উপরের এককের টেস্ট দেখুন — `USD` সিডারে আগে থেকেই আছে
        Currency::query()->where('code', 'USD')->forceDelete();

        $made = app(MasterListService::class)->create(Currency::class, [
            'name_en' => 'US Dollar', 'name_bn' => 'মার্কিন ডলার',
            'symbol' => '$', 'decimal_places' => 2,
        ], 'currencies');

        $this->assertSame('USD', $made->code);
    }

    /** কর — `Value Added Tax` → `VAT`, অক্ষরের নিয়মে যেটা `VAL` হত। */
    public function test_a_tax_gets_the_name_the_paperwork_uses(): void
    {
        $made = app(MasterListService::class)->create(Tax::class, [
            'name_en' => 'Value Added Tax', 'name_bn' => 'মূল্য সংযোজন কর',
            'rate' => 15, 'kind' => 'vat',
        ], 'taxes');

        $this->assertSame('VAT', $made->code);
    }

    /**
     * ⚠️ মালিকের কড়া নিষেধটা — কোনো মাস্টারের কোডে সিরিয়ালের ছাঁচ নয়।
     *
     * ── কেন এটা আলাদা একটা টেস্ট ─────────────────────────────────────
     * উপরেরগুলো বলে "ঠিক কোডটা এসেছে"। এটা বলে **ভুল ছাঁচটা আসেনি** —
     * আর দুইটা এক কথা নয়। কেউ যদি একদিন `CodeSuggester`-এর বদলে
     * `NumberSeriesEngine` বসিয়ে দেয়, উপরের তিনটা টেস্টের প্রতিটাই
     * লাল হবে ঠিকই, কিন্তু বার্তাটা হত "KG আশা করেছিলাম, UNI-0001
     * পেয়েছি" — যেটা পড়ে মনে হয় অভিধানে একটা শব্দ কম আছে।
     *
     * এই টেস্টটা বার্তাটাকে সরাসরি নিয়মটার দিকে দেখায়।
     */
    public function test_no_master_code_ever_looks_like_a_serial(): void
    {
        $lists = app(MasterListService::class);

        /*
         * প্রতিটা মডেলের নিজের বাধ্যতামূলক ঘর আলাদা, তাই বাড়তি ঘরগুলো
         * সারির সাথেই যায়। এক সেট ঘর সবাইকে পাঠানোর চেষ্টায় প্রথম
         * রানে "Add fillable property [rate]" এসেছিল — mass assignment
         * অচেনা ঘর নীরবে ফেলে দেয় না, ছুঁড়ে ফেলে।
         */
        $rows = [
            [Unit::class, 'units', 'Sachet', ['factor' => 1]],
            [Currency::class, 'currencies', 'Norwegian Krone', ['symbol' => 'kr', 'decimal_places' => 2]],
            [Tax::class, 'taxes', 'Green Tax', ['rate' => 5, 'kind' => 'vat']],
        ];

        foreach ($rows as [$model, $kind, $name, $extra]) {
            $made = $lists->create($model, [
                'name_en' => $name, 'name_bn' => $name, ...$extra,
            ], $kind);

            $this->assertNotEmpty($made->code, "{$name}-এর কোড খালি এসেছে।");

            $this->assertDoesNotMatchRegularExpression(
                '/^[A-Z]{2,5}[-_]?0*\d+$/',
                $made->code,
                "{$name} পেয়েছে '{$made->code}' — এটা সিরিয়ালের ছাঁচ, আর মালিক "
                .'২ সেপ্টেম্বর ২০২৬-এ বলেছেন কোনো কোড কখনো UNIT-0001 রূপে হবে না।',
            );
        }
    }

    /**
     * হিসাবের খাত — অভিভাবকের নিচে পরের নম্বর, সিরিজ নয়।
     *
     * চলতি সম্পদের (`1100`) নিচে ইতিমধ্যে `1101`…`1131` আছে। নতুন
     * খাতটা তাই `১১৩২` পাওয়ার কথা — অর্থাৎ ভাইদের ধাপ (এখানে ১) মেপে
     * সবচেয়ে বড়টার পরেরটা।
     */
    public function test_an_account_lands_under_its_parent_not_in_a_series(): void
    {
        $parent = Account::query()->where('is_group', true)->whereNotNull('parent_id')
            ->whereRaw('code REGEXP "^[0-9]+$"')
            ->has('children')
            ->orderBy('code')
            ->firstOrFail();

        $before = $parent->children()->pluck('code')
            ->filter(static fn ($c): bool => ctype_digit((string) $c))
            ->map(static fn ($c): int => (int) $c);

        $made = app(AccountService::class)->create([
            'parent_id' => $parent->id,
            'name_en' => 'Petty Cash Box', 'name_bn' => 'খুচরা নগদ',
            'is_group' => false,
        ]);

        $this->assertTrue(ctype_digit($made->code), "খাতের কোড সংখ্যা নয়: {$made->code}");
        $this->assertGreaterThan($before->max(), (int) $made->code);

        // অভিভাবকের কোডের গোড়াটা ধরে রাখে — ১১xx-এর সন্তান ১২xx হতে পারে না
        $this->assertStringStartsWith(
            substr((string) $parent->code, 0, 2),
            $made->code,
            "খাতটা অভিভাবক {$parent->code}-এর ঘর ছেড়ে বেরিয়ে গেছে।",
        );
    }

    /** এলাকার কোডও নাম থেকে — সাতটা ধাপের যেকোনোটায়। */
    public function test_a_location_names_itself(): void
    {
        /*
         * মইয়ের **মাথার** ধাপ, কারণ নিচের ধাপগুলোর অভিভাবক লাগে —
         * প্রথম রানে `territory` দিয়ে "উপরের এরিয়া বাছতে হবে" এসেছিল।
         * এখানে প্রশ্নটা কোড নিয়ে, মইয়ের নিয়ম নিয়ে নয়।
         */
        $made = app(LocationService::class)->create([
            'level' => Location::LADDER[0],
            'name_en' => 'Netrokona', 'name_bn' => 'নেত্রকোনা',
            'parent_id' => null,
        ]);

        $this->assertSame('NET', $made->code);
    }

    /**
     * নিজের কোড লিখলে সেটাই থাকে — নিয়মের দ্বিতীয় অর্ধেক।
     *
     * অটো বাধ্যতামূলক, কিন্তু **অটো চূড়ান্ত নয়**। পুরনো খাতা থেকে আসা
     * কোড ধরে রাখাই এই ঘরটার আসল কাজ।
     */
    public function test_a_code_you_type_yourself_is_the_one_that_is_kept(): void
    {
        // নাম প্রমিত এককের থেকে আলাদা — নকল-পাহারা seed "Kilogram"-এ থামত;
        // এই টেস্ট নিজে-লেখা কোড রাখা দেখে, নাম নয়
        $made = app(MasterListService::class)->create(Unit::class, [
            'code' => 'MYOWN', 'name_en' => 'Test Weight', 'name_bn' => 'নমুনা ওজন', 'factor' => 1,
        ], 'units');

        $this->assertSame('MYOWN', $made->code);
    }

    /**
     * একই নামের দ্বিতীয়টা কোড চুরি করে না।
     *
     * আর পাশের সংখ্যাটা `KG2` — `KG-0002` নয়, নাহলে ঠিক যে ছাঁচটা
     * বারণ সেটাই পেছনের দরজা দিয়ে ফিরত।
     */
    public function test_the_second_one_of_the_same_name_steps_aside(): void
    {
        $lists = app(MasterListService::class);

        Unit::query()->where('code', 'KG')->forceDelete();

        $first = $lists->create(Unit::class,
            ['name_en' => 'Kilogram', 'name_bn' => 'কেজি', 'factor' => 1], 'units');
        // ⓘ দ্বিতীয়টা ইচ্ছাকৃতভাবে একই নাম — এটাই এই টেস্টের বিষয় (কোড
        // চুরি করে না)। নতুন নকল-পাহারা এমন একই নাম আটকায়, তাই allow_duplicate
        // দিয়ে সিদ্ধান্তটা স্পষ্ট করে এগোনো।
        $second = $lists->create(Unit::class,
            ['name_en' => 'Kilogram', 'name_bn' => 'কেজি বড়', 'factor' => 1, 'allow_duplicate' => true], 'units');

        $this->assertSame('KG', $first->code);
        $this->assertNotSame($first->code, $second->code);
        $this->assertDoesNotMatchRegularExpression('/[-_]0\d+$/', $second->code);
    }

    /**
     * সম্পাদনায় ঘরটা ফাঁকা রেখে সেভ করলে কোড **মুছে যায় না**।
     *
     * ── কেন এই টেস্টটা লেখা হয়েছে ────────────────────────────────────
     * ঘরটা থেকে `required` তোলার সরাসরি ফল। আগে ফাঁকা কোড ফর্ম থেকে
     * আসতেই পারত না; এখন পারে, আর তিনটা সার্ভিসের প্রতিটাতেই
     * `[...$data]` ওটা বসিয়ে কোডটা মুছে দিত। নীরবে — কারণ কলামটা
     * `NOT NULL`, খালি স্ট্রিং নয়।
     */
    public function test_clearing_the_box_on_edit_does_not_erase_the_code(): void
    {
        $lists = app(MasterListService::class);

        Unit::query()->where('code', 'KG')->forceDelete();

        $unit = $lists->create(Unit::class,
            ['name_en' => 'Kilogram', 'name_bn' => 'কেজি', 'factor' => 1], 'units');

        $after = $lists->update($unit, ['code' => '', 'name_en' => 'Kilogram']);

        $this->assertSame('KG', $after->code);
    }

    /**
     * মুছে ফেলা সারির কোড দ্বিতীয়বার বিলি হয় না।
     *
     * ABOS-এ মোছা মানে soft delete, আর মোছা সারি ফিরিয়ে আনা যায়। ফিরে
     * এসে দেখে নিজের কোড অন্য কারো — সেটা হওয়া উচিত নয়, কারণ পুরনো
     * চালানে ওই কোডটাই ছাপা আছে।
     */
    public function test_a_deleted_rows_code_is_not_handed_out_again(): void
    {
        $lists = app(MasterListService::class);

        Unit::query()->where('code', 'KG')->forceDelete();

        $unit = $lists->create(Unit::class,
            ['name_en' => 'Kilogram', 'name_bn' => 'কেজি', 'factor' => 1], 'units');
        $this->assertSame('KG', $unit->code);

        $unit->delete();

        $again = $lists->create(Unit::class,
            ['name_en' => 'Kilogram', 'name_bn' => 'কেজি', 'factor' => 1], 'units');

        $this->assertNotSame('KG', $again->code);
    }

    /**
     * কোড কখনো কলামের চেয়ে লম্বা হয় না।
     *
     * `mdm_currencies.code` **varchar(8)** আর `mdm_brands.code`
     * varchar(32) — একটা স্থির সীমা বসালে সরু কলামে ইনসার্ট ভাঙত।
     * [[CodeSuggester::widthFor()]] টেবিল দেখে নেয়, তাই এই টেস্টটা
     * সেই দেখাটা সত্যিই হচ্ছে কি না তার রসিদ।
     */
    public function test_a_suggested_code_fits_the_column_it_goes_into(): void
    {
        $long = str_repeat('Alexandria', 6);

        $made = app(MasterListService::class)->create(Currency::class, [
            'name_en' => $long, 'name_bn' => $long,
            'symbol' => 'K', 'decimal_places' => 2,
        ], 'currencies');

        $this->assertLessThanOrEqual(8, mb_strlen($made->code));
    }

    /**
     * অভিধান দুইটাই দুই ভাষায় এক জিনিস বলে — চাবিগুলো বড় হাতের।
     *
     * ছোট হাতের একটা চাবি অভিধানে বসলে ওটা কোনোদিন মিলত না, আর
     * ফলাফলটা হত নীরব: কোডটা অক্ষরের নিয়মে বসত আর কেউ টের পেত না।
     */
    public function test_every_convention_key_is_upper_case(): void
    {
        foreach ([CodeConventions::UNITS, CodeConventions::CURRENCIES, CodeConventions::TAXES] as $dictionary) {
            foreach ($dictionary as $name => $code) {
                $this->assertSame(mb_strtoupper($name), $name, "অভিধানের চাবি '{$name}' বড় হাতের নয়।");
                $this->assertMatchesRegularExpression('/^[A-Z0-9]{1,8}$/', $code, "'{$code}' একটা কোডের মতো নয়।");
            }
        }
    }

    /** ইংরেজি নাম না থাকলেও ঘরটা খালি থাকে না — উপসর্গ বসে। */
    public function test_a_bangla_only_name_still_gets_a_code(): void
    {
        // name_bn seed "Bag" (বস্তা)-এর থেকে আলাদা — নকল-পাহারা নয়তো থামাত;
        // এই টেস্ট ইংরেজি নাম খালি হলে কোডের উপসর্গ দেখে
        $made = app(MasterListService::class)->create(Unit::class, [
            'name_en' => '', 'name_bn' => 'নমুনা একক', 'factor' => 1,
        ], 'units');

        $this->assertNotEmpty($made->code);
        $this->assertStringStartsWith('UNI', $made->code);
    }

    /** খালি খাতায় প্রথম খাতটাও একটা কোড পায়। */
    public function test_the_very_first_account_still_gets_a_number(): void
    {
        Account::query()->forceDelete();

        $code = app(CodeSuggester::class)->underParent(Account::class, null, true);

        $this->assertTrue(ctype_digit($code), "প্রথম খাতের কোড সংখ্যা নয়: '{$code}'");
    }
}
