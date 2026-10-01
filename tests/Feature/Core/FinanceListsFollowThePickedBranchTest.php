<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * মূলধন, উত্তোলন, জমা, ভাড়া, ঋণ আর জমার দাবির তালিকা সব শাখার সারি দেখাত — ১ অক্টোবর ২০২৬।
 *
 * ── ⛔ মালিকের নির্দেশ ──────────────────────────────────────────────────
 * *"প্রতিটা শাখা পুরোপুরি আলাদা"*। bb-র নিরীক্ষা: জমার দাবি আর ফাইন্যান্সের আটটা তালিকা
 * কেবল অনুমতির ওপর দাঁড়িয়ে, শাখার দেয়াল নেই। দেয়ালটা কেবল দেখানোয়
 * ([[ListedInViewedBranch]]) — হিসাব (মুনাফা-ভাগ, উত্তোলনের সীমা) গোটা কোম্পানির থাকে।
 *
 * ── ⭐ মাপ ───────────────────────────────────────────────────────────────
 * একই মালিক, একই পাতা: ময়মনসিংহ বাছলে কেবল ময়মনসিংহের সারি; "সব শাখা"-য় তিনটাই
 * (ময়মনসিংহ, নেত্রকোনা, শাখাহীন)। ⓘ সারিগুলো কাঁচা বসানো — নয়টা সেবার প্রতিটার ফর্ম
 * পূরণ এই দাবির প্রশ্ন নয়; প্রশ্ন কেবল তালিকা কোন সারি দেখায়।
 */
final class FinanceListsFollowThePickedBranchTest extends TestCase
{
    use RefreshDatabase;

    private const PLACES = ['mms', 'ntk', 'none'];

    private Company $company;

    private User $owner;

    private Branch $mymensingh;

    private Branch $netrakona;

    /** @var array<string, array<string, int>> তালিকা → জায়গা → সারির id */
    private array $row = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->mymensingh = $this->branch('MMS');
        $this->netrakona = $this->branch('NTK');
        CompanyContext::set($this->company->id, $this->mymensingh->id);
        $this->actingAs($this->owner);

        $c = $this->company->id;
        $today = now()->toDateString();
        $account = (int) Account::query()->where('is_group', false)->orderBy('id')->value('id');
        $customer = (int) Customer::query()->withoutGlobalScopes()->where('company_id', $c)->orderBy('id')->value('id');

        $person = DB::table('mdm_people')->insertGetId(['company_id' => $c, 'code' => 'ZQP', 'name_en' => 'Zq Person', 'public_id' => (string) Str::uuid()]);
        $institution = DB::table('fin_institutions')->insertGetId(['company_id' => $c, 'kind' => 'bank', 'name_en' => 'Zq Bank', 'name_key' => 'zq bank']);
        $kind = DB::table('fin_deposit_kinds')->insertGetId(['company_id' => $c, 'code' => 'ZQK', 'name_en' => 'Zq kind', 'shape' => 'lump', 'issuer' => 'bank', 'public_id' => (string) Str::uuid()]);

        $places = ['mms' => $this->mymensingh->id, 'ntk' => $this->netrakona->id, 'none' => null];

