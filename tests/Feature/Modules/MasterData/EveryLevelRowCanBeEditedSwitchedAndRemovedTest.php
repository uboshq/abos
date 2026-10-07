<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ প্রতিটা স্তরের ট্যাবে প্রতিটা সারির নিজের কাজ — মালিকের নির্দেশ,
 * ১৯ সেপ্টেম্বর ২০২৬: *"alada alada create"*, আর Action কলামে সম্পাদনা ·
 * সক্রিয়/নিষ্ক্রিয় · মুছুন।
 *
 * ── ⛔ লাইভে যা ঘটেছিল ───────────────────────────────────────────────
 * মালিক "নতুন এরিয়া" খুলে ফর্মের স্তরের ড্রপডাউনটা বিভাগে বদলে দেন।
 * ⚠️ ফলে একটা বাড়তি বিভাগ তৈরি হল, আর এরিয়াটা বসল তার নিচে — আর
 * সেই ভুল সারি দুইটা সরানোর কোনো বোতাম পর্দায় ছিল না।
 *
 * ⭐ তাই এখানে দুই দিকের দাবি: ফর্মে স্তর বাঁধা (ভুলটা আর হয় না), আর
 * সারিতে মোছা/নিষ্ক্রিয় (আগের ভুলটা মালিক নিজেই সরাতে পারেন) — কিন্তু
 * মোছা কখনো নিচের কিছু বা পুরনো কাগজকে বাবাহীন করে না।
 */
