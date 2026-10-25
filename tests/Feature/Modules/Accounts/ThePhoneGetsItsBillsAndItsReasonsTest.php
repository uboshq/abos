<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Sync\SyncService;
use App\Core\Services\DealerScope;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ ফোনের বাকি জায়গা — a4-এর চাওয়া (পরিশোধে-মোট-টাকা-চুক্তি §৭, ২৫ অক্টোবর ২০২৬)।
 *
 * দাবি:
 *   ১. `GET /parties/{type}/{id}/open-bills` — ওয়েবের কার্ডের একই বিল (একই public_id, একই বাকি), চুক্তির ঘরে
 *      (id = public_id, no, date, due_date, days_late, total, outstanding)
 *   ২. দেয়াল: অজানা পক্ষে ৪০৪; বিক্রয়কর্মী নিজের ডিলারের বিল পান, অন্যের ডিলারে ৪০৪
 *   ৩. sync push-এ ফেরানো সারি: পুরনো প্রথম কথার পাশে `errors: {ঘর: [কথা]}`, ঘরের নাম ওয়েবের ৪২২-এর মতো; ঘরহীন কারণ `_`-এ
 */
final class ThePhoneGetsItsBillsAndItsReasonsTest extends TestCase
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

    /** ১ — ওয়েবের কার্ডের একই বিল, চুক্তির ঘরে */
    public function test_the_open_bills_door_answers_the_web_cards_bills_in_the_contracts_fields(): void
    {
        $customer = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $invoice = $this->confirmedInvoice($customer);

        $web = collect($this->getJson(route('accounts.voucher.due', ['party_type' => 'customer', 'party_id' => $customer->id]))->assertOk()->json('bills'));

        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $phone = collect($this->getJson('/api/v1/accounts/parties/customer/'.$customer->id.'/open-bills')->assertOk()->json('bills'));

        $row = $phone->firstWhere('id', $invoice->public_id);
        $this->assertNotNull($row, '⛔ ফোনের খোলা বিলে বিলটা নেই, বা id বিলের public_id নয়');
        $this->assertSame(['id', 'no', 'date', 'due_date', 'days_late', 'total', 'outstanding'], array_keys($row), '⛔ ফোনের বিলের ঘর চুক্তির নয়');
        $this->assertSame($web->pluck('public_id')->all(), $phone->pluck('id')->all(), '⛔ ফোন আর ওয়েবের কার্ড আলাদা বিল দেখায়');
        $this->assertSame(0, bccomp((string) $row['outstanding'], (string) $web->firstWhere('public_id', $invoice->public_id)['outstanding'], 4),
            '⛔ ফোনের বাকি ওয়েবের কার্ডের বাকি নয়');
    }

    /** ২ — দেয়াল: অজানা পক্ষে ৪০৪; বিক্রয়কর্মী কেবল নিজের ডিলারে */
    public function test_the_open_bills_door_keeps_the_company_and_the_dealer_walls(): void
    {
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $this->assertSame(404, $this->getJson('/api/v1/accounts/parties/customer/999999/open-bills')->status(), '⛔ অজানা পক্ষের খোলা বিল এল — নাগালের বাইরের পক্ষ ৪০৪ পায় না');

        $sr = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        CompanyContext::forCompany($this->company->id, fn () => $sr->givePermissionTo(Permission::findOrCreate('accounts.voucher.create', 'web')));
        $this->assertTrue(app(DealerScope::class)->walled($sr->fresh()), 'দৃশ্যটাই ঠিক নয় — ডেমোর বিক্রয়কর্মী দেয়ালে নেই');
        $own = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $stranger = Customer::acrossDealers()->where('name_en', 'Niloy Store')->firstOrFail();

        $this->app['auth']->forgetGuards();
        app(DealerScope::class)->forget();
        Sanctum::actingAs($sr->fresh(), [AuthController::APP]);

        $this->assertSame(200, $this->getJson('/api/v1/accounts/parties/customer/'.$own->id.'/open-bills')->status(),
            '⛔ বিক্রয়কর্মী নিজের ডিলারের খোলা বিল পান না');
        $this->assertSame(404, $this->getJson('/api/v1/accounts/parties/customer/'.$stranger->id.'/open-bills')->status(),
            '⛔ বিক্রয়কর্মী অন্যের ডিলারের খোলা বিল দেখেন — দেয়াল ভাঙা');
    }

    /** ৩ — ফেরানো সারিতে ঘর ধরে কারণ */
    public function test_a_rejected_push_row_names_its_fields_beside_the_first_message(): void
    {
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $cash = Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('code')->firstOrFail();

        $answer = $this->push([[
            'changeId' => 'gross-over-1', 'entityType' => 'Voucher', 'operation' => 'CREATE',
            'payloadJson' => json_encode([
                'type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(), 'narration' => 'ফোনের মোট',
                'party_type' => 'supplier', 'party_id' => $supplier->id, 'from_account_id' => $cash->id, 'instrument' => 'cash',
                'gross_amount' => '100', 'ait_amount' => '60', 'vds_amount' => '40',
            ]),
        ]]);
        $row = $answer[0] ?? $answer['results'][0] ?? [];

        $this->assertSame('rejected', strtolower((string) ($row['status'] ?? '')), 'দৃশ্যটাই ঠিক নয় — সারিটা ফেরেনি: '.json_encode($row, JSON_UNESCAPED_UNICODE));
        $expected = array_map(fn (string $l) => trans('accounts::validation.withheld_not_below_gross', ['gross' => '100.00', 'withheld' => '100.00'], $l), ['bn', 'en']);
        $this->assertContains($row['errors']['gross_amount'][0] ?? null, $expected, '⛔ ফেরানো সারিতে gross_amount-এর ঘরে কারণ নেই: '.json_encode($row, JSON_UNESCAPED_UNICODE));
        $this->assertContains($row['message'] ?? null, $expected, '⛔ পুরনো প্রথম কথাটা হারাল — পুরনো ফোন কিছু দেখাবে না');
    }

    /** ৪ — ব্যাখ্যাই করা গেল না এমন সারিও একই আকারে: ঘর ধরে কারণ, আর পুরনো প্রথম কথা (a4-এর পরখ, 1de6b0897) */
    public function test_an_unreadable_push_row_names_its_field_too(): void
    {
        $answer = $this->push([['changeId' => 'no-entity-1', 'operation' => 'CREATE', 'payloadJson' => '{}']]);
        $row = $answer[0] ?? [];

        $this->assertSame('rejected', strtolower((string) ($row['status'] ?? '')), 'দৃশ্যটাই ঠিক নয় — সারিটা ফেরেনি: '.json_encode($row, JSON_UNESCAPED_UNICODE));
        $expected = [trans('sync.change_needs_entity_type', [], 'bn'), trans('sync.change_needs_entity_type', [], 'en')];
        $this->assertContains($row['errors']['entityType'][0] ?? null, $expected, '⛔ ব্যাখ্যা-না-হওয়া সারিতে ঘর ধরে কারণ নেই: '.json_encode($row, JSON_UNESCAPED_UNICODE));
        $this->assertContains($row['message'] ?? null, $expected, '⛔ পুরনো প্রথম কথাটা হারাল — পুরনো ফোন কিছু দেখাবে না');
        $this->assertSame('no-entity-1', $row['changeId'] ?? null, '⛔ ফেরানো সারির changeId হারাল — ফোন উত্তর মেলাতে পারে না');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** @param  list<array<string, mixed>>  $rows  @return array<int|string, mixed> */
    private function push(array $rows): array
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner->fresh());

        return app(SyncService::class)->push($this->owner->fresh(), 'phone-gross', 'accounts', $rows);
    }

    private function confirmedInvoice(Customer $customer): SalesInvoice
    {
        return app(SalesInvoiceService::class)->confirm(app(SalesInvoiceService::class)->create(
            ['customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => '1', 'rate' => '100']],
        ));
    }
}