        foreach ($places as $where => $branch) {
            $n = 'ZQ'.strtoupper($where);
            $base = ['company_id' => $c, 'branch_id' => $branch];

            $this->row['claims'][$where] = DB::table('sal_deposit_claims')->insertGetId($base + ['customer_id' => $customer, 'claimed_on' => $today, 'amount' => 10, 'method' => 'bank', 'status' => 'pending']);
            $this->row['facilities'][$where] = DB::table('fin_bank_facilities')->insertGetId($base + ['kind' => 'cc', 'bank' => $n, 'sanctioned_on' => $today, 'limit_amount' => 10]);
            $this->row['entries'][$where] = DB::table('acc_capital_entries')->insertGetId($base + ['document_no' => $n.'C', 'contributor_type' => 'person', 'entry_type' => 'contribution', 'trx_date' => $today, 'amount' => 10, 'public_id' => (string) Str::uuid()]);
            $this->row['deposits'][$where] = DB::table('fin_deposits')->insertGetId($base + ['document_no' => $n.'D', 'kind_id' => $kind, 'account_id' => $account, 'held_by' => 'business', 'institution' => $n, 'opened_on' => $today, 'status' => 'active', 'public_id' => (string) Str::uuid()]);
            $this->row['loans'][$where] = DB::table('fin_hand_loan_accounts')->insertGetId($base + ['person_id' => $person, 'status' => 'active', 'public_id' => (string) Str::uuid()]);
            $this->row['policies'][$where] = DB::table('fin_insurance_policies')->insertGetId($base + ['institution_id' => $institution, 'policy_no' => $n.'I', 'covers' => 'fire', 'subject' => $n, 'starts_on' => $today, 'ends_on' => now()->addYear()->toDateString()]);
            $this->row['history'][$where] = DB::table('acc_profit_shares')->insertGetId($base + ['document_no' => $n.'P', 'trx_date' => $today, 'person_id' => $person, 'profit_base' => 10, 'amount' => 1, 'status' => 'posted']);
            $this->row['contracts'][$where] = DB::table('fin_rental_contracts')->insertGetId($base + ['counterparty' => $n, 'account_id' => $account, 'expense_account_id' => $account, 'deposit_amount' => 0, 'monthly_rent' => 10, 'starts_on' => $today, 'term_months' => 12, 'ends_on' => now()->addYear()->toDateString(), 'status' => 'active']);
            $this->row['rows'][$where] = DB::table('fin_withdrawals')->insertGetId($base + ['document_no' => $n.'W', 'amount' => 10, 'trx_date' => $today, 'public_id' => (string) Str::uuid()]);
        }
    }

    public function test_each_finance_list_shows_only_the_picked_branchs_rows(): void
    {
        $pages = [
            'জমার দাবি' => [route('sales.claim.index'), 'claims', 'claims'],
            'ব্যাংক সুবিধা' => [route('finance.bank_facility.index'), 'facilities', 'facilities'],
            'মূলধন' => [route('finance.capital.index'), 'entries', 'entries'],
            'সব জমা' => [route('finance.deposit.all'), 'deposits', 'deposits'],
            'ব্যাংকের জমা' => [route('finance.deposit.index', 'bank'), 'deposits', 'deposits'],
            'হাত-ঋণ' => [route('finance.hand_loan.index'), 'standing', 'loans'],
            'বীমা' => [route('finance.insurance.index'), 'policies', 'policies'],
            'মুনাফা-ভাগ' => [route('finance.profit.index'), 'history', 'history'],
            'ভাড়া' => [route('finance.rental.index'), 'contracts', 'contracts'],
            'উত্তোলন' => [route('finance.withdrawal.index'), 'rows', 'rows'],
        ];

        foreach (['mms' => $this->mymensingh->id, 'all' => 'all'] as $pick => $branch) {
            $this->choose($branch);

            foreach ($pages as $name => [$url, $key, $bag]) {
                $ids = $this->idsOn($url, $key);

                foreach (self::PLACES as $where) {
                    $should = $pick === 'all' || $where === 'mms';

                    $this->assertSame($should, in_array($this->row[$bag][$where], $ids, true), sprintf(
                        '⛔ %s — "%s" বেছে %s-এর সারি %s।', $name, $pick, $where, $should ? 'দেখা যায়নি' : 'দেখা গেল',
                    ));
                }
            }
        }
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return list<int> */
    private function idsOn(string $url, string $key): array
    {
        $data = $this->actingAs($this->owner)->get($url)->assertOk()->viewData($key);

        // ⓘ হাত-ঋণের তালিকা খাতার সাথে জের নিয়ে আসে — `standing.rows[].account`
        $items = is_array($data) && isset($data['rows']) ? $data['rows'] : $data;
        $items = is_object($items) && method_exists($items, 'items') ? $items->items() : $items;

        return collect($items)->map(fn ($r) => (int) (is_array($r) ? $r['account']->id : $r->id))->values()->all();
    }

    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())
            ->post(route('branch.switch'), ['branch_id' => (string) $branch])
            ->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
