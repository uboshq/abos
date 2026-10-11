<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Imports\FixedAssetOpeningImporter;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetCategory;
use App\Modules\Accounts\Models\AssetEvent;
use App\Modules\Accounts\Models\AssetTransfer;
use App\Modules\Accounts\Models\AssetVerification;
use App\Modules\Accounts\Models\DepreciationEntry;
use App\Modules\Accounts\Models\DepreciationRun;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\AssetCategoryService;
use App\Modules\Accounts\Services\AssetEventService;
use App\Modules\Accounts\Services\AssetVerificationService;
use App\Modules\Accounts\Services\DepreciationEngine;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * স্থায়ী সম্পদের কাগজ — দুই শাখা বা দুই মানুষ এক জায়গায় এলে যা ফসকাত (cloud/fixed-assets রিভিউ, ১১ অক্টোবর ২০২৬)।
 *
 * ⭐ দাবিগুলো:
 *   · অন্য শাখা দেখানো মালিকের শেষ সইয়েও সম্পদ চালু হয়, আর ফেরত সইয়ে ঘটনা বাতিল — সম্পদ আটকে থাকে না।
 *   · কেনার দাখিলা সম্পদের শাখায়, হেডারের শাখায় নয়; বিলের সারির সম্পদ বিলের শাখার।
 *   · বিক্রি হয়ে যাওয়া সম্পদের পরেও বিলের সারির পরিমাণ আবার খোলে না।
 *   · পুরনো পর্দা থেকে দ্বিতীয় স্থানান্তর বা দ্বিতীয় বিদায় থামে; বিদায় ভবিষ্যতে বা বসে যাওয়া অবচয়ের আগে নয়।
 *   · পরের মাস বসে গেলে বাদ পড়া মাস আর বসে না।
 *   · নাগালের বাইরের শাখায় সম্পদ বা গণনা নয়; ট্যাগ আর আমদানির অঙ্ক কারণসহ ফেরে।
 *   · বন্ধ গণনায় ফল বসে না; মাসের দৌড়ের ক্রম অর্থ মডিউলের আমানতের সাথে ভাগ হয় না।
 */
