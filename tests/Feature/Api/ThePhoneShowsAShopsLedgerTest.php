<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনে গ্রাহকের খাতা — মালিক, ১২ অক্টোবর ২০২৬: *"app e customer list e Customar ledger zate dekha zay sohoze bujarmoto"*।
 *
 * দাবি ([[CustomerLedgerApiController]]):
 *  - মাথার বকেয়া = খাতার ডেবিট − ক্রেডিট; প্রথম সারির জের = মাথার বকেয়া; নতুন আগে।
 *  - গ্রাহক দেখার চাবি ছাড়া ৪০৩; সংখ্যার আইডি ৪০৪ (কেবল public_id)।
 *  - বিক্রয়কর্মী অন্যের দোকানের খাতা পান না — ৪০৪ ([[ScopedToUserDealers]])।
 */
final class ThePhoneShowsAShopsLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_the_due_on_top_is_the_ledger_and_the_newest_row_comes_first(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $customer = $this->customerWithEntries();

        $body = $this->phone($owner, '/api/v1/sales/customers/'.$customer->public_id.'/ledger')->assertOk()->json();

        $net = (string) LedgerEntry::query()->forParty(Customer::drillSourceType(), $customer->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')->value('net');

        $this->assertSame($customer->public_id, $body['id']);
        $this->assertSame(0, bccomp((string) $body['due'], '600', 4), '⛔ ১০০০ নিয়ে ৪০০ দিলে বাকি ৬০০ নয়।');
        $this->assertSame(0, bccomp((string) $body['due'], $net, 4), '⛔ মাথার বকেয়া খাতার যোগ নয়।');
        $this->assertNotEmpty($body['entries'], '⛔ খাতার সারি আসেনি।');
        $this->assertSame(0, bccomp((string) $body['entries'][0]['balance'], (string) $body['due'], 4), '⛔ প্রথম সারির জের মাথার বকেয়া নয়।');

        $dates = array_column($body['entries'], 'date');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates, '⛔ নতুন সারি আগে নেই।');
    }

    public function test_without_the_customer_key_it_is_refused_and_a_plain_number_is_not_an_address(): void
    {
        $customer = $this->customerWithEntries();
        $stranger = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $stranger->companies()->attach($this->company, ['is_active' => true]);

        $this->phone($stranger, '/api/v1/sales/customers/'.$customer->public_id.'/ledger')->assertForbidden();

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->phone($owner, '/api/v1/sales/customers/'.$customer->id.'/ledger')->assertNotFound();
    }

    public function test_a_salesman_never_reads_another_salesmans_shop(): void
    {
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        CompanyContext::forCompany($this->company->id, fn () => $sales->givePermissionTo(Permission::findOrCreate('customer.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ⓘ বিক্রয়কর্মীর চোখে যে দোকানগুলো পড়ে — বাকিগুলোর একটা নেওয়া হয়
        $this->actingAs($sales->fresh());
        $his = Customer::query()->pluck('id')->all();
        $other = Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->whereNotIn('id', $his)->orderBy('id')->first();
        $this->assertNotNull($other, '⛔ দাবির ভিত্তি নেই — বিক্রয়কর্মী সব দোকান দেখেন, দেয়াল যাচাই হয় না।');

        $this->phone($sales, '/api/v1/sales/customers/'.$other->public_id.'/ledger')->assertNotFound();
    }

    /**
     * ⓘ ডেমোতে গ্রাহকের খাতার সারি নেই — দুই দিনের দুই ভাউচার: আগে মাল (বাকি বাড়ে, বিক্রির খাতে জমা), পরে নগদ টাকা (বাকি কমে)। ⓘ নগদ শূন্যের নিচে নামে না, তাই প্রথম ভাউচারে নগদ নয়।
     * ⓘ বিপজ্জনক উপাত্ত: পুরনো সারি আগে লেখা — উল্টো ক্রমে দিলে "নতুন আগে" দাবি ধরা পড়ে।
     */
    private function customerWithEntries(): Customer
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $cash = Account::query()->where('money_kind', Account::CASH)->postable()->active()->firstOrFail();
        $receivable = StandardChart::find(StandardChart::RECEIVABLE)->id;
        $party = ['party_type' => Customer::drillSourceType(), 'party_id' => $customer->id];

        $this->journal(Carbon::today()->subDays(3), [
            ['account_id' => $receivable, 'debit' => '1000', 'credit' => '0'] + $party,
            ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'debit' => '0', 'credit' => '1000'],
        ]);
        $this->journal(Carbon::today(), [
            ['account_id' => $cash->id, 'debit' => '400', 'credit' => '0'],
            ['account_id' => $receivable, 'debit' => '0', 'credit' => '400'] + $party,
        ]);

        $this->assertTrue(LedgerEntry::query()->forParty(Customer::drillSourceType(), $customer->id)->exists(),
            '⛔ দাবির ভিত্তি নেই — গ্রাহকের খাতায় সারি ওঠেনি।');

        return $customer;
    }

    /** @param list<array<string, mixed>> $lines */
    private function journal(Carbon $date, array $lines): void
    {
        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => $date->toDateString(), 'narration' => 'phone ledger',
            'instrument_no' => 'TST-PL-'.random_int(1, 999999),
        ], $lines));
    }

    private function phone(User $user, string $url): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }
}
