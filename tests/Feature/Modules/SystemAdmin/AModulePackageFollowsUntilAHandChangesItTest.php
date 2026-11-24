<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\BranchModulePackage;
use App\Models\Company;
use App\Models\ModulePackage;
use App\Models\User;
use App\Modules\SystemAdmin\Support\ModuleBoard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মডিউল প্যাকেজ আর "কেন দেখা যায় না" (মালিক, ২৪ নভেম্বর ২০২৬, নমুনা system-plan-v4)।
 *
 * দাবি: বসালে শাখার পুরনো সারিই লেখা হয় · প্যাকেজ বদলালে কেবল হাতে না বদলানো শাখা নড়ে · অন্য কোম্পানির শাখায় বসে না · চাবি ছাড়া ৪০৩ ·
 *   "কেন" ঠিক আটকানো ধাপটা বলে
 */
final class AModulePackageFollowsUntilAHandChangesItTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    /** @var list<Branch> */
    private array $branches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->branches = Branch::query()->where('is_active', true)->orderBy('id')->limit(2)->get()->all();
    }

    public function test_a_package_writes_the_branch_rows_and_follows_only_branches_left_alone(): void
    {
        [$a, $b] = $this->branches;
        $this->post(route('system_admin.module.packages.store'), ['name_en' => 'Shop', 'modules' => ['sales', 'customer', 'inventory']])->assertRedirect();
        $package = ModulePackage::query()->sole();

        $html = (string) $this->post(route('system_admin.module.packages.assign', $package), ['branches' => [$a->id, $b->id]])->assertOk()->getContent();
        $this->assertSame(0, BranchModule::query()->count(), '⛔ দেখাতেই লেখা হয়ে গেছে।');
        preg_match('/name="stamp" value="([^"]+)"/', $html, $m);
        $this->post(route('system_admin.module.packages.assign', $package), ['branches' => [$a->id, $b->id], 'stamp' => $m[1]])->assertRedirect();
        $off = app(ModuleBoard::class)->branchOff([$a->id, $b->id]);
        $this->assertContains('hr', $off[$a->id]);
        $this->assertNotContains('sales', $off[$a->id]);
        $this->assertNotContains('accounts', $off[$a->id], '⛔ তালাবন্ধ মডিউল প্যাকেজে বন্ধ হলো।');

        // ⓘ ক হাতে বদলালেন — এখন থেকে নিজের মতো
        $plan = app(ModuleBoard::class)->plan([$a->id => 'a'], $off, ['hr' => true]);
        app(ModuleBoard::class)->writeBranches($plan['changes'], $this->owner->id);
        $this->assertTrue((bool) BranchModulePackage::query()->where('branch_id', $a->id)->sole()->is_custom);
        $this->assertFalse((bool) BranchModulePackage::query()->where('branch_id', $b->id)->sole()->is_custom);

        $this->put(route('system_admin.module.packages.update', $package), ['name_en' => 'Shop', 'modules' => ['sales', 'customer']])->assertRedirect();
        $after = app(ModuleBoard::class)->branchOff([$a->id, $b->id]);
        $this->assertContains('inventory', $after[$b->id], '⛔ প্যাকেজের বদল শাখায় পৌঁছায়নি।');
        $this->assertNotContains('inventory', $after[$a->id], '⛔ হাতে বদলানো শাখা প্যাকেজের সাথে নড়ল।');
    }

    public function test_a_package_is_never_given_to_another_companys_branch(): void
    {
        $package = ModulePackage::query()->create(['name_en' => 'X', 'modules' => ['sales']]);
        $theirs = Branch::acrossAllCompanies()->where('company_id', '!=', CompanyContext::id())->firstOrFail();

        $this->post(route('system_admin.module.packages.assign', $package), ['branches' => [$this->branches[0]->id, $theirs->id]])->assertNotFound();
        $this->assertSame(0, BranchModulePackage::acrossAllCompanies()->count());
        $this->assertSame(0, BranchModule::acrossAllCompanies()->count());
    }

    public function test_the_pages_open_for_the_owner_and_not_without_the_key(): void
    {
        $package = ModulePackage::query()->create(['name_en' => 'X', 'modules' => ['sales']]);
        $doors = [route('system_admin.module.packages'), route('system_admin.module.packages.create'), route('system_admin.module.packages.edit', $package), route('system_admin.module.why')];

        foreach ($doors as $door) {
            $this->get($door)->assertOk();
        }

        $stranger = User::query()->where('email', '!=', 'owner@abos.test')
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))->get()
            ->first(fn (User $u) => ! $u->can('system_admin.settings.manage'));
        $this->assertNotNull($stranger);
        $this->actingAs($stranger);
        foreach ($doors as $door) {
            $this->get($door)->assertForbidden();
        }
    }

    public function test_why_names_the_step_that_stops_it(): void
    {
        $branch = $this->branches[0];
        $ask = fn () => (string) $this->get(route('system_admin.module.why', ['person' => $this->owner->id, 'branch' => $branch->id, 'screen' => 'hr|menu.hr.employee.index']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-why-step="ok"', $ask(), 'পর্দাটাই পাওয়া যায়নি — মাপার কিছু নেই।');
        $this->assertStringNotContainsString('data-why-step="stop"', $ask());

        BranchModule::query()->create(['branch_id' => $branch->id, 'module' => 'hr', 'is_enabled' => false]);
        $this->assertSame(1, substr_count($ask(), 'data-why-step="stop"'), '⛔ কেবল শাখার ধাপটাই আটকানোর কথা।');
    }
}
