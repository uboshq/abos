<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetAcknowledgement;
use App\Modules\Accounts\Models\AssetVerificationLine;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\AssetVerificationService;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * খাতায় ফ্রিজ ছিল, দোকানে আছে কি না কেউ গুনত না — স্থায়ী সম্পদ ধাপ ৪ (মালিক, ১০ অক্টোবর ২০২৬)।
 *
 * ⭐ দাবিগুলো:
 *   · অভিযান শাখার খাতার চালু সম্পদই তোলে — অন্য শাখার বা বিদায় হওয়া নয়; শাখায় একসাথে একটাই।
 *   · ভুল জায়গায় পেলে কোথায় তা লিখতেই হয়; ছবি সংযুক্তিতে বসে আর দেখার চাবিতে খোলে; বন্ধ অভিযান আর বদলায় না।
 *   · গুনতে নিজের চাবি লাগে; শাখায় সীমিত মানুষ অন্য শাখার অভিযান দেখেন না।
 *   · দায়িত্বে থাকা কর্মী নিজের সম্পদ দেখেন (অন্য শাখারটাও) আর স্বীকার করেন; অন্যেরটা পারেন না।
 *   · লেবেল PrintEngine দিয়ে PDF হয়ে বেরোয়।
 */
final class NobodyWalkedTheFloorToCountTheAssetsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $a;

    private Branch $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
    }

    public function test_a_count_lists_only_the_branchs_assets_in_service_and_only_one_runs_at_a_time(): void
    {
        $fridge = $this->asset('Fridge', $this->a, ['location' => 'Shop floor']);
        $this->asset('Desk', $this->a);
        $this->asset('Netrakona bike', $this->b);
        $sold = $this->asset('Old fan', $this->a);
        app(FixedAssetService::class)->dispose($sold, '0', null, now()->toDateString(), FixedAsset::WRITTEN_OFF);

        $count = app(AssetVerificationService::class)->open((int) $this->a->id, 'October count');

        $names = $count->lines()->with('asset')->get()->pluck('asset.name')->sort()->values()->all();
        $this->assertSame(['Desk', 'Fridge'], $names, '⛔ অন্য শাখার বা বিদায় হওয়া সম্পদ তালিকায় উঠল।');
        $this->assertSame('Shop floor', $count->lines()->where('fixed_asset_id', $fridge->id)->value('expected_location'), 'থাকার কথার জায়গা ধরা হয়নি।');

        $this->expectException(ValidationException::class);
        app(AssetVerificationService::class)->open((int) $this->a->id);
    }

    public function test_results_photos_and_the_variance_and_a_closed_count_stays_closed(): void
    {
        $this->asset('Fridge', $this->a);
        $this->asset('Desk', $this->a);
        $this->asset('Chair', $this->a);
        $count = app(AssetVerificationService::class)->open((int) $this->a->id);
        [$fridge, $desk] = $count->lines()->orderBy('id')->get()->all();

        $this->post(route('accounts.asset.verify.mark', $fridge), ['result' => AssetVerificationLine::WRONG_LOCATION])
            ->assertSessionHasErrors('found_location');

        $this->post(route('accounts.asset.verify.mark', $fridge), [
            'result' => AssetVerificationLine::WRONG_LOCATION, 'found_location' => 'Back store',
            'photo' => UploadedFile::fake()->image('fridge.jpg', 40, 40),
        ])->assertSessionHasNoErrors();
        $this->post(route('accounts.asset.verify.mark', $desk), ['result' => AssetVerificationLine::FOUND])->assertSessionHasNoErrors();

        $photo = Attachment::query()->where('source_entity', AssetVerificationLine::ATTACHMENT_ENTITY)->where('source_entity_id', $fridge->id)->first();
        $this->assertNotNull($photo, '⛔ ছবিটা সংযুক্তিতে বসেনি।');
        $this->get(route('attachment.download', $photo))->assertOk();

        $variance = app(AssetVerificationService::class)->variance($count);
        $this->assertSame(1, $variance['counts']['found']);
        $this->assertSame(1, $variance['counts']['wrong_location']);
        $this->assertSame(1, $variance['counts']['unchecked'], '⛔ না-গোনা সম্পদ পার্থক্যে নেই।');
        $this->assertCount(2, $variance['exceptions']);

        $this->post(route('accounts.asset.verify.close', $count))->assertSessionHasNoErrors();
        $this->post(route('accounts.asset.verify.mark', $desk), ['result' => AssetVerificationLine::NOT_FOUND])->assertSessionHasErrors('result');
        $this->assertSame(AssetVerificationLine::FOUND, $desk->fresh()->result, '⛔ বন্ধ অভিযানের ফল বদলে গেল।');
    }

    public function test_counting_needs_its_own_key_and_a_branch_limited_reader_sees_only_their_branch(): void
    {
        $this->asset('Fridge', $this->a);
        $this->asset('Bike', $this->b);
        $mine = app(AssetVerificationService::class)->open((int) $this->a->id);
        $theirs = app(AssetVerificationService::class)->open((int) $this->b->id);

        $clerk = $this->clerk(['accounts.asset.view'], $this->a);

        $this->actingAs($clerk)->get(route('accounts.asset.verify.index'))->assertOk()
            ->assertSee($mine->document_no)->assertDontSee($theirs->document_no);
        $this->get(route('accounts.asset.verify.show', $theirs))->assertNotFound();
        $this->post(route('accounts.asset.verify.mark', $mine->lines()->first()), ['result' => AssetVerificationLine::FOUND])
            ->assertForbidden();
    }

    public function test_a_custodian_sees_their_own_assets_and_acknowledges_only_those(): void
    {
        $user = $this->clerk([], null);
        $keeper = $this->employee('E-01', 'Rahim', $user);
        $other = $this->employee('E-02', 'Karim', null);

        $fridge = $this->asset('Fridge', $this->a, ['custodian_id' => $keeper->id]);
        $bike = $this->asset('Bike', $this->b, ['custodian_id' => $keeper->id]);
        $desk = $this->asset('Desk', $this->a, ['custodian_id' => $other->id]);

        $this->actingAs($user)->get(route('accounts.asset.mine'))->assertOk()
            ->assertSee('Fridge')->assertSee('Bike')->assertDontSee('Desk');

        $this->post(route('accounts.asset.acknowledge', $fridge->id), ['condition' => AssetAcknowledgement::GOOD])->assertSessionHasNoErrors();
        $this->assertSame((int) $keeper->id, (int) AssetAcknowledgement::query()->where('fixed_asset_id', $fridge->id)->value('employee_id'));
        $this->assertNotNull($fridge->fresh()->load('acknowledgements')->acknowledgedByCustodian());

        $this->post(route('accounts.asset.acknowledge', $desk->id), ['condition' => AssetAcknowledgement::GOOD])->assertForbidden();
        $this->assertFalse(AssetAcknowledgement::query()->where('fixed_asset_id', $desk->id)->exists(), '⛔ অন্যের জিনিস স্বীকার করা গেল।');

        // ⓘ কর্মী নন এমন মানুষের জন্য পাতাটা নয়
        $this->actingAs($this->clerk([], null))->get(route('accounts.asset.mine'))->assertForbidden();
        $this->assertTrue($bike->exists);
    }

    public function test_labels_print_through_the_print_engine_and_the_screens_open(): void
    {
        $fridge = $this->asset('Fridge', $this->a, ['tag_no' => 'TAG-0042']);
        $count = app(AssetVerificationService::class)->open((int) $this->a->id);

        $pdf = $this->get(route('accounts.asset.labels', ['assets' => [$fridge->id]]))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $this->get(route('accounts.asset.verify.index'))->assertOk()->assertSee($count->document_no);
        $this->get(route('accounts.asset.verify.show', $count))->assertOk()->assertSee('Fridge');
        $this->get(route('accounts.asset.show', $fridge))->assertOk()->assertSee(__('accounts::asset.labels_action'));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $over */
    private function asset(string $name, Branch $branch, array $over = []): FixedAsset
    {
        return app(FixedAssetService::class)->register([
            'asset_account_id' => Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)->id,
            'name' => $name, 'acquired_on' => '2026-07-01', 'cost' => '10000', 'salvage' => '0',
            'method' => FixedAsset::STRAIGHT_LINE, 'life_months' => 60, 'funded_by' => FixedAssetService::FUNDED_ALREADY,
            'branch_id' => $branch->id, ...$over,
        ]);
    }

    /** @param  list<string>  $keys */
    private function clerk(array $keys, ?Branch $only): User
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => null, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($clerk, $keys) {
            foreach ($keys as $key) {
                $clerk->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });

        if ($only !== null) {
            UserDataScope::query()->withoutGlobalScopes()->create([
                'company_id' => $this->company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $only->id,
            ]);
            app(DataScope::class)->forget();
        }

        return $clerk->fresh();
    }

    private function employee(string $code, string $name, ?User $user): Employee
    {
        return Employee::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->a->id, 'code' => $code, 'name_en' => $name,
            'joining_date' => '2024-03-01', 'payment_method' => 'cash', 'is_active' => true, 'user_id' => $user?->id,
        ]);
    }
}
