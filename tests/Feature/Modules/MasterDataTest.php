<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Services\LocationService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Master Data — এলাকার মই ও ছয়টা তালিকা।
 *
 * এই মডিউলটার নিজের কোনো লেনদেন নেই, তাই ভুল হলে সেটা সাথে সাথে দেখা
 * যায় না — দেখা যায় মাস পরে, যখন একটা রিপোর্টে দুইটা "ময়মনসিংহ" বা
 * তিনটা আলাদা বানানের "ক্ষতিগ্রস্ত" জমে ওঠে। তাই এখানকার পরীক্ষাগুলো
 * কাঠামোর দিকে বেশি তাকায়।
 */
class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    private function locations(): LocationService
    {
        return app(LocationService::class);
    }

    private function lists(): MasterListService
    {
        return app(MasterListService::class);
    }

    // ── এলাকার মই ───────────────────────────────────────────────────────

    public function test_the_ladder_is_one_tree_not_two(): void
    {
        // দেশ থেকে রুট — সাতটা স্তর, একটাই টেবিল। দুইটা টেবিল হলে
        // "এই রুটটা কোন টেরিটরিতে" প্রশ্নের উত্তর জোড়া দিয়ে বের করতে হত।
        $this->assertSame([
            'country', 'division', 'region', 'area', 'territory', 'point', 'route',
        ], Location::LADDER);
    }

    public function test_a_location_must_sit_directly_under_the_level_above_it(): void
    {
        /*
         * নিজের কোড, সিডারের BD/MYM নয়।
         *
         * সিডার এখন বাংলাদেশ ও আটটা বিভাগ বসিয়ে রাখে, তাই BD দিয়ে আরেকটা
         * দেশ বানাতে গেলে "এই কোডে আরেকটা রেকর্ড আছে" বলে থামে — আর সেটা
         * ঠিকই বলে, কিন্তু এই টেস্টের প্রশ্ন সেটা নয়। এখানকার প্রশ্ন:
         * একটা ধাপ কি তার ঠিক উপরের ধাপের নিচেই বসে?
         */
        $country = $this->locations()->create([
            'code' => 'TST-C', 'name_en' => 'Testland', 'level' => Location::COUNTRY,
        ]);

        // বিভাগ দেশের নিচে — ঠিক
        $division = $this->locations()->create([
            'code' => 'TST-D', 'name_en' => 'Test Division', 'level' => Location::DIVISION,
            'parent_id' => $country->id,
        ]);

        $this->assertSame($country->id, $division->parent_id);

        // এরিয়া সরাসরি দেশের নিচে — ভুল, মাঝে একটা স্তর বাদ পড়েছে
        $this->expectException(ValidationException::class);

        $this->locations()->create([
            'code' => 'TRI', 'name_en' => 'Trishal', 'level' => Location::AREA,
            'parent_id' => $country->id,
        ]);
    }

    /**
     * অঞ্চল বন্ধ থাকলে এরিয়ার বাবা হয় বিভাগ।
     *
     * বন্ধ স্তরটা এড়িয়ে যেতে হয়, নাহলে গাছে একটা ফাঁক তৈরি হত যেখানে
     * কিছুই বসানো যেত না — আর ব্যবহারকারী বুঝত না কেন।
     */
    public function test_a_switched_off_level_is_skipped_not_left_as_a_gap(): void
    {
        $settings = app(SettingsService::class);

        $this->assertFalse($settings->enabled('master_data.region_enabled'));

        $this->assertSame(Location::DIVISION, Location::parentLevelOf(Location::AREA));

        $settings->set('master_data.region_enabled', true);
        $settings->flush();

        $this->assertSame(Location::REGION, Location::parentLevelOf(Location::AREA));
    }

    public function test_nothing_can_be_created_at_a_switched_off_level(): void
    {
        // অঞ্চল ডিফল্টে বন্ধ — সেখানে কিছু বসালে গাছে থাকত অথচ
        // ড্রপডাউনে আসত না, আর তার নিচের সব হারিয়ে যেত
        $this->expectException(ValidationException::class);

        $this->locations()->create([
            'code' => 'RGN', 'name_en' => 'Some region', 'level' => Location::REGION,
        ]);
    }

    public function test_a_location_cannot_change_level(): void
    {
        // নিজের কোড — সিডারের BD ইতিমধ্যেই বসানো
        $country = $this->locations()->create([
            'code' => 'TST-C2', 'name_en' => 'Testland Two', 'level' => Location::COUNTRY,
        ]);

        $this->expectException(ValidationException::class);

        $this->locations()->update($country, ['level' => Location::ROUTE]);
    }

    /**
     * নিজের নিচে নিজেকে বসানো যায় না — আর উপরের স্তরে বাবা দেওয়াও যায় না।
     *
     * দ্বিতীয়টা আগে নীরবে উপেক্ষা করা হত: দেশের কোনো বাবা নেই বলে
     * সার্ভিস অনুরোধটা ফেলে দিত। ব্যবহারকারী ভাবত কাজটা হয়েছে, আর পরে
     * গাছে খুঁজে না পেয়ে বুঝত না কেন।
     */
    public function test_a_location_cannot_be_moved_under_itself(): void
    {
        $this->locations()->installBangladesh();

        $mym = Location::query()->where('code', 'MYM')->firstOrFail();

        $area = $this->locations()->create([
            'code' => 'TRI', 'name_en' => 'Trishal',
            'level' => Location::AREA, 'parent_id' => $mym->id,
        ]);

        // বিভাগকে তার নিজের এরিয়ার নিচে — চক্র
        try {
            $this->locations()->update($mym, ['parent_id' => $area->id]);
            $this->fail('একটা এলাকা নিজের নিচে বসে গেল।');
        } catch (ValidationException) {
            $this->assertNotSame($area->id, $mym->fresh()->parent_id);
        }

        // আর দেশের উপরে কিছু বসানোর চেষ্টাও না বলে ফিরিয়ে দেয়
        $country = Location::query()->atLevel(Location::COUNTRY)->firstOrFail();

        $this->expectException(ValidationException::class);

        $this->locations()->update($country, ['parent_id' => $mym->id]);
    }

    public function test_the_path_reads_from_the_top_down(): void
    {
        $this->locations()->installBangladesh();

        $mym = Location::query()->where('code', 'MYM')->firstOrFail();

        $area = $this->locations()->create([
            'code' => 'TRI', 'name_en' => 'Trishal', 'name_bn' => 'ত্রিশাল',
            'level' => Location::AREA, 'parent_id' => $mym->id,
        ]);

        // টেরিটরি ডিফল্টে চালু, তাই এরিয়ার নিচে টেরিটরি
        $territory = $this->locations()->create([
            'code' => 'TRI-1', 'name_en' => 'Trishal North', 'level' => Location::TERRITORY,
            'parent_id' => $area->id,
        ]);

        app()->setLocale('bn');

        $this->assertSame('বাংলাদেশ › ময়মনসিংহ › ত্রিশাল › Trishal North', $territory->fresh()->path());
    }

    public function test_deactivating_a_location_takes_everything_under_it(): void
    {
        // গাছটা সিডারেই বসানো — দেশ, আটটা বিভাগ, আর ডিপোর নিজের
        // এরিয়া-টেরিটরি-পয়েন্ট। সবগুলোই এই দেশের নিচে।
        $before = Location::query()->count();

        $country = Location::query()->atLevel(Location::COUNTRY)->firstOrFail();

        $this->locations()->deactivate($country);

        // একটা সক্রিয় বিভাগ নিষ্ক্রিয় দেশের নিচে ঝুললে ড্রপডাউনে দেখা
        // যেত কিন্তু গাছে খুঁজে পাওয়া যেত না
        $this->assertSame(0, Location::query()->active()->count());

        // সংখ্যাটা হাতে লেখা নয়, আগের গোনা — সিডারে একটা পয়েন্ট যোগ
        // হলে টেস্টটা ভাঙবে না, অথচ নিয়ম ৫ ঠিকই পাহারা দেবে
        $this->assertSame($before, Location::query()->count(), 'রেকর্ডগুলো থেকে যাবে — নিয়ম ৫।');
    }

    public function test_bangladesh_installs_once_and_only_once(): void
    {
        /*
         * ডেমো সিডার এখন নিজেই installBangladesh() ডাকে, তারপর ডিপোর
         * নিজের এরিয়া-টেরিটরি-পয়েন্ট বসায় — গ্রাহকের তালিকায় ওই
         * কলামগুলো ফাঁকা থাকলে নকশাটা যাচাই করা যেত না। তাই এখানে গাছ
         * ভরা অবস্থায় শুরু হয়, আর "প্রথম" ডাকটা আসলে দ্বিতীয়।
         *
         * পাহারাটার আসল প্রশ্ন তাতে বদলায় না: দুইবার ডাকলে দেশ ও বিভাগ
         * দুইবার বসে না তো? নইলে রিপোর্টে দুইটা "ময়মনসিংহ" দেখা যেত।
         */
        $before = Location::query()->count();

        $this->assertSame(0, $this->locations()->installBangladesh());
        $this->assertSame($before, Location::query()->count());

        $this->assertSame(1, Location::query()->atLevel(Location::COUNTRY)->count());
        $this->assertSame(8, Location::query()->atLevel(Location::DIVISION)->count());
    }

    public function test_every_installed_location_has_both_names(): void
    {
        $this->locations()->installBangladesh();

        // নিয়ম ৯ — বাংলা নাম না থাকলে বাংলা রিপোর্টেও ইংরেজি থাকত
        $missing = Location::query()
            ->where(fn ($q) => $q->whereNull('name_bn')->orWhere('name_bn', ''))
            ->pluck('code')
            ->all();

        $this->assertSame([], $missing);
    }

    // ── ছয়টা তালিকা ────────────────────────────────────────────────────

    public function test_installing_the_defaults_makes_the_first_invoice_possible(): void
    {
        $this->lists()->installDefaults();

        /*
         * গোনা হয় তালিকায় কী আছে, installDefaults() কয়টা সারি বানাল
         * তা নয়। কোম্পানি তৈরির অংশ হিসেবেই তালিকাগুলো বসে যায়, তাই
         * এখানে ডাকলে সে ০ ফেরত দেয় — আর সেটাই কাম্য। প্রশ্নটা
         * "কয়টা তৈরি হলো" নয়, "প্রথম বিলটা লেখা যায় কি না"।
         *
         * একক নেই মানে পরিমাণের এককই নেই; শর্ত নেই মানে বকেয়ার তারিখ নেই।
         */
        $this->assertTrue(Unit::query()->exists(), 'একক ছাড়া পরিমাণ লেখা যায় না।');
        $this->assertTrue(Tax::query()->exists());
        $this->assertTrue(PaymentTerm::query()->exists(), 'শর্ত ছাড়া বকেয়ার তারিখ নেই।');
        $this->assertTrue(PartyType::query()->exists());
        $this->assertTrue(ReasonCode::query()->exists());
    }

    public function test_installing_twice_changes_nothing(): void
    {
        $this->lists()->installDefaults();
        $before = Unit::query()->count();

        $this->lists()->installDefaults();

        $this->assertSame($before, Unit::query()->count());
    }

    /**
     * ধরনগুলো সারি, enum নয়।
     *
     * প্রতিষ্ঠান নিজেই নতুন ধরন যোগ করতে পারবে — enum লিখলে প্রতিটা
     * নতুন ধরনের জন্য একটা রিলিজ লাগত, আর ততদিন কেউ "অন্যান্য" লিখে
     * কাজ চালাত।
     */
    public function test_a_company_can_add_its_own_party_type(): void
    {
        $this->lists()->installDefaults();

        $this->post(route('master_data.party_type.store'), [
            'code' => 'SUBDLR',
            'name_en' => 'Sub-dealer',
            'name_bn' => 'সাব-ডিলার',
            'applies_to' => PartyType::CUSTOMER,
        ])->assertRedirect();

        $this->assertNotNull(PartyType::query()->where('code', 'SUBDLR')->first());
    }

    public function test_only_one_record_can_be_the_default(): void
    {
        $this->lists()->installDefaults();

        $cash = PaymentTerm::query()->where('code', 'CASH')->firstOrFail();
        $net30 = PaymentTerm::query()->where('code', 'NET30')->firstOrFail();

        $this->assertTrue($cash->is_default);

        $net30->makeDefault();

        $this->assertFalse($cash->fresh()->is_default);
        $this->assertTrue($net30->fresh()->is_default);
        $this->assertSame(1, PaymentTerm::query()->default()->count());
    }

    public function test_the_default_cannot_be_deactivated(): void
    {
        $this->lists()->installDefaults();

        $default = PaymentTerm::query()->default()->firstOrFail();

        $this->expectException(ValidationException::class);

        $this->lists()->deactivate($default);
    }

    /**
     * দুইটা তালিকায় ডিফল্ট বলে কিছু নেই।
     *
     * একটা এককই "ডিফল্ট একক" হওয়ার মানে নেই (পণ্য নিজের একক বলে), আর
     * কারণ কোডেও নয় (কারণটা ঘটনার উপর নির্ভর করে)। শর্ত ছাড়া
     * is_default ধরে নিলে ওই দুইটা তালিকা ৫০০ দিত — আর দিয়েছিলও।
     */
    public function test_lists_without_a_default_still_open(): void
    {
        $this->lists()->installDefaults();

        $this->assertFalse(Unit::supportsDefault());
        $this->assertFalse(ReasonCode::supportsDefault());
        $this->assertTrue(PaymentTerm::supportsDefault());

        $this->get(route('master_data.unit.index'))->assertOk();
        $this->get(route('master_data.reason.index'))->assertOk();
    }

    public function test_two_records_in_one_list_cannot_share_a_code(): void
    {
        // প্রমিত তালিকার কোড নয় — ওগুলো কোম্পানি তৈরির সময়েই বসে
        // যায়, তাই এখানে ব্যবহার করলে টেস্টটা নিজের নিয়মে নয়, সিডারের
        // কারণে পাস করত
        $this->lists()->create(Unit::class, ['code' => 'DUP-1', 'name_en' => 'Piece', 'factor' => 1]);

        $this->expectException(ValidationException::class);

        $this->lists()->create(Unit::class, ['code' => 'DUP-1', 'name_en' => 'Pieces', 'factor' => 1]);
    }

    // ── একক ও রূপান্তর ─────────────────────────────────────────────────

    public function test_a_unit_converts_through_every_level_to_its_base(): void
    {
        $gram = $this->lists()->create(Unit::class, [
            'code' => 'T-G', 'name_en' => 'Gram', 'factor' => 1, 'allows_fraction' => true,
        ]);

        $kg = $this->lists()->create(Unit::class, [
            'code' => 'T-KG', 'name_en' => 'Kilogram', 'factor' => 1000, 'base_unit_id' => $gram->id,
        ]);

        $bag = $this->lists()->create(Unit::class, [
            'code' => 'T-BAG', 'name_en' => 'Bag', 'factor' => 25, 'base_unit_id' => $kg->id,
        ]);

        // ১ বস্তা = ২৫ কেজি = ২৫,০০০ গ্রাম — এক স্তরে থামলে ২৫ আসত
        $this->assertSame('25000.000000', $bag->fresh()->toBase('1'));
    }

    public function test_a_unit_cannot_be_its_own_base(): void
    {
        $pcs = $this->lists()->create(Unit::class, ['code' => 'T-PCS', 'name_en' => 'Piece', 'factor' => 1]);
        $ctn = $this->lists()->create(Unit::class, [
            'code' => 'T-CTN', 'name_en' => 'Carton', 'factor' => 12, 'base_unit_id' => $pcs->id,
        ]);

        $this->expectException(ValidationException::class);

        // পিসের ভিত্তি কার্টন — চক্র, আর toBase() কখনো থামত না
        $this->lists()->update($pcs, ['base_unit_id' => $ctn->id]);
    }

    // ── কর ──────────────────────────────────────────────────────────────

    /**
     * দামের ভেতরের কর উল্টো নিয়মে কষা হয়।
     *
     * ১১৫ টাকায় ১৫% ভ্যাট থাকলে কর ১১৫ × ০.১৫ = ১৭.২৫ নয়,
     * ১১৫ − (১১৫ ÷ ১.১৫) = ১৫। বাইরের নিয়মে কষলে প্রতিটা খুচরা বিলে
     * কর বেশি বসত — আর যোগফল তবু মিলত, শুধু ভুল অঙ্কে।
     */
    #[DataProvider('taxCases')]
    public function test_tax_is_computed_the_right_way_round(bool $inclusive, string $amount, string $expected): void
    {
        $tax = $this->lists()->create(Tax::class, [
            'code' => 'V15', 'name_en' => 'VAT 15%', 'rate' => 15,
            'kind' => 'vat', 'is_inclusive' => $inclusive,
        ]);

        $this->assertSame($expected, $tax->amountOn($amount));
    }

    public static function taxCases(): array
    {
        return [
            'added on top' => [false, '100.00', '15.0000'],
            'already inside' => [true, '115.00', '15.0000'],
        ];
    }

    public function test_a_tax_rate_outside_zero_to_hundred_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->lists()->create(Tax::class, [
            'code' => 'BAD', 'name_en' => 'Impossible', 'rate' => 150, 'kind' => 'vat',
        ]);
    }

    // ── কারণ কোড ────────────────────────────────────────────────────────

    /**
     * ফেরত পণ্য স্টকে ফেরে কি না — কারণ কোডেই বলা।
     *
     * "গ্রাহক পছন্দ করেনি" আর "মেয়াদ শেষ" দুইটাই ফেরত, কিন্তু একটা
     * স্টকে ফেরে আর অন্যটা নষ্ট। প্রতিবার হাতে ঠিক করতে বললে কেউ ভুল
     * করত, আর নষ্ট মাল আবার বিক্রি হয়ে যেত।
     */
    public function test_a_reason_code_says_whether_the_goods_come_back(): void
    {
        $this->lists()->installDefaults();

        $this->assertFalse(ReasonCode::query()->where('code', 'EXPIRED')->firstOrFail()->returns_to_stock);
        $this->assertTrue(ReasonCode::query()->where('code', 'UNSOLD')->firstOrFail()->returns_to_stock);
    }

    public function test_reason_codes_are_scoped_to_where_they_are_used(): void
    {
        $this->lists()->installDefaults();

        $returns = ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->pluck('code')->all();

        $this->assertContains('DAMAGE', $returns);
        // স্টক সমন্বয়ের কারণ বিক্রয় ফেরতের তালিকায় আসা উচিত নয়
        $this->assertNotContains('COUNT', $returns);
    }

    // ── টেন্যান্ট ও অনুমতি ──────────────────────────────────────────────

    public function test_one_company_never_sees_another_companys_master_data(): void
    {
        $this->locations()->installBangladesh();

        /*
         * দুই কোম্পানিরই নিজের প্রমিত তালিকা আছে (কোম্পানি তৈরির সময়েই
         * বসে), তাই "অন্যটায় শূন্য" দিয়ে আর বিচ্ছিন্নতা মাপা যায় না —
         * ওটা বরং প্রমাণ করত তালিকাগুলো বসেইনি।
         *
         * আসল প্রশ্ন: এই কোম্পানিতে বানানো একটা সারি ওই কোম্পানিতে
         * দেখা যায় কি না, আর দুই দিকের সারিগুলো আলাদা কি না।
         */
        $mine = $this->lists()->create(Unit::class, [
            'code' => 'ONLY-MINE',
            'name_en' => 'Only Mine',
            'name_bn' => 'শুধু আমার',
            'factor' => 1,
        ]);

        $myUnitIds = Unit::query()->pluck('id')->all();

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $this->assertNull(Unit::query()->find($mine->id));
        $this->assertFalse(Unit::query()->where('code', 'ONLY-MINE')->exists());

        // অবস্থান দুই কোম্পানিতেই বসানো হয় না, তাই ওখানে শূন্যই সঠিক
        $this->assertSame(0, Location::query()->count());

        // একই কোড দুই কোম্পানিতে থাকতে পারে, কিন্তু সারিগুলো আলাদা
        $this->assertSame([], array_intersect($myUnitIds, Unit::query()->pluck('id')->all()));
    }

    public function test_view_permission_alone_cannot_change_anything(): void
    {
        $this->lists()->installDefaults();

        $reader = User::factory()->create();
        $reader->companies()->attach($this->company, ['is_active' => true]);
        $reader->forceFill(['current_company_id' => $this->company->id])->save();
        $reader->givePermissionTo(Permission::findOrCreate('master_data.view', 'web'));

        $unit = Unit::query()->firstOrFail();

        $this->actingAs($reader);

        $this->get(route('master_data.unit.index'))->assertOk();
        $this->get(route('master_data.location.index'))->assertOk();

        $this->get(route('master_data.unit.create'))->assertForbidden();
        $this->delete(route('master_data.unit.destroy', $unit->id))->assertForbidden();
        $this->get(route('master_data.location.create'))->assertForbidden();

        // নম্বর সিরিজ manage ছাড়া দেখাও যায় না — উপসর্গ বদলানো
        // প্রতিটা ভবিষ্যৎ ডকুমেন্টের নম্বর বদলে দেয়
        $this->get(route('master_data.series.index'))->assertForbidden();
    }

    public function test_every_list_screen_opens(): void
    {
        $this->lists()->installDefaults();

        foreach (['unit', 'tax', 'term', 'price_list', 'party_type', 'reason'] as $route) {
            $this->get(route("master_data.{$route}.index"))->assertOk();
            $this->get(route("master_data.{$route}.create"))->assertOk();
        }

        $this->get(route('master_data.location.index'))->assertOk();
        $this->get(route('master_data.series.index'))->assertOk();
    }

    public function test_an_unknown_list_is_a_404(): void
    {
        // রুটগুলো KINDS থেকে তৈরি, তাই অজানা তালিকার কোনো রুটই নেই
        $this->get('/master-data/nonsense')->assertNotFound();
    }

    // ── নম্বর সিরিজ ─────────────────────────────────────────────────────

    /**
     * পরের নম্বর বদলানো যায় না।
     *
     * পিছিয়ে দিলে একই নম্বর দুইবার ইস্যু হত, এগিয়ে দিলে অডিটে
     * অব্যাখ্যাত ফাঁক থাকত। দুইটাই মাস পরে ধরা পড়ে।
     */
    public function test_the_next_number_cannot_be_edited(): void
    {
        $series = NumberSeries::query()->firstOrFail();
        $before = $series->next_number;

        $this->put(route('master_data.series.update', $series), [
            'prefix' => 'XYZ',
            'padding' => 4,
            'format' => '{PREFIX}-{SEQ}',
            'next_number' => 9999,
        ])->assertRedirect();

        $series->refresh();

        $this->assertSame('XYZ', $series->prefix);
        $this->assertSame($before, $series->next_number);
    }

    /**
     * নম্বরের ছক ব্যবহারকারী নিজে ঠিক করতে পারেন।
     *
     * ইঞ্জিনে ছকটা বরাবরই ছিল, শুধু পর্দায় খোলা ছিল না — তাই প্রতিটা
     * প্রতিষ্ঠান একই চেহারার নম্বরে আটকে থাকত।
     */
    public function test_the_number_format_can_be_changed_from_the_screen(): void
    {
        $series = NumberSeries::query()->firstOrFail();

        $this->put(route('master_data.series.update', $series), [
            'prefix' => 'INV',
            'suffix' => 'BD',
            'padding' => 5,
            'format' => '{PREFIX}/{YY}/{SEQ}/{SUFFIX}',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $series->refresh();

        $this->assertSame('{PREFIX}/{YY}/{SEQ}/{SUFFIX}', $series->format);

        // নমুনাটা ইঞ্জিনের নিজের কোড থেকে আসে, তাই এটাই আসল নম্বরের চেহারা
        $preview = app(NumberSeriesEngine::class)->preview($series);

        $this->assertStringStartsWith('INV/', $preview);
        $this->assertStringEndsWith('/BD', $preview);
        $this->assertStringContainsString(str_pad((string) $series->next_number, 5, '0', STR_PAD_LEFT), $preview);
    }

    /**
     * অনুসর্গ খালি রাখা যায়।
     *
     * কলামটা NOT NULL, তাই null পাঠালে সেভ করার সময় ডাটাবেজ ব্যতিক্রম
     * ছুঁড়ত আর ব্যবহারকারী একটা ৫০০ পাতা পেতেন — অথচ ঘরটা ঐচ্ছিকই।
     * ব্রাউজারে পরীক্ষা করার সময় অনুসর্গ দেওয়া ছিল বলে ধরা পড়েনি।
     */
    public function test_the_suffix_may_be_left_empty(): void
    {
        $series = NumberSeries::query()->firstOrFail();

        $this->put(route('master_data.series.update', $series), [
            'prefix' => 'INV',
            'suffix' => '',
            'padding' => 4,
            'format' => '{PREFIX}-{SEQ}',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('', $series->fresh()->suffix);
    }

    /**
     * {SEQ} ছাড়া ছক নেওয়া হয় না।
     *
     * নিলে প্রতিটা ডকুমেন্ট একই নম্বর পেত — "INV-2026" বারবার। সেভ করার
     * সময় কোনো ভুল দেখাত না; ধরা পড়ত অনেক পরে, যখন দুইটা ভিন্ন বিলের
     * নম্বর এক হয়ে যেত, আর ততদিনে কাগজ ছাপা হয়ে গেছে।
     */
    public function test_a_format_without_the_running_number_is_refused(): void
    {
        $series = NumberSeries::query()->firstOrFail();
        $before = $series->format;

        $this->put(route('master_data.series.update', $series), [
            'prefix' => 'INV',
            'padding' => 4,
            'format' => '{PREFIX}-{YYYY}',
        ])->assertSessionHasErrors('format');

        $this->assertSame($before, $series->fresh()->format);
    }

    /**
     * পর্দার নমুনা আর ইস্যু হওয়া নম্বর একই।
     *
     * আগে পর্দাটা নিজে হাতে "{prefix}-{বছর}-{ক্রম}" জুড়ে দেখাত, আর
     * ছকটা অন্য কিছু হলে নমুনাটা মিথ্যা বলত — ব্যবহারকারী একটা দেখে
     * সেভ করতেন, ডকুমেন্টে বসত অন্যটা।
     */
    public function test_the_sample_on_screen_matches_the_number_actually_issued(): void
    {
        $series = NumberSeries::query()->where('doc_type', 'CUS')->firstOrFail();

        $engine = app(NumberSeriesEngine::class);

        $shown = $engine->preview($series);
        $issued = $engine->next('CUS');

        $this->assertSame($shown, $issued);
    }
}
