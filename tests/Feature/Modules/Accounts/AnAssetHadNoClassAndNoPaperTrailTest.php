<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\DataScope;
use App\Core\Services\ImportRunner;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetCategory;
use App\Modules\Accounts\Models\AssetCostPart;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\AssetCategoryService;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * স্থায়ী সম্পদের শ্রেণি ছিল না, নিবন্ধনে জায়গা-কর্মী-বিল কিছুই ছিল না — স্থায়ী সম্পদ ধাপ ১ (মালিক, ১০ অক্টোবর ২০২৬; IAS 16)।
 *
 * ⭐ দাবিগুলো:
 *   · শ্রেণি ভুল ধরনের খাত নেয় না; নতুন সম্পদে শ্রেণির খাত, আয়ু আর শেষ দামের হার বসে।
 *   · মূলধনীকরণের সীমার নিচের জিনিস সম্পদ হয় না।
 *   · পাকা ক্রয় বিলের সারি সম্পদে তুললে মাল মজুদ থেকে সম্পদে সরে — বিক্রেতার পাওনা নড়ে না (কেনা দুইবার নয়)।
 *   · দামের ভাগের যোগফলই দাম; অংশ তার মূল সম্পদে ঝোলে।
 *   · আগে থেকে থাকা সম্পদের তালিকা তোলা নিজের চাবি আর নিজের সই ছাড়া হয় না।
 *   · শাখায় সীমিত মানুষ কেবল নিজের শাখার সম্পদ দেখেন।
 */
