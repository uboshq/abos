<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ একই বাবার নিচে একই স্তরে একই নাম দুইবার নয়।
 *
 * ── লাইভে যা ঘটেছিল, ১৯ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * বিভাগের ট্যাবে দুইটা "Bangladesh › Mymensingh" — MYM আর MYM2। মালিক:
 * *"ekhane duplicate howar kotha na taw hoyeche"*। ⚠️ কোডের পাহারা ধরেনি,
 * কারণ দ্বিতীয়টার কোড নিজে থেকে বানানো — নাম এক, কোড আলাদা।
 *
 * ⭐ তাই প্রতিটা দাবিতে কোড আলাদা রাখা হয়েছে: পাহারাটা নাম দেখে কি না,
 * সেটাই মাপার জিনিস।
 */
final class OneParentCannotHoldTheSameNameTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Location $country;

    private Location $division;

    /** "ময়মনসিংহ" — য় এক অক্ষরে (U+09DF) */
    private const BN_COMPOSED = "ম\u{09DF}মনসিংহ";

    /** একই নাম, য় ভাঙা আকারে (য U+09AF + ় U+09BC) — ফাইলে যেভাবে আসে */
    private const BN_DECOMPOSED = "ম\u{09AF}\u{09BC}মনসিংহ";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->country = $this->make(Location::COUNTRY, null, 'TBD', 'Testland');
        $this->division = $this->make(Location::DIVISION, $this->country, 'TMYM', 'Mymensingh', self::BN_COMPOSED);
    }

    private function make(string $level, ?Location $parent, string $code, string $en, ?string $bn = null): Location
    {
        return Location::query()->create([
            'company_id' => CompanyContext::id(),
            'parent_id' => $parent?->id,
            'code' => $code,
            'name_en' => $en,
            'name_bn' => $bn,
            'level' => $level,
            'is_active' => true,
        ]);
    }

    private function store(array $data)
    {
        return $this->post(route('master_data.location.store'), $data);
    }

    private function divisions(): int
    {
        return Location::query()->where('level', Location::DIVISION)->where('parent_id', $this->country->id)->count();
    }

    /**
     * ⛔ লাইভের হুবহু ঘটনা — কোড খালি (নিজে থেকে বসে), নাম এক, হাত আর
     * ফাঁকা আলাদা। দ্বিতীয় বিভাগ তৈরি হয় না, আর বার্তা আগেরটার কোড বলে।
     */
    public function test_the_same_name_under_the_same_parent_is_refused(): void
    {
        $this->store([
            'level' => Location::DIVISION,
            'parent_id' => $this->country->id,
            'name_en' => '  mymensingh ',
        ])->assertSessionHasErrors('name_en');

        $this->assertSame(1, $this->divisions(), 'দ্বিতীয় Mymensingh তৈরি হয়ে গেছে।');

        $this->assertSame(
            __('master_data::validation.location_name_taken', [
                'name' => 'mymensingh',
                'level' => __('master_data::level.division'),
                'parent' => $this->country->name(),
                'code' => 'TMYM',
            ]),
            session('errors')->first('name_en'),
        );
        $this->assertStringContainsString('TMYM', session('errors')->first('name_en'));
    }

    /**
     * ⛔ বাংলা নামও — আর "য়" ভাঙা আকারে (য + ়) লিখলেও একই নাম।
     */
    public function test_the_same_bangla_name_is_refused_even_when_spelled_decomposed(): void
    {
        $decomposed = self::BN_DECOMPOSED;
        $this->assertNotSame($decomposed, $this->division->name_bn, 'পরীক্ষাটা অন্ধ: দুই বানান একই বাইট।');

        $this->store([
            'level' => Location::DIVISION,
            'parent_id' => $this->country->id,
            'name_en' => 'Mymensingh North',
            'name_bn' => $decomposed,
        ])->assertSessionHasErrors('name_bn');

        $this->assertSame(1, $this->divisions());
    }

    /**
     * ⛔ নিষ্ক্রিয় সারিও গোনে — নইলে পরে সক্রিয় করলেই দুইটা।
     */
    public function test_an_inactive_twin_still_counts(): void
    {
        $this->division->forceFill(['is_active' => false])->save();

        $this->store([
            'level' => Location::DIVISION,
            'parent_id' => $this->country->id,
            'name_en' => 'Mymensingh',
        ])->assertSessionHasErrors('name_en');

        $this->assertSame(1, $this->divisions());
    }

    /**
     * ⭐ আলাদা বাবার নিচে একই নাম চলে — দুই বিভাগে "Sadar" থাকতেই পারে।
     */
    public function test_the_same_name_under_another_parent_is_allowed(): void
    {
        $other = $this->make(Location::DIVISION, $this->country, 'TDHA', 'Dhaka');
        $this->make(Location::childLevelOf(Location::DIVISION), $this->division, 'TSAD1', 'Sadar');

        $this->store([
            'level' => Location::childLevelOf(Location::DIVISION),
            'parent_id' => $other->id,
            'name_en' => 'Sadar',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Location::query()->where('name_en', 'Sadar')->count());
    }

    /**
     * ⛔ সম্পাদনায় ভাইয়ের নাম বসানো যায় না — কিন্তু নিজের নাম রেখে সেভ চলে।
     */
    public function test_editing_cannot_take_a_siblings_name_but_can_keep_its_own(): void
    {
        $dhaka = $this->make(Location::DIVISION, $this->country, 'TDHA', 'Dhaka');

        $this->put(route('master_data.location.update', $dhaka), [
            'name_en' => 'MYMENSINGH',
            'parent_id' => $this->country->id,
        ])->assertSessionHasErrors('name_en');

        $this->assertSame('Dhaka', $dhaka->fresh()->name_en);

        $this->put(route('master_data.location.update', $this->division), [
            'name_en' => 'Mymensingh',
            'name_bn' => self::BN_COMPOSED,
            'parent_id' => $this->country->id,
        ])->assertSessionHasNoErrors();
    }

    /**
     * ⭐ লাইভের পুরনো ভুলটা মালিক নিজে সারাতে পারেন: MYM2-এর নিচের
     * রিজিয়নকে সম্পাদনায় MYM-এর নিচে সরানো, তারপর খালি MYM2 মোছা।
     */
    public function test_the_duplicate_can_be_emptied_by_moving_its_child_and_then_deleted(): void
    {
        // পাহারার আগের দিনের ডেটা — সরাসরি বসানো, যেমন লাইভে আছে
        $twin = $this->make(Location::DIVISION, $this->country, 'TMYM2', 'Mymensingh');
        $region = $this->make(Location::childLevelOf(Location::DIVISION), $twin, 'TMYM3', 'Mymensingh Sadar');

        $this->put(route('master_data.location.update', $region), [
            'name_en' => 'Mymensingh Sadar',
            'parent_id' => $this->division->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->division->id, $region->fresh()->parent_id, 'রিজিয়নটা সরেনি।');

        $this->delete(route('master_data.location.purge', $twin))->assertSessionHasNoErrors();
        $this->assertNull(Location::query()->withTrashed()->find($twin->id), 'খালি হওয়া MYM2 মোছা যায়নি।');
    }

    /**
     * ⛔ সবার উপরের স্তরেও — দুইটা "Testland" দেশ নয়।
     */
    public function test_two_countries_cannot_share_a_name(): void
    {
        $this->store([
            'level' => Location::COUNTRY,
            'name_en' => 'TESTLAND',
        ])->assertSessionHasErrors('name_en');

        $this->assertSame(1, Location::query()->where('level', Location::COUNTRY)->where('name_en', 'like', 'testland')->count());
    }
}
