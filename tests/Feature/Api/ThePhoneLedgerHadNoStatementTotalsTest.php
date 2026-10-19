<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
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
use Tests\TestCase;

/**
 * ⭐ ফোনের দোকানের খাতায় হিসাব-বিবরণীর চার অঙ্ক — পূর্ণ নিরীক্ষা §৯ ⚠️৭, ১৮ অক্টোবর ২০২৬ ([[PartyLedger::statement()]])।
 *
 * ⛔ আগে কেবল একটা বকেয়া আর সারি — প্রারম্ভিক, মোট ডেবিট, মোট ক্রেডিট, সমাপনী কোথাও নেই।
 *
 * দাবি:
 *  - সময় ছাড়া: প্রারম্ভিক ০, ডেবিট ১০০০, ক্রেডিট ৪০০, সমাপনী ৬০০ = মাথার বকেয়া।
 *  - `from` গতকাল: প্রারম্ভিক ১০০০ (আগের মাল), ডেবিট ০, ক্রেডিট ৪০০, সমাপনী ৬০০।
 *  - `to` চার দিন আগে: সময়ে কিছু নেই — সমাপনী ০, পরের সারি গোনা হয় না।
 *  - "কেবল ক্রেডিট" বাছলে সারি কমে, বিবরণী বদলায় না।
 */
final class ThePhoneLedgerHadNoStatementTotalsTest extends TestCase
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
    }

    public function test_the_ledger_carries_opening_debit_credit_and_closing_for_the_period(): void
    {
        $customer = $this->customerWithEntries();
        $url = '/api/v1/sales/customers/'.$customer->public_id.'/ledger';

        $all = $this->phone($url)->assertOk()->json();
        $this->assertStatement(['0', '1000', '400', '600'], $all['statement'] ?? null, 'সময় ছাড়া');
        $this->assertSame(0, bccomp((string) $all['statement']['closing'], (string) $all['due'], 4), '⛔ সমাপনী জের মাথার বকেয়া নয়।');

        $since = $this->phone($url.'?from='.Carbon::today()->subDay()->toDateString())->assertOk()->json();
        $this->assertStatement(['1000', '0', '400', '600'], $since['statement'], '⛔ গতকাল থেকে — আগের মাল প্রারম্ভিকে যায়নি');

        $before = $this->phone($url.'?to='.Carbon::today()->subDays(4)->toDateString())->assertOk()->json();
        $this->assertStatement(['0', '0', '0', '0'], $before['statement'], '⛔ সময়ের পরের সারি গোনা হলো');

        $credits = $this->phone($url.'?side=credit')->assertOk()->json();
        $this->assertCount(1, $credits['entries'], 'দাবির ভিত্তি নেই — "কেবল ক্রেডিট" সারি কমায়নি');
        $this->assertStatement(['0', '1000', '400', '600'], $credits['statement'], '⛔ সারির ছাঁকনি বিবরণী বদলাল');
    }

    /** @param list<string> $want */
    private function assertStatement(array $want, mixed $got, string $why): void
    {
        $this->assertIsArray($got, '⛔ খাতায় বিবরণী নেই — '.$why);
        foreach (['opening', 'debit', 'credit', 'closing'] as $i => $key) {
            $this->assertSame(0, bccomp((string) $got[$key], $want[$i], 4), $why.' — '.$key.' '.$got[$key].', চাই '.$want[$i]);
        }
    }

    /** ⓘ চার দিন আগে নয়, তিন দিন আগে মাল (Dr ১০০০), আজ নগদ (Cr ৪০০) */
    private function customerWithEntries(): Customer
    {
        $this->actingAs($this->owner);
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

        return $customer;
    }

    /** @param list<array<string, mixed>> $lines */
    private function journal(Carbon $date, array $lines): void
    {
        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => $date->toDateString(), 'narration' => 'phone statement',
            'instrument_no' => 'TST-PS-'.random_int(1, 999999),
        ], $lines));
    }

    private function phone(string $url): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }
}