final class AnAssetHadNoClassAndNoPaperTrailTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_a_category_refuses_a_wrong_account_and_fills_a_new_asset(): void
    {
        try {
            app(AssetCategoryService::class)->create([...$this->categoryData(), 'expense_account_id' => $this->id('1202')]);
            $this->fail('⛔ সম্পদের খাত অবচয় খরচের ঘরে বসে গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expense_account_id', $e->errors());
        }

        $category = $this->category();
        $asset = app(FixedAssetService::class)->register([
            'category_id' => $category->id, 'name' => 'Delivery Van', 'acquired_on' => now()->toDateString(),
            'cost' => '100000', 'funded_by' => FixedAssetService::FUNDED_ALREADY,
        ]);

        $this->assertSame($category->id, (int) $asset->category_id);
        $this->assertSame($this->id('1202'), (int) $asset->asset_account_id, '⛔ শ্রেণির দামের খাত বসেনি।');
        $this->assertSame((int) StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id, (int) $asset->accumulated_account_id);
        $this->assertSame(60, (int) $asset->life_months, '⛔ শ্রেণির আয়ু বসেনি।');
        $this->assertSame(0, bccomp((string) $asset->salvage, '10000', 4), '⛔ শেষ দাম শ্রেণির হারে (১০%) বসেনি।');
        $this->assertSame($asset->document_no, $asset->tag_no, 'ট্যাগ খালি দিলে কাগজের নম্বরই ট্যাগ।');
    }

    public function test_an_item_below_the_threshold_is_refused_as_an_asset(): void
    {
        app(SettingsService::class)->set(FixedAssetService::THRESHOLD, '5000');

        try {
            app(FixedAssetService::class)->register([
                'category_id' => $this->category()->id, 'name' => 'Stapler', 'acquired_on' => now()->toDateString(),
                'cost' => '450', 'funded_by' => FixedAssetService::FUNDED_ALREADY,
            ]);
            $this->fail('⛔ সীমার নিচের জিনিস সম্পদের খাতায় উঠল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cost', $e->errors());
        }

        $this->assertSame(0, FixedAsset::query()->where('name', 'Stapler')->count());
    }

    public function test_capitalising_a_bill_line_moves_stock_and_does_not_buy_it_twice(): void
    {
        [$bill, $product, $warehouse] = $this->postedBill();
        $line = $bill->lines()->firstOrFail();
        $payable = $this->balance(StandardChart::PAYABLE);
        $inventory = $this->balance(StandardChart::INVENTORY);
        $stock = app(StockService::class)->floorQty($product, $warehouse);

        $asset = app(FixedAssetService::class)->register([
            'category_id' => $this->category()->id, 'name' => 'Office fridge', 'acquired_on' => now()->toDateString(),
            'funded_by' => FixedAssetService::FUNDED_BILL, 'purchase_bill_line_id' => $line->id, 'capitalised_qty' => '2',
        ]);

        $this->assertTrue($asset->isActive());
        $this->assertSame(0, bccomp($payable, $this->balance(StandardChart::PAYABLE), 4), '⛔ বিক্রেতার পাওনা আবার বসল — কেনা দুইবার।');
        $this->assertSame(0, bccomp(bcsub($inventory, $this->balance(StandardChart::INVENTORY), 4), (string) $asset->cost, 4),
            '⛔ মজুদ থেকে ঠিক সম্পদের দামটা বেরোয়নি।');
        // ⓘ ঐ বিলের নিজের দাম (১০০ × ২) — তাকের পুরনো দামি মালের দরে নয় (IAS 16: দাম মানে নিজের কেনা দাম)
        $this->assertSame(0, bccomp((string) $asset->cost, '200', 4), '⛔ সম্পদ বিলের দামে নয়, তাকের পুরনো দরে বসল — '.$asset->cost);
        $this->assertSame(0, bccomp($this->balanceOf((int) $asset->asset_account_id), '200', 4), '⛔ সম্পদের খাতে দাম ঢোকেনি।');
        $this->assertSame(0, bccomp(bcsub($stock, app(StockService::class)->floorQty($product, $warehouse), 4), '2', 4), '⛔ মজুদের পরিমাণ কমেনি।');
        $this->assertSame((int) $bill->id, (int) $asset->purchase_bill_id);

        // ⓘ একই সারি থেকে বাকি ৮-এর বেশি নয়
        $this->expectException(ValidationException::class);
        app(FixedAssetService::class)->register([
            'category_id' => $this->category()->id, 'name' => 'Too many fridges', 'acquired_on' => now()->toDateString(),
            'funded_by' => FixedAssetService::FUNDED_BILL, 'purchase_bill_line_id' => $line->id, 'capitalised_qty' => '9',
        ]);
    }

    public function test_cost_parts_add_up_and_a_component_hangs_on_its_parent(): void
    {
        $category = $this->category();
        $machine = app(FixedAssetService::class)->register([
            'category_id' => $category->id, 'name' => 'Packing machine', 'acquired_on' => now()->toDateString(),
            'cost' => '0', 'funded_by' => FixedAssetService::FUNDED_ALREADY,
            'cost_parts' => [
                ['kind' => AssetCostPart::PURCHASE, 'amount' => '80000'],
                ['kind' => AssetCostPart::FREIGHT, 'amount' => '3000'],
                ['kind' => AssetCostPart::INSTALLATION, 'amount' => '2000'],
            ],
        ]);

        $this->assertSame(0, bccomp((string) $machine->cost, '85000', 4), '⛔ দামের ভাগের যোগফল দাম হয়নি।');
        $this->assertCount(3, $machine->costParts);

        $motor = app(FixedAssetService::class)->register([
            'category_id' => $category->id, 'name' => 'Motor', 'acquired_on' => now()->toDateString(), 'cost' => '15000',
            'funded_by' => FixedAssetService::FUNDED_ALREADY, 'parent_id' => $machine->id,
        ]);

        $this->assertSame([$motor->id], $machine->fresh()->components->pluck('id')->all());

        // ⓘ পাতা খোলে — পরিচয়, ভাগ আর অংশসহ
        $this->get(route('accounts.asset.show', $machine))->assertOk()
            ->assertSee(__('accounts::asset.part_freight'))->assertSee('Motor');
    }

    public function test_the_opening_import_needs_its_own_key_and_its_own_signature(): void
    {
        $category = $this->category();
        $csv = "category_code,name,acquired_on,cost,accumulated\n{$category->code},Old generator,2024-01-15,50000,20000\n";

        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('accounts.asset.manage', 'web')));
        $this->actingAs($clerk->fresh());

        $this->assertArrayNotHasKey('fixed_asset_opening', app(ImportRunner::class)->available(), '⛔ রোজকার সম্পদের চাবিতেই পুরনো তালিকা তোলা যায়।');

        $this->actingAs($this->owner);
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => AccountsSignature::MODULE,
            'action' => AccountsSignature::FIXED_ASSET_OPENING, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);

        $result = app(ImportRunner::class)->run('fixed_asset_opening', $this->csv($csv));
        $this->assertSame([], $result['failed'] ?? [], json_encode($result));

        $asset = FixedAsset::query()->where('name', 'Old generator')->sole();
        $this->assertTrue($asset->isAwaiting(), '⛔ পুরনো জের সই ছাড়াই খাতায় উঠল।');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', FixedAsset::drillSourceType())->where('source_id', $asset->id)->count());

        $approval = app(ApprovalEngine::class)->latestFor($asset, AccountsSignature::FIXED_ASSET_OPENING);
        $this->assertSame(Approval::PENDING, $approval?->status, '⛔ নিজের সই চাওয়া হয়নি।');
        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $asset->refresh();
        $this->assertTrue($asset->isActive());
        $this->assertSame(0, bccomp($asset->accumulated(), '20000', 4), 'এ পর্যন্ত ক্ষয় বসেনি।');
        $this->assertSame(0, bccomp($asset->bookValue(), '30000', 4));
    }

    public function test_a_branch_limited_reader_sees_only_their_branch_assets(): void
    {
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $category = $this->category();

        foreach ([[$a, 'Mymensingh fridge'], [$b, 'Netrakona fridge']] as [$branch, $name]) {
            app(FixedAssetService::class)->register([
                'category_id' => $category->id, 'name' => $name, 'acquired_on' => now()->toDateString(), 'cost' => '40000',
                'funded_by' => FixedAssetService::FUNDED_ALREADY, 'branch_id' => $branch->id,
            ]);
        }

        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => null, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('accounts.asset.view', 'web')));
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $a->id,
        ]);
        app(DataScope::class)->forget();

        $page = $this->actingAs($clerk->fresh())->get(route('accounts.asset.index'))->assertOk();
        $page->assertSee('Mymensingh fridge')->assertDontSee('Netrakona fridge');

        $other = FixedAsset::acrossBranches()->where('name', 'Netrakona fridge')->sole();
        $this->get(route('accounts.asset.show', $other))->assertNotFound();
    }

    public function test_the_screens_open(): void
    {
        $category = $this->category();
        [$bill] = $this->postedBill();

        $this->get(route('accounts.asset.category.index'))->assertOk()->assertSee($category->code);
        $this->get(route('accounts.asset.category.create'))->assertOk();
        $this->get(route('accounts.asset.category.edit', $category))->assertOk();
        $this->get(route('accounts.asset.create', ['bill_line' => $bill->lines()->value('id')]))->assertOk()->assertSee($bill->document_no);
        $this->get(route('accounts.asset.index', ['category' => $category->id, 'status' => FixedAsset::IDLE]))->assertOk();

        $this->post(route('accounts.asset.category.store'), [...$this->categoryData(), 'code' => 'FUR', 'name_en' => 'Furniture', 'is_active' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(AssetCategory::query()->where('code', 'FUR')->exists());

        $this->post(route('accounts.asset.store'), [
            'category_id' => $category->id, 'name' => 'Bike', 'acquired_on' => now()->toDateString(), 'cost' => '90000',
            'funded_by' => FixedAssetService::FUNDED_ALREADY, 'location' => 'Depot gate', 'serial_no' => 'SN-1',
        ])->assertSessionHasNoErrors();
        $bike = FixedAsset::query()->where('name', 'Bike')->sole();
        $this->assertSame('Depot gate', $bike->location);

        $this->post(route('accounts.asset.status', $bike), ['status' => FixedAsset::IDLE])->assertSessionHasNoErrors();
        $this->assertSame(FixedAsset::IDLE, $bike->fresh()->status);
        $this->post(route('accounts.asset.status', $bike), ['status' => FixedAsset::LOST])->assertSessionHasErrors('status');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function categoryData(): array
    {
        return [
            'code' => 'VEH', 'name_en' => 'Vehicles', 'name_bn' => 'যানবাহন',
            'asset_account_id' => $this->id('1202'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)->id,
            'gain_account_id' => StandardChart::find(StandardChart::ASSET_DISPOSAL_GAIN)->id,
            'loss_account_id' => StandardChart::find(StandardChart::ASSET_DISPOSAL_LOSS)->id,
            'impairment_account_id' => null,
            'method' => FixedAsset::STRAIGHT_LINE, 'life_months' => 60, 'residual_percent' => '10',
        ];
    }

    private function category(): AssetCategory
    {
        return AssetCategory::query()->where('code', 'VEH')->first()
            ?? app(AssetCategoryService::class)->create($this->categoryData());
    }

    private function id(string $code): int
    {
        return (int) Account::query()->postable()->where('code', $code)->value('id');
    }

    /** @return array{0: PurchaseBill, 1: Product, 2: Warehouse} */
    private function postedBill(): array
    {
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->firstOrFail();
        $bill = app(PurchaseBillService::class)->create(
            ['supplier_id' => Supplier::query()->firstOrFail()->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '10', 'rate' => '100']],
        );

        return [app(PurchaseBillService::class)->confirm($bill)->fresh(), $product, $warehouse];
    }

    private function balance(string $code): string
    {
        return $this->balanceOf((int) StandardChart::find($code)->id);
    }

    private function balanceOf(int $accountId): string
    {
        $row = LedgerEntry::query()->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c')->first();

        return bcsub((string) $row->d, (string) $row->c, 4);
    }

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'abos-fa').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'assets.csv', 'text/csv', null, true);
    }
}
