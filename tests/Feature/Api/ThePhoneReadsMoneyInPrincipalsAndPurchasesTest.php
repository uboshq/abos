<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\DealerBindingService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Sales\Models\Collection;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনে টাকা আদায়, প্রিন্সিপালের তালিকা আর ক্রয়ের তালিকা — মালিক, ৬ অক্টোবর ২০২৬ (সমন্বয়কের মারফত: "অ্যাপে payment
 * received, principal list আর purchase list দরকার")। কেবল পড়া।
 *
 * ⭐ দাবি:
 *   · আদায় — তারিখের পরিসরের ভেতরেরগুলোই, মোট সেগুলোর যোগ; পদ্ধতি টাকার খাত থেকে (নগদ · ব্যাংক · MFS), চেক কাগজের নামে;
 *     পদ্ধতির ছাঁকনিতে কাগজের নাম-ফাঁকা নগদও আসে; খোঁজা; বিস্তারিতে কোন বিলে কত; চাবি ছাড়া ৪০৩; SR কেবল নিজের ডিলারের
 *   · প্রিন্সিপাল — VENDOR ধরনের, সংক্ষিপ্ত নামে; জের ওয়েবের সরবরাহকারীর পাতার একই ("দিতে হবে" ধনাত্মক); শেষ ক্রয়; খাতা
 *   · ক্রয় — পরিসর আর প্রিন্সিপালে ছাঁকা; বিলে পরিশোধিত আর বাকি; লাইনে কেনা দর কেবল খরচ দেখার চাবিতে
 */
final class ThePhoneReadsMoneyInPrincipalsAndPurchasesTest extends TestCase
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

    // ── টাকা আদায় ──────────────────────────────────────────────────────────

    public function test_money_in_lists_the_range_says_the_method_and_adds_up(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $cash = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();
        $bank = $this->bankLeaf($cash);

        $this->collection('RCV-CASH', $customer, $cash, '1500', now()->toDateString());
        $this->collection('RCV-CHQ', $customer, $bank, '2500', now()->toDateString(), 'Cheque', 'CHQ-7781');
        $this->collection('RCV-OLD', $customer, $cash, '9999', now()->subYear()->toDateString());

        $all = $this->phone($this->owner, '/sales/collections', ['from' => now()->subDays(2)->toDateString(), 'to' => now()->toDateString(), 'q' => 'RCV-'])->json();
        $this->assertSame(['RCV-CHQ', 'RCV-CASH'], array_column($all['rows'], 'no'), '⛔ পরিসরের বাইরের আদায় এল, বা ভেতরেরটা নেই।');
        $this->assertSame(0, bccomp((string) $all['total'], '4000', 4), '⛔ মোট পরিসরের আদায়ের যোগ নয়।');
        $this->assertSame(['cheque', 'cash'], array_column($all['rows'], 'method'));

        $onlyCash = $this->phone($this->owner, '/sales/collections', ['from' => now()->subDays(2)->toDateString(), 'q' => 'RCV-', 'method' => 'cash'])->json('rows');
        $this->assertSame(['RCV-CASH'], array_column($onlyCash, 'no'), '⛔ কাগজের নাম-ফাঁকা নগদ আদায় নগদের ছাঁকনিতে হারাল।');
        $this->assertSame(['RCV-CHQ'], array_column($this->phone($this->owner, '/sales/collections',
            ['from' => now()->subDays(2)->toDateString(), 'q' => 'RCV-', 'method' => 'cheque'])->json('rows'), 'no'));

        $one = Collection::query()->where('document_no', 'RCV-CHQ')->firstOrFail();
        $detail = $this->phone($this->owner, '/sales/collections/'.$one->public_id)->json();
        $this->assertSame('CHQ-7781', $detail['instrument_no']);
        $this->assertSame('cheque', $detail['method']);
    }

    public function test_money_in_needs_the_webs_collection_key(): void
    {
        $stranger = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $stranger->companies()->attach($this->company->id, ['is_active' => true]);

        $this->phoneRaw($stranger, '/sales/collections')->assertForbidden();
        $this->phoneRaw($stranger, '/purchase/principals')->assertForbidden();
        $this->phoneRaw($stranger, '/purchase/purchases')->assertForbidden();

        CompanyContext::forCompany($this->company->id, fn () => $stranger->givePermissionTo(Permission::findOrCreate('sales.collection.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->phoneRaw($stranger, '/sales/collections')->assertOk();
    }

    public function test_a_walled_salesman_sees_money_in_only_from_his_own_dealers(): void
    {
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $mine = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $theirs = Customer::query()->where('name_en', 'Karim Stores')->firstOrFail();
        $cash = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();

        $this->collection('RCV-MINE', $mine, $cash, '100', now()->toDateString());
        $this->collection('RCV-THEIRS', $theirs, $cash, '700', now()->toDateString());

        \App\Modules\Customer\Models\DealerBinding::query()->withoutGlobalScopes()->where('user_id', $sales->id)->delete();
        app(DealerBindingService::class)->bind((int) $sales->id, [(int) $mine->id], now()->toDateString());
        app(SettingsService::class)->set(DealerScope::SWITCH, true);
        CompanyContext::forCompany($this->company->id, function () use ($sales) {
            $sales->givePermissionTo(Permission::findOrCreate('sales.collection.view', 'web'));
            Role::findByName('salesman', 'web')->givePermissionTo(Permission::findOrCreate(DealerScope::OWN, 'web'));
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DealerScope::class)->forget();

        $nos = array_column($this->phone($sales, '/sales/collections', ['q' => 'RCV-'])->json('rows'), 'no');
        $this->assertContains('RCV-MINE', $nos, 'দাবির ভিত্তি নেই — নিজের ডিলারের আদায়ও এল না।');
        $this->assertNotContains('RCV-THEIRS', $nos, '⛔ SR-এর ফোনে অন্যের ডিলারের আদায়।');
    }

    // ── প্রিন্সিপাল ─────────────────────────────────────────────────────────

    public function test_principals_carry_the_webs_balance_short_name_and_last_purchase(): void
    {
        $supplier = Supplier::query()->onlySuppliers()->orderBy('id')->firstOrFail();
        $supplier->forceFill(['short_name' => 'Star Line'])->save();
        // ⓘ একটা বিল — জের আর শেষ ক্রয় শূন্য = শূন্য না মাপে
        $this->aBill($supplier);
        $bill = PurchaseBill::query()->where('supplier_id', $supplier->id)->orderByDesc('trx_date')->first();

        $row = collect($this->phone($this->owner, '/purchase/principals', ['q' => 'Star Line'])->json('rows'))->firstWhere('id', (string) $supplier->public_id);
        $this->assertNotNull($row, '⛔ প্রিন্সিপাল সংক্ষিপ্ত নামে খুঁজে পাওয়া গেল না।');
        $this->assertSame('Star Line', $row['name']);

        $this->actingAs($this->owner);
        $web = Supplier::query()->withPayableInView()->whereKey($supplier->id)->firstOrFail()->payable_in_view;
        $this->assertSame(0, bccomp((string) $web, (string) $row['balance'], 4), '⛔ ফোনের জের ওয়েবের পাতার নয়।');
        $this->assertSame($bill?->trx_date?->toDateString(), $row['last_purchase_on']);

        $ledger = $this->phone($this->owner, '/purchase/principals/'.$supplier->public_id)->json();
        $this->assertSame((string) $supplier->public_id, $ledger['id']);
        if ($ledger['entries'] !== []) {
            // ⓘ নতুন আগে — প্রথম সারির জের = মাথার জের (ওয়েবের পাতার নিয়ম)
            $this->assertSame(0, bccomp((string) $ledger['entries'][0]['balance'], (string) $row['balance'], 4), '⛔ খাতার শেষ জের মাথার জের নয়।');
        }

        $customerType = Supplier::query()->whereHas('partyType', fn ($t) => $t->where('code', '!=', Supplier::VENDOR_CODE))->first();
        if ($customerType !== null) {
            $ids = array_column($this->phone($this->owner, '/purchase/principals')->json('rows'), 'id');
            $this->assertNotContains((string) $customerType->public_id, $ids, '⛔ VENDOR নয় এমন সরবরাহকারী প্রিন্সিপালের তালিকায়।');
        }
    }

    // ── ক্রয় ─────────────────────────────────────────────────────────────────

    public function test_purchases_filter_by_range_and_principal_and_hide_cost_without_the_key(): void
    {
        // ⓘ ডেমোতে ক্রয় বিল নেই — দুই প্রিন্সিপালের দুইটা বানানো, আসল পথে; একজনের হলে প্রিন্সিপালের ছাঁকনি কিছুই মাপত না
        $second = Supplier::query()->onlySuppliers()->orderBy('id')->skip(1)->first()
            ?? Supplier::query()->create(['code' => 'SUP-TST2', 'name_en' => 'Second Principal', 'name_bn' => 'Second Principal']);
        $this->aBill($second);
        $this->aBill();
        $bill = PurchaseBill::query()->with('supplier')->whereHas('lines')->orderByDesc('trx_date')->firstOrFail();
        $range = ['from' => $bill->trx_date->toDateString(), 'to' => $bill->trx_date->toDateString()];

        $list = $this->phone($this->owner, '/purchase/purchases', [...$range, 'principal' => (string) $bill->supplier->public_id])->json();
        $row = collect($list['rows'])->firstWhere('id', (string) $bill->public_id);
        $this->assertNotNull($row, '⛔ প্রিন্সিপাল আর দিনে ছাঁকা তালিকায় বিলটা নেই।');
        $this->assertSame(0, bccomp((string) $row['paid'], $bill->paidAmount(), 4));
        $this->assertSame(0, bccomp((string) $row['due'], $bill->dueAmount(), 4));
        foreach ($list['rows'] as $r) {
            $this->assertSame($row['principal'], $r['principal'], '⛔ অন্য প্রিন্সিপালের বিল ছাঁকনিতে এল।');
        }
        $this->assertGreaterThan(count($list['rows']), count($this->phone($this->owner, '/purchase/purchases', $range)->json('rows')),
            'দাবির ভিত্তি নেই — ছাঁকনি ছাড়াও একই কয়টা বিল।');

        $out = $this->phone($this->owner, '/purchase/purchases', ['from' => now()->addYear()->toDateString(), 'to' => now()->addYear()->toDateString()])->json('rows');
        $this->assertSame([], $out, '⛔ পরিসরের বাইরের বিল এল।');

        $withCost = $this->phone($this->owner, '/purchase/purchases/bill/'.$bill->public_id)->json('lines');
        $this->assertArrayHasKey('rate', $withCost[0], 'মালিকের ফোনে কেনা দর নেই।');

        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('purchase.bill.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $lines = $this->phone($clerk, '/purchase/purchases/bill/'.$bill->public_id)->json('lines');
        $this->assertArrayNotHasKey('rate', $lines[0], '⛔ খরচের চাবি ছাড়া কেনা দর ফোনে গেল।');
        $this->assertArrayNotHasKey('amount', $lines[0]);
        $this->phoneRaw($clerk, '/purchase/purchases', ['kind' => 'receipt'])->assertForbidden();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function phone(User $user, string $path, array $query = []): TestResponse
    {
        return $this->phoneRaw($user, $path, $query)->assertOk();
    }

    private function phoneRaw(User $user, string $path, array $query = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        app(DealerScope::class)->forget();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1'.$path.($query === [] ? '' : '?'.http_build_query($query)));
    }

    private function collection(string $no, Customer $customer, Account $account, string $amount, string $on, ?string $instrument = null, ?string $instrumentNo = null): void
    {
        Collection::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => $no,
            'customer_id' => $customer->id,
            'account_id' => $account->id,
            'trx_date' => $on,
            'amount' => $amount,
            'instrument' => $instrument,
            'instrument_no' => $instrumentNo,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function aBill(?Supplier $supplier = null): PurchaseBill
    {
        $this->actingAs($this->owner);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $bills = app(\App\Modules\Purchase\Services\PurchaseBillService::class);

        return $bills->confirm($bills->create(
            ['supplier_id' => ($supplier ?? Supplier::query()->onlySuppliers()->orderBy('id')->firstOrFail())->id, 'trx_date' => now()->toDateString()],
            [['product_id' => \App\Modules\Inventory\Models\Product::query()->orderBy('id')->firstOrFail()->id, 'qty' => '10', 'rate' => '100']],
        ));
    }

    private function bankLeaf(Account $till): Account
    {
        $bank = $till->replicate(['public_id']);
        $bank->forceFill(['code' => 'TST-RCB', 'name_en' => 'Receipt bank', 'name_bn' => 'Receipt bank', 'money_kind' => Account::BANK,
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')])->save();

        return $bank;
    }
}
