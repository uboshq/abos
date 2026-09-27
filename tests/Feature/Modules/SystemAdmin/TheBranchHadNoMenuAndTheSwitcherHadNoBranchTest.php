<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * শাখার কোনো মেনু ছিল না, আর সুইচারে শাখা বদলানো যেত না — মালিকের নির্দেশ, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⓘ কী মাপা হলো ──────────────────────────────────────────────────
 * ⓵ "সিস্টেম প্রশাসন → শাখা": তালিকা, নতুন, সম্পাদনা, নিষ্ক্রিয় — নিয়ম
 *   [[BranchDesk]]-এর, কোম্পানির পাতাও একই সেবা ডাকে।
 * ⓶ সুইচার: শাখা খোলার সহজ পথ না থাকায় বেশিরভাগ কোম্পানিতে শাখা একটাই, তাই
 *   সুইচারে শাখার তালিকা আসতই না। ⛔ আর তালিকা ও দরজা দুইটাই মানুষটার
 *   শাখা-অধিকার ([[DataScope]] `branch`) দেখত না — এক শাখায় বাঁধা কর্মী
 *   ঠিকানা দিয়ে অন্য শাখায় ঢুকতে পারতেন।
 *
 * ⭐ দরজার দাবি একই মানুষ দিয়ে — চাবি ছাড়া, তারপর চাবিসহ।
 */