final class EveryLevelRowCanBeEditedSwitchedAndRemovedTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Location> */
    private array $chain = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $parent = null;

        foreach (Location::activeLadder() as $index => $level) {
            $parent = Location::query()->create([
                'company_id' => CompanyContext::id(),
                'parent_id' => $parent?->id,
                'code' => 'ROW'.$index,
                'name_en' => 'Rowname-'.$level,
                'level' => $level,
                'is_active' => true,
            ]);

            $this->chain[$level] = $parent;
        }
    }

    private function last(): Location
    {
        return $this->chain[array_key_last($this->chain)];
    }

    /**
     * ⛔ "নতুন পয়েন্ট" থেকে খোলা ফর্মে স্তরের ড্রপডাউন নেই — স্তরটা
     * লুকানো ঘরে, আর শিরোনামেই লেখা কী তৈরি হচ্ছে।
     */
    public function test_the_form_opened_from_a_level_cannot_switch_level(): void
    {
        $form = $this->get(route('master_data.location.create', ['level' => Location::POINT]))->assertOk();

        $form->assertDontSee('<select name="level"', escape: false);
        $form->assertSee('<input type="hidden" name="level" value="'.Location::POINT.'">', escape: false);
        $form->assertSee(__('master_data::action.new_level', ['level' => __('master_data::level.point')]));
    }

    /**
     * ⭐ সংরক্ষণের পর সেই স্তরের ট্যাবে ফেরা, গাছে নয়।
     */
    public function test_saving_returns_to_that_levels_tab(): void
    {
        $this->post(route('master_data.location.store'), [
            'level' => Location::POINT,
            'name_en' => 'Fresh point',
            'parent_id' => $this->chain[Location::parentLevelOf(Location::POINT)]->id,
        ])->assertRedirect(route('master_data.location.level', ['level' => Location::POINT]));
    }

    /**
     * ⭐ স্তরের ট্যাবে প্রতিটা সারিতে তিনটা কাজের ঠিকানা।
     */
    public function test_a_level_tab_offers_edit_deactivate_and_delete_per_row(): void
    {
        $point = $this->chain[Location::POINT];

        $page = $this->get(route('master_data.location.level', ['level' => Location::POINT]))->assertOk();

        $page->assertSee(route('master_data.location.edit', $point), escape: false);
        $page->assertSee('action="'.route('master_data.location.destroy', $point).'"', escape: false);
        $page->assertSee('action="'.route('master_data.location.purge', $point).'"', escape: false);
        $page->assertDontSee(route('master_data.location.activate', $point), escape: false);
    }

    /**
     * ⭐ নিষ্ক্রিয় সারিতে "সক্রিয় করুন" — আর সেটা সত্যিই ফেরায়,
     * উপরের বন্ধ বাবাসহ (নইলে সারিটা গাছে খুঁজে পাওয়া যেত না)।
     */
    public function test_an_inactive_row_can_be_switched_back_on(): void
    {
        $point = $this->chain[Location::POINT];
        $this->delete(route('master_data.location.destroy', $point));
        $this->assertFalse($point->fresh()->is_active);

        $page = $this->get(route('master_data.location.level', ['level' => Location::POINT, 'inactive' => 1]))->assertOk();
        $page->assertSee('action="'.route('master_data.location.activate', $point).'"', escape: false);

        $this->post(route('master_data.location.activate', $point))->assertSessionHasNoErrors();

        $this->assertTrue($point->fresh()->is_active);
        $this->assertTrue($point->fresh()->parent->is_active);
    }

    /**
     * ⛔ নিচে সন্তান থাকলে মোছা নয় — আর কারণটা বলা হয়।
     */
    public function test_a_row_with_children_is_not_deleted_and_says_why(): void
    {
        $point = $this->chain[Location::POINT];

        $this->from(route('master_data.location.level', ['level' => Location::POINT]))
            ->delete(route('master_data.location.purge', $point))
            ->assertSessionHasErrors('location');

        $this->assertNotNull(Location::query()->withTrashed()->find($point->id), 'সন্তানসহ এলাকাটা মুছে গেছে।');

        $message = session('errors')->first('location');
        $this->assertStringContainsString(__('master_data::level.'.Location::childLevelOf(Location::POINT)), $message,
            'বার্তা বলেনি নিচে কী আছে।');
    }

    /**
     * ⛔ গ্রাহক বাঁধা থাকলে মোছা নয় — পুরনো কাগজ বাবাহীন হত।
     */
    public function test_a_row_a_customer_points_at_is_not_deleted(): void
    {
        $leaf = $this->last();
        $customer = DB::table('customers')->where('company_id', CompanyContext::id())->orderBy('id')->first();
        DB::table('customers')->where('id', $customer->id)->update(['location_id' => $leaf->id]);

        $this->delete(route('master_data.location.purge', $leaf))->assertSessionHasErrors('location');

        $this->assertNotNull(Location::query()->find($leaf->id), 'গ্রাহকের এলাকাটা মুছে গেছে।');
        $this->assertStringContainsString(__('master_data::validation.location_used_by.customers'),
            session('errors')->first('location'));
    }

    /**
     * ⭐ কোথাও কিছু নেই — সত্যিই মুছে যায়, আর একই কোড আবার বসানো যায়।
     *
     * ⓘ দ্বিতীয় দাবিটাই `delete()` বনাম `forceDelete()` আলাদা করে:
     * নরম মোছায় কোডটা চিরকাল আটকে থাকত।
     */
    public function test_a_free_row_is_removed_and_its_code_can_be_used_again(): void
    {
        $leaf = $this->last();
        $parentId = $leaf->parent_id;

        $this->delete(route('master_data.location.purge', $leaf))->assertSessionHasNoErrors();

        $this->assertNull(Location::query()->withTrashed()->find($leaf->id), 'এলাকাটা মোছেনি।');

        $this->post(route('master_data.location.store'), [
            'level' => $leaf->level,
            'code' => $leaf->code,
            'name_en' => 'Again',
            'parent_id' => $parentId,
        ])->assertSessionHasNoErrors();
    }

    /**
     * ⛔ মোছার চাবি ছাড়া মোছা নয় — কেবল manage থাকলে নিষ্ক্রিয়ই শেষ কথা।
     */
    public function test_delete_needs_its_own_permission(): void
    {
        $clerk = User::factory()->create();
        $company = Company::query()->whereKey(CompanyContext::id())->firstOrFail();
        $clerk->companies()->attach($company, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $company->id])->save();
        $clerk->givePermissionTo(
            Permission::findOrCreate('master_data.view', 'web'),
            Permission::findOrCreate('master_data.manage', 'web'),
        );

        $this->actingAs($clerk);
        $leaf = $this->last();

        $this->delete(route('master_data.location.purge', $leaf))->assertForbidden();
        $this->assertNotNull(Location::query()->find($leaf->id));

        $page = $this->get(route('master_data.location.level', ['level' => $leaf->level]))->assertOk();
        $page->assertDontSee(route('master_data.location.purge', $leaf), escape: false);
        $page->assertSee(route('master_data.location.edit', $leaf), escape: false);
    }
}