final class AnAssetPaperSlippedWhereTwoBranchesOrTwoPeopleMetTest extends TestCase
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

    public function test_the_last_signature_from_another_branch_puts_the_asset_in_service_in_its_own_branch(): void
    {
        $this->flow(AccountsSignature::FIXED_ASSET_REGISTER);

        $asset = $this->bought('Generator', $this->b);
        $this->assertTrue($asset->isAwaiting(), 'দৃশ্যটাই বানানো যায়নি — সই চাওয়া হয়নি');

        $this->showBranch($this->a);
        $this->assertNull(FixedAsset::query()->find($asset->id), 'দৃশ্যটাই বানানো যায়নি — হেডারের শাখায় সম্পদটা দেখা যাচ্ছে');

        app(ApprovalEngine::class)->approve(app(ApprovalEngine::class)->latestFor($asset, AccountsSignature::FIXED_ASSET_REGISTER), $this->owner);

        $this->assertSame(FixedAsset::ACTIVE, FixedAsset::acrossBranches()->find($asset->id)->status,
            '⛔ অন্য শাখা দেখানো অবস্থায় শেষ সই পড়ল, তবু সম্পদ চিরকাল অপেক্ষায়');
        $this->assertSame([(int) $this->b->id], $this->branchesOf(FixedAsset::drillSourceType(), (int) $asset->id),
            '⛔ কেনার দাখিলা সম্পদের শাখায় নয়');
    }

    public function test_a_refused_signature_cancels_the_event_and_drops_the_waiting_registration(): void
    {
        $van = $this->bought('Van', $this->a);
        $this->flow(AccountsSignature::FIXED_ASSET_ADDITION);

        $event = app(AssetEventService::class)->addition($van, $this->addition());
        $this->assertTrue($event->isAwaiting(), 'দৃশ্যটাই বানানো যায়নি');
        app(ApprovalEngine::class)->reject(app(ApprovalEngine::class)->latestFor($event, AccountsSignature::FIXED_ASSET_ADDITION), $this->owner, 'Not now');

        $this->assertSame(AssetEvent::CANCELLED, $event->fresh()->status, '⛔ ফেরত সইয়ের পরেও ঘটনা চিরকাল অপেক্ষায়');
        $this->assertTrue(app(AssetEventService::class)->addition($van->fresh(), $this->addition())->isAwaiting(),
            '⛔ ফেরত ঘটনার পরে সম্পদ আর নতুন ঘটনা নেয় না');

        $this->flow(AccountsSignature::FIXED_ASSET_REGISTER);
        $waiting = $this->bought('Printer', $this->a);
        app(ApprovalEngine::class)->reject(app(ApprovalEngine::class)->latestFor($waiting, AccountsSignature::FIXED_ASSET_REGISTER), $this->owner, 'No');
        $this->assertNull(FixedAsset::acrossBranches()->find($waiting->id), '⛔ ফেরত নিবন্ধন চিরকাল অপেক্ষায়');
    }

    public function test_the_purchase_and_the_opening_wear_land_in_the_assets_branch_not_the_headers(): void
    {
        $this->showBranch($this->a);

        $bought = $this->bought('Freezer', $this->b);
        $opened = app(FixedAssetService::class)->register([
            'category_id' => $this->category()->id, 'name' => 'Old truck', 'acquired_on' => '2026-07-01', 'cost' => '300000',
            'funded_by' => FixedAssetService::FUNDED_OPENING, 'opening_accumulated' => '50000', 'branch_id' => $this->b->id,
        ]);

        $this->assertSame([(int) $this->b->id], $this->branchesOf(FixedAsset::drillSourceType(), (int) $bought->id),
            '⛔ কেনা হেডারের শাখায় বসল — সম্পদ আরেক শাখায়');
        $this->assertSame([(int) $this->b->id], $this->branchesOf(FixedAsset::drillSourceType(), (int) $opened->id),
            '⛔ খোলা জের হেডারের শাখায় বসল');
        $openingWear = DepreciationEntry::query()->where('fixed_asset_id', $opened->id)->sole();
        $this->assertSame([(int) $this->b->id], $this->branchesOf(DepreciationEntry::drillSourceType(), (int) $openingWear->id),
            '⛔ আগের বছরগুলোর ক্ষয় হেডারের শাখায় বসল');
    }

    public function test_a_bill_line_stays_taken_after_its_asset_leaves_and_belongs_to_the_bills_branch(): void
    {
        $line = $this->postedBill()->lines()->firstOrFail();
        $fridges = $this->fromBill($line->id, '10');

        app(FixedAssetService::class)->dispose($fridges, '0', null, now()->toDateString());

        try {
            $this->fromBill($line->id, '1');
            $this->fail('⛔ বিক্রি হওয়া সম্পদের পরে একই বিলের সারি আবার খুলল — মজুদ দুইবার কমত');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('capitalised_qty', $e->errors());
        }

        $other = $this->postedBill()->lines()->firstOrFail();
        $billBranch = (int) PurchaseBill::query()->withoutGlobalScopes()->whereKey($other->purchase_bill_id)->value('branch_id');
        $elsewhere = (int) ($billBranch === (int) $this->a->id ? $this->b->id : $this->a->id);

        try {
            $this->fromBill($other->id, '1', $elsewhere);
            $this->fail('⛔ বিলের মাল এক শাখার মজুদ থেকে, সম্পদ আরেক শাখায় বসল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('branch_id', $e->errors());
        }
    }

    public function test_a_stale_screen_cannot_move_or_sell_the_same_asset_twice(): void
    {
        $asset = $this->bought('Forklift', $this->a);
        $stale = $asset->fresh();

        app(FixedAssetService::class)->transfer($stale, (int) $this->b->id, now()->toDateString());
        $this->assertRefused(fn () => app(FixedAssetService::class)->transfer($stale, (int) $this->b->id, now()->toDateString()),
            '⛔ পুরনো পর্দা থেকে একই স্থানান্তর দুইবার — দুই শাখায় দুইবার কেনা দাম');
        $this->assertSame(1, AssetTransfer::query()->where('asset_id', $asset->id)->count());

        $moved = FixedAsset::acrossBranches()->findOrFail($asset->id);
        app(FixedAssetService::class)->dispose($moved, '0', null, now()->toDateString());
        $this->assertRefused(fn () => app(FixedAssetService::class)->dispose($moved, '0', null, now()->toDateString()),
            '⛔ পুরনো পর্দা থেকে একই সম্পদ দুইবার বিদায় — কেনা দাম দুইবার বেরোল');
        $this->assertSame(1, LedgerEntry::query()->where('source_type', FixedAsset::disposalSourceType())->where('source_id', $asset->id)
            ->where('account_id', $asset->asset_account_id)->count());

        $this->assertRefused(fn () => app(AssetEventService::class)->addition($moved, $this->addition()),
            '⛔ পুরনো পর্দা থেকে বিদায় নেওয়া সম্পদে সংযোজন বসল');
    }

    public function test_a_disposal_is_not_dated_in_the_future_or_before_posted_depreciation(): void
    {
        $van = $this->bought('Pickup', $this->a, '2026-07-01');
        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $month) {
            app(FixedAssetService::class)->depreciate($van->fresh(), $month);
        }

        $this->assertRefused(fn () => app(FixedAssetService::class)->dispose($van->fresh(), '0', null, '2026-08-15'),
            '⛔ সেপ্টেম্বরের অবচয় বসার পরে আগস্টের বিদায় — বসে যাওয়া ক্ষয়ও মুছত', 'disposed_on');
        $this->assertRefused(fn () => app(FixedAssetService::class)->dispose($van->fresh(), '0', null, now()->addDay()->toDateString()),
            '⛔ ভবিষ্যতের দিনে বিদায়', 'disposed_on');

        app(FixedAssetService::class)->dispose($van->fresh(), '0', null, '2026-09-20');
        $this->assertSame(FixedAsset::DISPOSED, $van->fresh()->status, 'শেষ অবচয়ের মাসেই বিদায় চলে');
    }

    public function test_a_skipped_month_is_not_posted_once_a_later_month_is(): void
    {
        $asset = app(FixedAssetService::class)->register([
            'category_id' => $this->category()->id, 'name' => 'Laptop', 'acquired_on' => '2026-07-01', 'cost' => '12000', 'salvage' => '0',
            'method' => FixedAsset::STRAIGHT_LINE, 'life_months' => 12, 'funded_by' => FixedAssetService::FUNDED_ALREADY, 'branch_id' => $this->a->id,
        ]);

        // ⓘ জুলাই বসে, আগস্ট বাদ, সেপ্টেম্বর বসে (অর্থবছর জুলাই থেকে)
        foreach (['2026-07', '2026-09'] as $month) {
            app(FixedAssetService::class)->depreciate($asset->fresh(), $month.'-01');
        }

        $august = app(DepreciationEngine::class)->amountFor($asset->fresh(), '2026-08-01');
        $this->assertSame('later_posted', $august['reason'], '⛔ সেপ্টেম্বর বসার পরে বাদ পড়া আগস্ট বসতে চাইল — দামের বেশি ক্ষয়');
        $this->assertSame(0, bccomp($august['amount'], '0', 4));
    }

    public function test_another_branch_is_out_of_reach_for_a_new_asset_and_a_count(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo([
            Permission::findOrCreate('accounts.asset.view', 'web'), Permission::findOrCreate('accounts.asset.manage', 'web'),
            Permission::findOrCreate('accounts.asset.verify', 'web'),
        ]));
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->a->id,
        ]);
        app(DataScope::class)->forget();
        $category = $this->category();
        $this->bought('Their desk', $this->b);

        $this->actingAs($clerk->fresh());
        $this->post(route('accounts.asset.store'), [
            'category_id' => $category->id, 'name' => 'Planted', 'acquired_on' => now()->toDateString(), 'cost' => '90000',
            'funded_by' => FixedAssetService::FUNDED_ALREADY, 'branch_id' => $this->b->id,
        ])->assertSessionHasErrors('branch_id');
        $this->assertFalse(FixedAsset::acrossBranches()->where('name', 'Planted')->exists(), '⛔ নাগালের বাইরের শাখায় সম্পদ বসল');

        $this->post(route('accounts.asset.verify.store'), ['branch_id' => $this->b->id, 'started_on' => now()->toDateString()])
            ->assertSessionHasErrors('branch_id');
        $this->assertSame(0, AssetVerification::query()->withoutGlobalScopes()->count(), '⛔ নাগালের বাইরের শাখার গণনা খুলল');

        // ⓘ নিজের শাখায় — আগের মতোই
        $this->post(route('accounts.asset.store'), [
            'category_id' => $category->id, 'name' => 'Mine', 'acquired_on' => now()->toDateString(), 'cost' => '90000',
            'funded_by' => FixedAssetService::FUNDED_ALREADY, 'branch_id' => $this->a->id,
        ])->assertSessionHasNoErrors();
    }

    public function test_a_bengali_tag_and_one_e_five_are_refused_with_a_reason(): void
    {
        $this->post(route('accounts.asset.store'), [
            'category_id' => $this->category()->id, 'name' => 'Tagged', 'acquired_on' => now()->toDateString(), 'cost' => '90000',
            'funded_by' => FixedAssetService::FUNDED_ALREADY, 'tag_no' => 'ট্যাগ-১',
        ])->assertSessionHasErrors('tag_no');

        $row = ['category_code' => 'VEH', 'name' => 'Imported van', 'tag_no' => '', 'acquired_on' => '2026-07-01', 'cost' => '1e5',
            'accumulated' => '', 'salvage' => '', 'location' => '', 'serial_no' => ''];
        $importer = app(FixedAssetOpeningImporter::class);
        $this->category();

        $this->assertContains(__('core.import.not_a_number', ['column' => 'cost']), $importer->check($row), '⛔ আমদানিতে "1e5" দাম হিসেবে পার হল');
        $this->assertContains(__('accounts::asset.tag_ascii'), $importer->check(['cost' => '100000', 'tag_no' => 'ট্যাগ'] + $row),
            '⛔ আমদানিতে বাংলা ট্যাগ পার হল — লেবেলের পাতা ৫০০ দিত');
        $this->assertNotContains(__('core.import.not_a_number', ['column' => 'cost']), $importer->check(['cost' => '100000.50'] + $row));
    }

    public function test_a_count_result_cannot_land_after_the_count_is_closed(): void
    {
        $this->bought('Shelf', $this->a);
        $count = app(AssetVerificationService::class)->open((int) $this->a->id, 'October', now()->toDateString());
        $line = $count->lines()->firstOrFail();
        $line->load('verification');

        app(AssetVerificationService::class)->close($count->fresh());

        $this->assertRefused(fn () => app(AssetVerificationService::class)->mark($line, 'found'),
            '⛔ বন্ধ গণনায় ফল বসল — জমানো হিসাব বদলাল', 'result');
    }

    public function test_the_months_run_has_its_own_number_series(): void
    {
        $this->bought('Cooler', $this->a, '2026-07-01');

        app(DepreciationEngine::class)->run('2026-07-01');

        $run = DepreciationRun::acrossBranches()->firstOrFail();
        $this->assertSame(DepreciationRun::SERIES, 'FAD');
        $this->assertStringStartsWith('FAD', (string) $run->document_no, '⛔ মাসের দৌড় আমানতের DEP ক্রম ভাগ করল');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function bought(string $name, Branch $branch, ?string $on = null): FixedAsset
    {
        return app(FixedAssetService::class)->register([
            'category_id' => $this->category()->id, 'name' => $name, 'acquired_on' => $on ?? now()->toDateString(), 'cost' => '60000',
            'funded_by' => FixedAssetService::FUNDED_MONEY, 'funding_account_id' => $this->cash(), 'branch_id' => $branch->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function addition(): array
    {
        return ['amount' => '5000', 'happened_on' => now()->toDateString(), 'funded_by' => FixedAssetService::FUNDED_MONEY, 'funding_account_id' => $this->cash()];
    }

    private function fromBill(int $lineId, string $qty, ?int $branchId = null): FixedAsset
    {
        return app(FixedAssetService::class)->register(array_filter([
            'category_id' => $this->category()->id, 'name' => 'Fridge', 'acquired_on' => now()->toDateString(),
            'funded_by' => FixedAssetService::FUNDED_BILL, 'purchase_bill_line_id' => $lineId, 'capitalised_qty' => $qty, 'branch_id' => $branchId,
        ], fn ($v) => $v !== null));
    }

    private function postedBill(): PurchaseBill
    {
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $bill = app(PurchaseBillService::class)->create(
            ['supplier_id' => Supplier::query()->firstOrFail()->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => '10', 'rate' => '20000']],
        );

        return app(PurchaseBillService::class)->confirm($bill)->fresh();
    }

    private function flow(string $action): void
    {
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => AccountsSignature::MODULE, 'action' => $action, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);
        $this->app->forgetInstance(ApprovalEngine::class);
    }

    private function showBranch(Branch $branch): void
    {
        $this->post(route('branch.switch'), ['branch_id' => (string) $branch->id])->assertRedirect();
        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, (int) $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    /** @return list<int> */
    private function branchesOf(string $sourceType, int $sourceId): array
    {
        $branches = LedgerEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->pluck('branch_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $this->assertNotSame([], $branches, 'দৃশ্যটাই বানানো যায়নি — খাতায় কিছু বসেনি');

        return $branches;
    }

    private function category(): AssetCategory
    {
        return AssetCategory::query()->where('code', 'VEH')->first() ?? app(AssetCategoryService::class)->create([
            'code' => 'VEH', 'name_en' => 'Vehicles', 'name_bn' => 'যানবাহন',
            'asset_account_id' => (int) Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)->id,
            'gain_account_id' => StandardChart::find(StandardChart::ASSET_DISPOSAL_GAIN)->id,
            'loss_account_id' => StandardChart::find(StandardChart::ASSET_DISPOSAL_LOSS)->id,
            'impairment_account_id' => null,
            'method' => FixedAsset::STRAIGHT_LINE, 'life_months' => 60, 'residual_percent' => '0',
        ]);
    }

    private function cash(): int
    {
        return (int) Account::query()->money()->postable()->orderBy('code')->value('id');
    }

    private function assertRefused(callable $what, string $why, ?string $field = null): void
    {
        try {
            $what();
        } catch (ValidationException $e) {
            if ($field !== null) {
                $this->assertArrayHasKey($field, $e->errors(), $why.' — অন্য ঘরে আটকাল: '.implode(', ', array_keys($e->errors())));
            }

            return;
        }

        $this->fail($why);
    }
}