final class TheBranchHadNoMenuAndTheSwitcherHadNoBranchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->alpha = Company::query()->findOrFail($this->owner->current_company_id);
        $this->beta = $this->owner->companies()->where('companies.id', '!=', $this->alpha->id)->firstOrFail();

        CompanyContext::set($this->alpha->id, $this->alpha->defaultBranch()?->id);
    }

    private function branchesOf(Company $company)
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $company->id);
    }

    private function openBranch(Company $company, string $code, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('system_admin.branch.create'))
            ->post(route('system_admin.branch.store'), [
                'company_id' => $company->id,
                'code' => $code,
                'name_en' => 'Branch '.$code,
                'name_bn' => 'শাখা '.$code,
            ]);
    }

    /** ⭐ একই মানুষ: চাবি ছাড়া ৪০৩, চাবি পেলে তালিকা খোলে আর একটা সারি দেখায়। */
    public function test_the_same_person_needs_the_company_key_to_open_the_branch_list(): void
    {
        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach($this->alpha->id, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->alpha->id])->save();

        $this->actingAs($clerk)->get(route('system_admin.branch.index'))->assertForbidden();

        $clerk->givePermissionTo(Permission::findOrCreate('system_admin.company.manage', 'web'));

        $existing = $this->branchesOf($this->alpha)->orderBy('id')->firstOrFail();

        $this->actingAs($clerk->fresh())
            ->get(route('system_admin.branch.index'))
            ->assertOk()
            ->assertSee($existing->code);
    }

    /** ⭐ মেনুতে "শাখা" সারিটা আছে, আর পাতাটা খোলে। */
    public function test_the_menu_carries_a_branches_row(): void
    {
        $this->actingAs($this->owner)
            ->get(route('system_admin.company.index'))
            ->assertOk()
            ->assertSee(route('system_admin.branch.index'), false);
    }

    /** ⭐ নতুন শাখা বাছা কোম্পানিতেই বসে — চলতিটায় নয়। */
    public function test_a_new_branch_lands_in_the_chosen_company(): void
    {
        $this->openBranch($this->beta, 'nb1')->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue($this->branchesOf($this->beta)->where('code', 'NB1')->exists(),
            'শাখাটা বাছা কোম্পানিতে বসেনি।');
        $this->assertFalse($this->branchesOf($this->alpha)->where('code', 'NB1')->exists(),
            'শাখাটা চলতি কোম্পানিতে বসে গেছে।');
    }

    /**
     * ⛔ একই কোম্পানিতে একই কোড দুইবার নয় — ঘরের পাশে বার্তা, খালি ৪২২ পাতা নয়।
     *
     * ⓘ পাল্টা-দাবি: অন্য কোম্পানিতে একই কোড চলে।
     */
    public function test_a_duplicate_code_in_the_same_company_is_refused(): void
    {
        $this->openBranch($this->alpha, 'DUP')->assertSessionHasNoErrors();
        $before = $this->branchesOf($this->alpha)->count();

        $this->openBranch($this->alpha, 'dup')->assertSessionHasErrors('code');
        $this->assertSame($before, $this->branchesOf($this->alpha)->count());

        $this->openBranch($this->beta, 'DUP')->assertSessionHasNoErrors();
    }

    /** ⛔ যে কোম্পানিতে আপনি নেই, সেখানে শাখা খোলা বা সম্পাদনা নয়। */
    public function test_another_companys_branch_is_out_of_reach(): void
    {
        $stranger = Company::query()->whereNotIn('id', $this->owner->companies()->pluck('companies.id'))->first()
            ?? Company::create(['code' => 'OUTSD', 'name_en' => 'Outside Co', 'is_active' => true]);

        $this->openBranch($stranger, 'SNK')->assertSessionHasErrors('company_id');
        $this->assertFalse($this->branchesOf($stranger)->where('code', 'SNK')->exists());

        $theirs = CompanyContext::forCompany($stranger->id, fn () => Branch::create([
            'code' => 'THEIRS', 'name_en' => 'Theirs', 'is_active' => true, 'is_default' => false,
        ]));

        $this->actingAs($this->owner)->get(route('system_admin.branch.edit', $theirs->id))->assertNotFound();
        $this->actingAs($this->owner)->get(route('system_admin.branch.index'))->assertDontSee('THEIRS');
    }

    /** ⛔ ডিফল্ট শাখা নিষ্ক্রিয় হয় না; অন্য শাখা হয়, আর আবার সচলও হয়। */
    public function test_the_default_branch_cannot_be_switched_off_but_another_can(): void
    {
        $default = $this->branchesOf($this->alpha)->where('is_default', true)->firstOrFail();

        $this->actingAs($this->owner)->from(route('system_admin.branch.index'))
            ->post(route('system_admin.branch.toggle', $default->id))
            ->assertSessionHasErrors('is_active');
        $this->assertTrue($default->fresh()->is_active);

        $this->openBranch($this->alpha, 'TGL');
        $other = $this->branchesOf($this->alpha)->where('code', 'TGL')->firstOrFail();

        $this->actingAs($this->owner)->from(route('system_admin.branch.index'))
            ->post(route('system_admin.branch.toggle', $other->id))->assertSessionHasNoErrors();
        $this->assertFalse($other->fresh()->is_active);
    }

    /** ⭐ অব্যবহৃত শাখা মুছে যায় (soft delete), আর তালিকা থেকে সরে যায়। */
    public function test_an_unused_branch_can_be_deleted(): void
    {
        $this->openBranch($this->alpha, 'GONE');
        $branch = $this->branchesOf($this->alpha)->where('code', 'GONE')->firstOrFail();

        $this->actingAs($this->owner)->from(route('system_admin.branch.index'))
            ->delete(route('system_admin.branch.destroy', $branch->id))
            ->assertSessionHasNoErrors();

        /* ⚠️ withoutGlobalScopes() soft-delete-এর ছাঁকনিও সরায় — তাই সরাসরি `trashed()` মাপা */
        $row = Branch::query()->withoutGlobalScopes()->find($branch->id);
        $this->assertNotNull($row, 'শাখাটা একেবারে মুছে গেছে — soft delete হওয়ার কথা।');
        $this->assertTrue($row->trashed(), 'অব্যবহৃত শাখা মোছেনি।');

        $this->actingAs($this->owner)->get(route('system_admin.branch.index'))->assertDontSee('>GONE<', false);
    }

    /**
     * ⛔ ব্যবহৃত শাখা মোছা যায় না — "নিষ্ক্রিয় করুন" বার্তা।
     *
     * ⚠️ বিপজ্জনক ইনপুট: একজন ব্যবহারকারী ঐ শাখায় বসে আছেন (`current_branch_id`)।
     * ⓘ ডিফল্ট শাখা আর কোম্পানির শেষ শাখাও মোছা যায় না।
     */
    public function test_a_used_default_or_last_branch_cannot_be_deleted(): void
    {
        $this->openBranch($this->alpha, 'BUSY');
        $busy = $this->branchesOf($this->alpha)->where('code', 'BUSY')->firstOrFail();

        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach($this->alpha->id, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->alpha->id, 'current_branch_id' => $busy->id])->save();

        $this->actingAs($this->owner)->from(route('system_admin.branch.index'))
            ->delete(route('system_admin.branch.destroy', $busy->id))
            ->assertSessionHasErrors('branch');
        $this->assertNotNull($busy->fresh(), 'ব্যবহৃত শাখা মুছে গেছে।');

        $default = $this->branchesOf($this->alpha)->where('is_default', true)->firstOrFail();
        $this->actingAs($this->owner)->from(route('system_admin.branch.index'))
            ->delete(route('system_admin.branch.destroy', $default->id))
            ->assertSessionHasErrors('branch');
        $this->assertNotNull($default->fresh());
    }

    /** ⭐ সম্পাদনায় নাম বদলায়, আর কোড নিয়মে বড় হাতের হয়। */
    public function test_a_branch_can_be_renamed(): void
    {
        $this->openBranch($this->alpha, 'REN');
        $branch = $this->branchesOf($this->alpha)->where('code', 'REN')->firstOrFail();

        $this->actingAs($this->owner)->from(route('system_admin.branch.edit', $branch->id))
            ->put(route('system_admin.branch.update', $branch->id), [
                'code' => 'ren2', 'name_en' => 'Renamed', 'name_bn' => 'নতুন নাম',
            ])->assertSessionHasNoErrors();

        $fresh = $branch->fresh();
        $this->assertSame('REN2', $fresh->code);
        $this->assertSame('Renamed', $fresh->name_en);
    }

    /**
     * ⭐ দ্বিতীয় শাখা খোলার পরে সুইচারে সেটা আসে, আর বদলালে চলতি শাখা বদলায়।
     *
     * ⓘ পরের পাতায় সুইচারের বোতাম নতুন শাখার নাম বন্ধনীতে দেখায় — অর্থাৎ
     * পরের অনুরোধের প্রসঙ্গও নতুন শাখায়।
     */
    public function test_a_second_branch_shows_in_the_switcher_and_switching_moves_the_user(): void
    {
        $this->openBranch($this->alpha, 'SW2');
        $second = $this->branchesOf($this->alpha)->where('code', 'SW2')->firstOrFail();

        $this->actingAs($this->owner->fresh())
            ->get(route('system_admin.branch.index'))
            ->assertOk()
            /* ⓘ কোড, নাম নয় — পাতা বাংলায় বাংলা নাম দেখায়, কোড দুই ভাষাতেই এক */
            ->assertSee('SW2');

        $this->actingAs($this->owner->fresh())
            ->from(route('system_admin.branch.index'))
            ->post(route('branch.switch'), ['branch_id' => $second->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($second->id, (int) $this->owner->fresh()->current_branch_id);

        $this->actingAs($this->owner->fresh())
            ->get(route('system_admin.company.index'))
            ->assertOk()
            ->assertSee('('.$second->name().')', false);
    }

    /**
     * ⛔ এক শাখায় বাঁধা কর্মী অন্য শাখায় বদলাতে পারেন না — সুইচারেও দেখেন না।
     *
     * ⚠️ বিপজ্জনক ইনপুট: অধিকারের বাইরের শাখার id সরাসরি পাঠানো।
     * ⓘ পাল্টা-দাবি: নিজের শাখায় বদলানো চলে।
     */
    public function test_a_branch_bound_clerk_cannot_switch_into_another_branch(): void
    {
        $this->openBranch($this->alpha, 'OWN');
        $this->openBranch($this->alpha, 'OFF');
        $own = $this->branchesOf($this->alpha)->where('code', 'OWN')->firstOrFail();
        $off = $this->branchesOf($this->alpha)->where('code', 'OFF')->firstOrFail();

        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach($this->alpha->id, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->alpha->id, 'current_branch_id' => $own->id])->save();

        UserDataScope::query()->create([
            'company_id' => $this->alpha->id,
            'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $own->id,
        ]);
        app(DataScope::class)->forget();

        $this->actingAs($clerk->fresh())
            ->from(route('dashboard'))
            ->post(route('branch.switch'), ['branch_id' => $off->id])
            ->assertSessionHasErrors('branch_id');
        $this->assertSame($own->id, (int) $clerk->fresh()->current_branch_id,
            'অধিকারের বাইরের শাখায় বদলে গেছে।');

        app(DataScope::class)->forget();
        $this->actingAs($clerk->fresh())
            ->from(route('dashboard'))
            ->post(route('branch.switch'), ['branch_id' => $own->id])
            ->assertSessionHasNoErrors();

        /* ⚠️ ShellFacts আর DataScope দুইটাই `scoped` আর উত্তর জমায় — আগের অনুরোধের জমা মুছে তবে পড়া */
        app()->forgetScopedInstances();
        $this->actingAs($clerk->fresh());
        CompanyContext::set($this->alpha->id, $own->id);
        $listed = app(\App\Core\Services\ShellFacts::class)->branches()->pluck('id')->all();
        $this->assertNotContains($off->id, $listed, 'সুইচারের তালিকায় অধিকারের বাইরের শাখা দেখাচ্ছে।');
        $this->assertContains($own->id, $listed);
    }

    /**
     * ⛔ কোম্পানি বদলালে ডিফল্ট শাখা সীমার বাইরে হলে সীমার শাখাতেই বসা।
     *
     * ⚠️ বিপজ্জনক পথ: শাখা না বেছে কোম্পানি বদলানো — পুরনো কোড তখন সোজা
     * কোম্পানির ডিফল্ট শাখায় বসাত, মানুষটার অধিকার না দেখে।
     */
    public function test_switching_company_never_lands_outside_the_clerks_branches(): void
    {
        $this->openBranch($this->beta, 'BONLY');
        $betaDefault = $this->branchesOf($this->beta)->where('is_default', true)->firstOrFail();
        $betaOnly = $this->branchesOf($this->beta)->where('code', 'BONLY')->firstOrFail();
        $this->assertNotSame($betaDefault->id, $betaOnly->id, 'দৃশ্যটাই বানানো যায়নি।');

        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach([$this->alpha->id => ['is_active' => true], $this->beta->id => ['is_active' => true]]);
        $clerk->forceFill(['current_company_id' => $this->alpha->id])->save();

        UserDataScope::query()->create([
            'company_id' => $this->beta->id,
            'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $betaOnly->id,
        ]);
        app(DataScope::class)->forget();

        $this->actingAs($clerk->fresh())
            ->from(route('dashboard'))
            ->post(route('company.switch'), ['company_id' => $this->beta->id])
            ->assertSessionHasNoErrors();

        $fresh = $clerk->fresh();
        $this->assertSame($this->beta->id, (int) $fresh->current_company_id);
        $this->assertSame($betaOnly->id, (int) $fresh->current_branch_id,
            'কোম্পানি বদলে মানুষটা অধিকারের বাইরের ডিফল্ট শাখায় বসে গেছেন।');
    }

    /** ⭐ সীমা ছাড়া মানুষ (মালিক) কোম্পানির সব শাখায় যেতে পারেন — সুইচারেও সবগুলো দেখেন। */
    public function test_an_unbounded_owner_reaches_every_branch(): void
    {
        $this->openBranch($this->alpha, 'EV1');
        $this->openBranch($this->alpha, 'EV2');

        foreach (['EV1', 'EV2'] as $code) {
            $branch = $this->branchesOf($this->alpha)->where('code', $code)->firstOrFail();

            $this->actingAs($this->owner->fresh())
                ->from(route('dashboard'))
                ->post(route('branch.switch'), ['branch_id' => $branch->id])
                ->assertSessionHasNoErrors();
            $this->assertSame($branch->id, (int) $this->owner->fresh()->current_branch_id);
        }

        app()->forgetScopedInstances();
        $this->actingAs($this->owner->fresh());
        CompanyContext::set($this->alpha->id, null);
        $listed = app(\App\Core\Services\ShellFacts::class)->branches()->pluck('id')->all();

        $this->assertSame(
            $this->branchesOf($this->alpha)->where('is_active', true)->pluck('id')->sort()->values()->all(),
            collect($listed)->sort()->values()->all(),
            'সীমা ছাড়া মালিক সুইচারে কোম্পানির সব শাখা দেখেননি।',
        );
    }
}
