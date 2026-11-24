<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\Company;
use App\Models\ModulePackage;
use App\Models\User;
use App\Modules\SystemAdmin\Services\BranchDesk;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ শাখার ধরন — দোকান, ডিপো, গুদাম, অফিস (মালিক, ২৪ নভেম্বর ২০২৬)।
 *
 * দাবি: পুরোনো শাখা খালিই থাকে · ফর্মে বসে, মোছে, অচেনা মান ফেরে · না পাঠালে আগের মান থাকে · বদলটা অডিটে · তালিকা আর শাখার মডিউল
 *   একসাথে কয়েক ধরনে ছাঁকে, "দেওয়া নেই"ও · ধরন ধরে প্যাকেজ: আগে তালিকা, কেবল এই কোম্পানির সচল শাখা
 */
final class ABranchSaysWhatKindItIsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    /** @var list<Branch> */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($owner->fresh());

        foreach (['shop', 'depot', 'office', null] as $i => $type) {
            $this->b[] = Branch::query()->create(['code' => 'TYP'.$i, 'name_en' => 'Typed '.$i, 'type' => $type, 'is_active' => true]);
        }
    }

    public function test_the_seeded_branches_stay_without_a_type_and_the_words_exist(): void
    {
        $this->assertSame(0, Branch::query()->where('code', 'not like', 'TYP%')->whereNotNull('type')->count());
        $this->assertSame('in:'.implode(',', Branch::TYPES), BranchDesk::RULES['type'][2], '⛔ ফর্মের তালিকা আর মডেলের তালিকা আলাদা।');

        foreach (['bn', 'en'] as $locale) {
            foreach ([...Branch::TYPES, 'none', 'label'] as $key) {
                $this->assertNotSame('core.branch_type.'.$key, __('core.branch_type.'.$key, [], $locale));
            }
        }
    }

    public function test_the_form_sets_clears_and_refuses_and_the_change_is_audited(): void
    {
        $branch = $this->b[3];
        $send = fn (array $more) => $this->put(route('system_admin.branch.update', $branch->id), ['code' => $branch->code, 'name_en' => $branch->name_en, ...$more]);

        $this->get(route('system_admin.branch.edit', $branch->id))->assertOk()->assertSee('name="type"', false)->assertSee(__('core.branch_type.warehouse'));
        $send(['type' => 'warehouse'])->assertSessionHasNoErrors();
        $this->assertSame('warehouse', $branch->fresh()->type);
        $this->assertTrue(AuditTrail::query()->where('auditable_type', Branch::class)->where('auditable_id', $branch->id)
            ->whereHas('changes', fn ($q) => $q->where('field', 'type')->where('new_value', 'warehouse'))->exists(), '⛔ ধরনের বদল অডিটে নেই।');

        $send([])->assertSessionHasNoErrors();
        $this->assertSame('warehouse', $branch->fresh()->type, '⛔ ঘর না পাঠালে আগের মান হারাল।');
        $send(['type' => 'palace'])->assertSessionHasErrors('type');
        $send(['type' => ''])->assertSessionHasNoErrors();
        $this->assertNull($branch->fresh()->type);
    }

    public function test_the_branch_list_shows_and_filters_several_types_at_once(): void
    {
        $seen = function (array $types): array {
            $html = (string) $this->get(route('system_admin.branch.index', ['type' => $types, 'q' => 'Typed']))->assertOk()->getContent();

            return array_values(array_filter(['Typed 0', 'Typed 1', 'Typed 2', 'Typed 3'], fn (string $n) => str_contains($html, $n)));
        };

        $this->assertSame(['Typed 0', 'Typed 1'], $seen(['shop', 'depot']));
        $this->assertSame(['Typed 2', 'Typed 3'], $seen(['office', 'none']));
        $this->assertSame(['Typed 0', 'Typed 1', 'Typed 2', 'Typed 3'], $seen(['palace']), 'ⓘ অচেনা ধরন ছাঁকনি নয়');
    }

    public function test_the_module_page_filters_by_type_and_pick_all_takes_only_those(): void
    {
        $html = (string) $this->get(route('system_admin.module.branches', ['type' => ['shop', 'depot'], 'pick' => 'all']))->assertOk()->getContent();
        preg_match('/<form id="board-form".*?<\/form>/s', $html, $form);
        preg_match_all('/name="pick\[\]" value="(\d+)"/', $form[0] ?? '', $m);

        $this->assertEqualsCanonicalizing([$this->b[0]->id, $this->b[1]->id], array_map('intval', $m[1]));
    }

    public function test_a_type_filter_never_opens_another_companys_branch(): void
    {
        $theirs = Branch::acrossAllCompanies()->where('company_id', '!=', $this->company->id)->firstOrFail();
        $theirs->forceFill(['type' => 'shop'])->saveQuietly();

        $ids = app(\App\Modules\SystemAdmin\Support\ModuleBoard::class)->branches('', '', ['shop', 'none'])->pluck('id')->all();
        $this->assertNotContains($theirs->id, $ids);
        $this->assertContains($this->b[0]->id, $ids);
    }

    public function test_a_package_is_given_by_type_after_the_list(): void
    {
        $off = Branch::query()->create(['code' => 'TYPOFF', 'name_en' => 'Closed shop', 'type' => 'shop', 'is_active' => false]);
        $package = ModulePackage::query()->create(['name_en' => 'Shop', 'modules' => ['sales']]);
        $door = route('system_admin.module.packages.assign', $package);

        $html = (string) $this->post($door, ['types' => ['shop']])->assertOk()->assertSee('Typed 0')->assertDontSee('Typed 1')->assertDontSee('Closed shop')->getContent();
        $this->assertSame(0, BranchModule::query()->count(), '⛔ দেখাতেই লেখা হয়ে গেছে।');

        preg_match('/name="stamp" value="([^"]+)"/', $html, $m);
        $this->post($door, ['branches' => [$this->b[0]->id], 'stamp' => $m[1]])->assertRedirect();
        $this->assertSame([$this->b[0]->id], BranchModule::query()->distinct()->pluck('branch_id')->all());
        $this->assertFalse(BranchModule::query()->where('branch_id', $off->id)->exists());

        $this->post($door, ['types' => ['palace']])->assertSessionHasErrors('types.0');
        $this->post($door, [])->assertSessionHasErrors('branches');
    }
}
