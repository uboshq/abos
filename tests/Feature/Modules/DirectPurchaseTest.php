<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * সরাসরি ক্রয় চালান — এক পর্দায় মাল, দাম আর টাকা।
 *
 * পর্দাটা খোলে কিনা তা যথেষ্ট নয়। এখানে যাচাই হয় তার পরেরটুকু: মাল
 * সত্যিই গুদামে ঢোকে কিনা, দায় সরবরাহকারীর নামে বসে কিনা, বিক্রয়মূল্য
 * পণ্যের গায়ে পৌঁছায় কিনা, আর টাকা দিলে সেটা খতিয়ানে যায় কিনা।
 */
class DirectPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    private function moneyAccount(): Account
    {
        return Account::query()->firstOrCreate(
            ['code' => '1102-01'],
            [
                'company_id' => $this->company->id,
                'parent_id' => Account::query()->where('code', StandardChart::BANK_AND_MFS)->value('id'),
                'name_en' => 'Bank CD',
                'name_bn' => 'ব্যাংক চলতি',
                'type' => Account::ASSET,
                'nature' => Account::DEBIT,
                'is_group' => false,
                'is_bank' => true,
            ],
        );
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'MEG-'.fake()->unique()->numberBetween(1000, 9999),
            'lines' => [[
                'product_id' => $this->product->id,
                'qty' => '100',
                'rate' => '60',
                'sales_price' => '90',
            ]],
            ...$overrides,
        ];
    }

    public function test_the_screen_opens(): void
    {
        $this->get(route('purchase.direct.create'))
            ->assertOk()
            ->assertSee($this->supplier->name())
            ->assertSee(__('purchase::action.confirm_direct'));
    }

    public function test_one_screen_brings_the_goods_in_and_the_liability_on_the_books(): void
    {
        $before = app(StockService::class)->availableQty($this->product, $this->warehouse);

        $this->post(route('purchase.direct.store'), $this->payload())->assertRedirect();

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();

        // বিলটা খসড়া থাকে না — মাল তো নেমেই গেছে
        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status);

        // মাল গুদামে
        $after = app(StockService::class)->availableQty($this->product, $this->warehouse);
        $this->assertSame(0, bccomp(bcsub($after, $before, 4), '100', 4));

        // দায় সরবরাহকারীর নামে — বিলের নিজের দাখিলাগুলো থেকেই গোনা
        $posted = LedgerEntry::query()
            ->where('source_type', PurchaseBill::drillSourceType())
            ->where('source_id', $bill->id)
            ->get();

        $this->assertTrue($posted->isNotEmpty(), 'The bill never reached the ledger.');
        $this->assertSame(
            0,
            bccomp(
                (string) $posted->sum('debit'),
                (string) $posted->sum('credit'),
                4,
            ),
            'The bill does not balance.',
        );
    }

    /**
     * দাম ঠিক করার কাজটা কোথাও গিয়ে পৌঁছায়।
     *
     * বিলের সারিতে দামটা ইতিহাস; বিক্রয়ের পর্দা পড়ে পণ্যের গায়ের দাম।
     * দ্বিতীয়টা না বসালে পুরো দর নির্ধারণটাই বৃথা যেত।
     */
    public function test_the_sales_price_lands_on_the_product(): void
    {
        $this->post(route('purchase.direct.store'), $this->payload())->assertRedirect();

        $product = $this->product->fresh();

        $this->assertSame(0, bccomp((string) $product->sale_price, '90', 4));
        $this->assertSame(0, bccomp((string) $product->purchase_price, '60', 4));
    }

    public function test_money_paid_on_the_spot_becomes_a_confirmed_payment(): void
    {
        $this->post(route('purchase.direct.store'), $this->payload([
            'paid_now' => '2000',
            'paid_from_account_id' => $this->moneyAccount()->id,
        ]))->assertRedirect();

        $payment = Payment::query()->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::CONFIRMED, $payment->status);
        $this->assertSame(0, bccomp((string) $payment->amount, '2000', 4));

        // টাকাটা যে খাত থেকে গেছে বলা হয়েছিল, সেখান থেকেই গেছে
        $credited = LedgerEntry::query()
            ->where('source_type', Payment::drillSourceType())
            ->where('source_id', $payment->id)
            ->where('account_id', $this->moneyAccount()->id)
            ->sum('credit');

        $this->assertSame(0, bccomp((string) $credited, '2000', 4));
    }

    public function test_paying_without_saying_where_from_is_refused(): void
    {
        $this->post(route('purchase.direct.store'), $this->payload(['paid_now' => '500']))
            ->assertSessionHasErrors('paid_from_account_id');

        $this->assertSame(0, PurchaseBill::query()->count());
    }

    public function test_nothing_is_bought_without_a_line(): void
    {
        $this->post(route('purchase.direct.store'), $this->payload(['lines' => []]))
            ->assertSessionHasErrors('lines');

        $this->assertSame(0, PurchaseBill::query()->count());
    }

    public function test_a_bill_with_no_price_decision_leaves_the_product_alone(): void
    {
        $was = (string) $this->product->sale_price;

        $this->post(route('purchase.direct.store'), $this->payload([
            'lines' => [[
                'product_id' => $this->product->id,
                'qty' => '10',
                'rate' => '65',
            ]],
        ]))->assertRedirect();

        // বিক্রয়মূল্য না দিলে পুরনোটাই থাকে — ফাঁকা ঘর কোনো সিদ্ধান্ত নয়
        $this->assertSame(0, bccomp((string) $this->product->fresh()->sale_price, $was, 4));
    }

    public function test_someone_who_cannot_bill_cannot_buy_directly(): void
    {
        $clerk = User::factory()->create();
        $clerk->companies()->attach($this->company, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();
        $clerk->givePermissionTo(Permission::findOrCreate('purchase.bill.view', 'web'));

        $this->actingAs($clerk)->get(route('purchase.direct.create'))->assertForbidden();
        $this->actingAs($clerk)->post(route('purchase.direct.store'), $this->payload())->assertForbidden();
    }
}
