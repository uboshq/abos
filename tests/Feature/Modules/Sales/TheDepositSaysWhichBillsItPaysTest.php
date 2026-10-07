<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CollectionLine;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DepositClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ জমার বিজ্ঞপ্তিতে "কোন বিলের বিপরীতে" — টাকার পরিকল্পনা ২, ৭ অক্টোবর ২০২৬ ([[DepositClaimService]])।
 *
 * ⭐ দাবি: খোলা বিলের তালিকা কেবল এই ডিলারের, পাকা, বকেয়াসহ, পুরনো আগে; বাছা বিল দাবিতে থাকে, গ্রহণে আদায়ের সারি হয়ে
 * সেই বিলগুলোতে মেলে — গৃহীত অঙ্ক কমলে ক্রমে যতটা মেলে, মাঝে শোধ হওয়া বিলে বকেয়া পর্যন্ত; না বাছলে আগের মতো খালি আদায়।
 * অন্যের বিল, পাকা নয় এমন বিল, বকেয়ার বেশি, একই বিল দুইবার, বা মোট জমার বেশি — দাবি তোলার মুহূর্তেই ৪২২। ফোন, পোর্টাল আর
 * ওয়েবের অনুরোধ-ফর্ম একই নিয়মে; ডিপোর তালিকা আর ডিলারের দাবির পাতা বাছা বিল দেখায়।
 */
final class TheDepositSaysWhichBillsItPaysTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $dealer;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->dealer = Customer::query()->orderBy('id')->firstOrFail();
        $this->dealer->forceFill(['portal_enabled' => true, 'portal_password' => 'dealer-pass-1'])->save();
        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-BILLSBANK', 'name_en' => 'Bills bank', 'name_bn' => 'বিলের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK,
        ]);
    }

    public function test_the_phone_lists_the_dealers_open_bills_and_the_accepted_deposit_lands_on_the_bills_picked(): void
    {
        $old = $this->bill('INV-B-1', '600', now()->subDays(9));
        $new = $this->bill('INV-B-2', '400', now()->subDays(2));
        $paid = $this->bill('INV-B-3', '300', now()->subDays(5));
        $this->paid($paid, '300');
        $this->bill('INV-B-4', '700', now()->subDays(4), DocumentStatus::DRAFT);
        $this->bill('INV-B-5', '800', now()->subDays(8), customer: $this->other());

        $this->phone(['sales.collection.create']);
        $bills = $this->getJson('/api/v1/sales/deposit-requests/bills?customer='.$this->dealer->public_id)->assertOk()->json('bills');
        $this->assertSame(['INV-B-1', 'INV-B-2'], array_column($bills, 'no'), '⛔ খোলা বিলের তালিকা ভুল — অন্যের, খসড়া বা শোধ হওয়া এল, বা ক্রম পুরনো আগে নয়।');
        $this->assertSame(['600.00', '400.00'], array_column($bills, 'due'));

        $json = $this->postJson('/api/v1/sales/deposit-requests', $this->form('1000', [
            ['invoice' => (string) $old->public_id, 'amount' => '600'],
            ['invoice' => (string) $new->public_id, 'amount' => '400'],
        ]))->assertCreated()->json();
        $this->assertSame([['id' => (string) $old->public_id, 'no' => 'INV-B-1', 'amount' => '600.00'], ['id' => (string) $new->public_id, 'no' => 'INV-B-2', 'amount' => '400.00']], $json['bills']);

        $claim = DepositClaim::query()->where('public_id', $json['id'])->firstOrFail();
        app(DepositClaimService::class)->accept($claim, $this->bank->id);

        $this->assertSame(['INV-B-1' => '600.0000', 'INV-B-2' => '400.0000'], $this->linesOf($claim));
        $this->assertSame(['0.0000', '0.0000'], [$old->fresh()->dueAmount(), $new->fresh()->dueAmount()], '⛔ বিলের বকেয়া কমেনি।');
    }

    public function test_a_smaller_accepted_amount_fills_the_bills_in_order_and_a_bill_paid_meanwhile_takes_only_its_due(): void
    {
        $a = $this->bill('INV-C-1', '600', now()->subDays(9));
        $b = $this->bill('INV-C-2', '400', now()->subDays(2));
        $c = $this->bill('INV-C-3', '500', now()->subDays(1));
        $claim = app(DepositClaimService::class)->raise($this->dealer, [
            'claimed_on' => now()->toDateString(), 'amount' => '1500', 'method' => DepositClaim::BANK,
            'bills' => [['sales_invoice_id' => $a->id, 'amount' => '600'], ['sales_invoice_id' => $b->id, 'amount' => '400'], ['sales_invoice_id' => $c->id, 'amount' => '500']],
        ]);

        // ⓘ মাঝে বিল A-র ৫০০ অন্য আদায়ে শোধ; ব্যাংক চার্জ কেটে গৃহীত ৯০০
        $this->paid($a, '500');
        app(DepositClaimService::class)->accept($claim, $this->bank->id, ['amount' => '900']);

        $this->assertSame(['INV-C-1' => '100.0000', 'INV-C-2' => '400.0000', 'INV-C-3' => '400.0000'], $this->linesOf($claim),
            '⛔ গ্রহণে ভাগ ভুল — বকেয়ার বেশি বসল, বা গৃহীত টাকার বেশি, বা ক্রম মানল না।');
    }

    public function test_no_bills_picked_is_the_old_path_and_wrong_picks_are_refused_when_raised(): void
    {
        $mine = $this->bill('INV-D-1', '500', now()->subDays(3));
        $draft = $this->bill('INV-D-2', '500', now()->subDays(3), DocumentStatus::DRAFT);
        $theirs = $this->bill('INV-D-3', '500', now()->subDays(3), customer: $this->other());
        $service = app(DepositClaimService::class);

        $plain = $service->raise($this->dealer, ['claimed_on' => now()->toDateString(), 'amount' => '300', 'method' => DepositClaim::BANK]);
        $this->assertNull($plain->bills);
        $service->accept($plain, $this->bank->id);
        $this->assertSame([], $this->linesOf($plain), '⛔ বিল না বাছা আদায় কোনো বিলে বসল।');

        $this->phone(['sales.collection.create']);
        foreach ([
            'অন্যের বিল' => ['300', [['invoice' => (string) $theirs->public_id, 'amount' => '100']]],
            'অচেনা বিল' => ['300', [['invoice' => '00000000-0000-0000-0000-000000000000', 'amount' => '100']]],
            'খসড়া বিল' => ['300', [['invoice' => (string) $draft->public_id, 'amount' => '100']]],
            // ⓘ জমার অঙ্ক ৬০০ — যাতে কেবল বকেয়ার নিয়মটাই আটকায়, মোটের নিয়ম নয়
            'বকেয়ার বেশি' => ['600', [['invoice' => (string) $mine->public_id, 'amount' => '501']]],
            'একই বিল দুইবার' => ['300', [['invoice' => (string) $mine->public_id, 'amount' => '100'], ['invoice' => (string) $mine->public_id, 'amount' => '100']]],
            'জমার চেয়ে বেশি' => ['300', [['invoice' => (string) $mine->public_id, 'amount' => '400']]],
        ] as $why => [$amount, $bills]) {
            $before = DepositClaim::query()->count();
            $this->postJson('/api/v1/sales/deposit-requests', $this->form($amount, $bills))->assertStatus(422)->assertJsonValidationErrors('bills');
            $this->assertSame($before, DepositClaim::query()->count(), "⛔ {$why}: দাবি বসে গেল।");
        }

        // ⛔ সেবার নিজের দেয়াল — দরজা ছাড়া ডাকলেও অন্যের বিল নয় (দরজার [[DepositRequestController::billIds()]]-এর নিচে দ্বিতীয় দেয়াল)
        try {
            $service->raise($this->dealer, ['claimed_on' => now()->toDateString(), 'amount' => '300', 'method' => DepositClaim::CASH,
                'bills' => [['sales_invoice_id' => $theirs->id, 'amount' => '100']]]);
            $this->fail('⛔ সেবা অন্যের বিলে দাবি বসাল।');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('bills', $e->errors());
        }

        // ⓘ ফাঁকা অঙ্কের সারি বাছাই নয়
        $id = $this->postJson('/api/v1/sales/deposit-requests', $this->form('300', [['invoice' => (string) $mine->public_id, 'amount' => '']]))->assertCreated()->json('id');
        $this->assertNull(DepositClaim::query()->where('public_id', $id)->value('bills'));
    }

    public function test_the_dealer_picks_bills_on_the_portal_and_the_desk_form_and_the_depot_list_show_them(): void
    {
        $bill = $this->bill('INV-E-1', '900', now()->subDays(3));

        $this->actingAs($this->dealer->fresh(), 'portal')->get(route('sales.portal.claim.create'))->assertOk()
            ->assertSee('data-claim-bills', false)->assertSee('INV-E-1')->assertSee((string) $bill->public_id, false);
        $this->actingAs($this->dealer->fresh(), 'portal')->post(route('sales.portal.claim.store'), [
            'claimed_on' => now()->toDateString(), 'amount' => '500', 'method' => 'cash',
            // ⓘ ঘরের ক্রম JSON কলামে বদলাতে পারে — তুলনা ক্রম ছাড়া
            'bills' => [['invoice' => (string) $bill->public_id, 'amount' => '500']],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $portal = DepositClaim::query()->where('customer_id', $this->dealer->id)->latest('id')->firstOrFail();
        $this->assertEquals([['sales_invoice_id' => $bill->id, 'amount' => '500.0000']], $portal->bills);
        $this->actingAs($this->dealer->fresh(), 'portal')->get(route('sales.portal.claim.show', $portal))->assertOk()->assertSee('INV-E-1');

        $desk = $this->member(['sales.collection.create', 'sales.claim.view']);
        $this->actingAs($desk)->get(route('sales.claim.request.create'))->assertOk()->assertDontSee('INV-E-1');
        $this->actingAs($desk)->get(route('sales.claim.request.create', ['customer' => $this->dealer->id]))->assertOk()
            ->assertSee('INV-E-1')->assertSee((string) $bill->public_id, false);
        $this->actingAs($desk)->post(route('sales.claim.request.store'), [
            'customer' => (string) $this->dealer->id, 'claimed_on' => now()->toDateString(), 'amount' => '200', 'method' => 'cash',
            'bills' => [['invoice' => (string) $bill->public_id, 'amount' => '200']],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEquals([['sales_invoice_id' => $bill->id, 'amount' => '200.0000']], DepositClaim::query()->latest('id')->firstOrFail()->bills);

        $this->actingAs($desk)->get(route('sales.claim.index'))->assertOk()->assertSee('data-claim-bills', false)->assertSee('INV-E-1');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function bill(string $no, string $total, \Illuminate\Support\Carbon $on, string $status = DocumentStatus::CONFIRMED, ?Customer $customer = null): SalesInvoice
    {
        return SalesInvoice::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no,
            'customer_id' => ($customer ?? $this->dealer)->id, 'trx_date' => $on->toDateString(), 'due_on' => $on->copy()->addDays(30)->toDateString(),
            'subtotal' => $total, 'discount' => '0', 'tax' => '0', 'total' => $total, 'status' => $status,
        ]);
    }

    private function paid(SalesInvoice $invoice, string $amount): void
    {
        $collection = Collection::query()->create([
            'branch_id' => $invoice->branch_id, 'document_no' => 'COL-'.$invoice->document_no, 'customer_id' => $invoice->customer_id,
            'account_id' => $this->bank->id, 'trx_date' => now()->toDateString(), 'amount' => $amount, 'status' => DocumentStatus::CONFIRMED,
        ]);
        CollectionLine::query()->create(['collection_id' => $collection->id, 'line_no' => 1, 'sales_invoice_id' => $invoice->id, 'amount' => $amount]);
    }

    private function other(): Customer
    {
        return Customer::query()->whereKeyNot($this->dealer->id)->orderBy('id')->firstOrFail();
    }

    /** @return array<string, string> বিলের নম্বর → আদায়ের সারির অঙ্ক */
    private function linesOf(DepositClaim $claim): array
    {
        $claim->refresh();
        $this->assertSame(DepositClaim::ACCEPTED, $claim->status);

        return CollectionLine::query()->where('collection_id', $claim->collection_id)->orderBy('line_no')->get()
            ->mapWithKeys(fn (CollectionLine $l) => [(string) SalesInvoice::query()->whereKey($l->sales_invoice_id)->value('document_no') => bcadd((string) $l->amount, '0', 4)])
            ->all();
    }

    /** @param  list<array<string, string>>  $bills */
    private function form(string $amount, array $bills): array
    {
        return ['customer' => (string) $this->dealer->public_id, 'claimed_on' => now()->toDateString(), 'amount' => $amount, 'method' => 'cash', 'bills' => $bills];
    }

    /** @param  list<string>  $keys */
    private function member(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** @param  list<string>  $keys */
    private function phone(array $keys): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->member($keys), [AuthController::APP]);
    }
}
