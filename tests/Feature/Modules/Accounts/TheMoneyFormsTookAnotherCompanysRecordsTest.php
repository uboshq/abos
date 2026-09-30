<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Person;
use App\Modules\MasterData\Models\TransferMode;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * টাকার ফর্ম অন্য কোম্পানির সারি মেনে নিত — চূড়ান্ত অডিট ⛔১০, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * `exists:accounts,id` বা `Rule::exists('users', 'id')` কাঁচা কোয়েরিতে চলে — কোম্পানির
 * গ্লোবাল স্কোপ সেখানে নেই। তাই ঋণ, স্থায়ী সম্পদ, চেক, নগদ গণনা, টাকা স্থানান্তর, ব্যাংক
 * মিলকরণ আর খাতের ফর্মে **অন্য কোম্পানির** খাত, টিল, মানুষ, সরবরাহকারী বা ব্যবহারকারীর id
 * পাঠালে যাচাই পার হত — আইডিটা তো কোথাও না কোথাও আছেই। কয়েকটা ঘরে (টিল, চেকের ব্যাংক)
 * `exists`-ই ছিল না।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * প্রতিটা ঘর নিজের কোম্পানিতে ছাঁকা ([[EveryExistsRuleNamesItsCompanyTest]] পাহারা দেয়)।
 * প্রতিটা দাবিতে একই ঘর দুইবার: নিজের কোম্পানির id-তে ঘরটা চুপ, অন্যেরটায় ঘরটাতেই ভুল।
 * ⓘ মালিক (সুপার অ্যাডমিন) নিজেই পাঠান — নিজের সারিতে তাঁকে কিছু আটকায় না।
 */
final class TheMoneyFormsTookAnotherCompanysRecordsTest extends TestCase
{
    use RefreshDatabase;

    private Company $home;

    private Company $other;

    /** @var array<string, int> নিজের কোম্পানির id */
    private array $own = [];

    /** শেষ জবাব — ফাঁকা ভুল-তালিকা মানে যাচাই পর্যন্ত পৌঁছায়নি কি না, সেটা বোঝার জন্য */
    private string $last = '';

    /** @var array<string, int> অন্য কোম্পানির id */
    private array $foreign = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->home = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->other = Company::query()->where('code', 'FMART')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $this->foreign = $this->recordsIn($this->other);
        $this->own = $this->recordsIn($this->home);

        // ⓘ কোনো কোম্পানিতে নেই এমন ব্যবহারকারী — "আছে" কিন্তু আমাদের নয়
        $this->foreign['user'] = (int) User::factory()->create()->id;
        $this->own['user'] = (int) $owner->id;

        CompanyContext::set($this->home->id, $this->home->defaultBranch()?->id);
    }

    public function test_the_new_record_forms_refuse_another_companys_ids(): void
    {
        $this->eachField('accounts.reconciliation.store', [], ['bank_account_id' => 'bank']);
        $this->eachField('accounts.count.store', [], ['cash_till_id' => 'till']);
        $this->eachField('accounts.asset.store', [], [
            'asset_account_id' => 'account',
            'funding_account_id' => 'money',
            'funding_person_id' => 'person',
            'funding_supplier_id' => 'supplier',
        ]);
        $this->eachField('accounts.loan.store', [], [
            'principal_account_id' => 'account',
            'interest_account_id' => 'account',
            'into_account_id' => 'money',
        ]);
        $this->eachField('accounts.transfer.store', [], [
            'from_till_id' => 'till',
            'given_by' => 'user',
            'received_by' => 'user',
        ]);
        $this->eachField('accounts.coa.store', [], ['held_by' => 'user']);
        $this->eachField('accounts.voucher.store', ['type' => Voucher::RECEIPT], [
            'carried_by' => 'user',
            'transfer_mode_id' => 'mode',
        ]);
    }

    public function test_the_forms_on_an_existing_record_refuse_another_companys_ids(): void
    {
        CompanyContext::set($this->home->id, $this->home->defaultBranch()?->id);

        $asset = $this->asset();
        $cheque = $this->cheque();
        $loan = $this->loan();

        $this->eachField('accounts.asset.dispose', ['asset' => $asset], ['into_account_id' => 'money']);
        $this->eachField('accounts.cheque.deposit', ['cheque' => $cheque], ['bank_account_id' => 'bank']);
        $this->eachField('accounts.cheque.clear', ['cheque' => $cheque], ['bank_account_id' => 'bank']);
        $this->eachField('accounts.loan.draw', ['loan' => $loan], ['into_account_id' => 'money']);
        $this->eachField('accounts.loan.repay', ['loan' => $loan], ['from_account_id' => 'money']);
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /**
     * একই ঘর দুইবার: নিজের id-তে ঘরটা চুপ, অন্যের id-তে ঘরটাতেই ভুল।
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $fields  ঘর → কোন ধরনের সারি
     */
    private function eachField(string $route, array $params, array $fields): void
    {
        foreach ($fields as $field => $kind) {
            $mine = $this->errorsFor($route, $params, [$field => $this->own[$kind]]);
            $this->assertArrayNotHasKey($field, $mine,
                "প্রস্তুতিটাই ভুল — {$route}: নিজের কোম্পানির {$kind} ({$this->own[$kind]})-তেই '{$field}' আটকাল: ".json_encode($mine[$field] ?? [], JSON_UNESCAPED_UNICODE));

            $theirs = $this->errorsFor($route, $params, [$field => $this->foreign[$kind]]);
            $this->assertArrayHasKey($field, $theirs,
                "⛔ {$route}: অন্য কোম্পানির {$kind} ({$this->foreign[$kind]}) '{$field}'-এ পার হয়ে গেল। জবাব {$this->last}, ভুল: ".implode(',', array_keys($theirs)));
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function errorsFor(string $route, array $params, array $data): array
    {
        $response = $this->from(route('dashboard'))->post(route($route, $params), $data);
        $this->last = $response->getStatusCode().' → '.($response->headers->get('Location') ?? '');

        $bag = session('errors');

        if ($bag === null) {
            return [];
        }

        // ⓘ সেশন json-এ রাখা হয়, তাই কখনো ব্যাগ, কখনো সাধারণ অ্যারে ({default: {format, messages}})
        $messages = is_array($bag)
            ? (($bag['default'] ?? $bag)['messages'] ?? [])
            : $bag->getBag('default')->toArray();

        session()->forget('errors');

        return is_array($messages) ? $messages : [];
    }

    /**
     * একটা কোম্পানির খাত, টিল, মানুষ আর সরবরাহকারী — তার নিজের সেবা দিয়ে।
     *
     * @return array<string, int>
     */
    private function recordsIn(Company $company): array
    {
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        app(StandardChart::class)->install();
        $till = app(CashTillService::class)->ensurePrimaryTill();

        $person = Person::query()->create([
            'code' => 'P-'.random_int(10000, 99999),
            'name_en' => 'Funding person',
        ]);

        $bank = app(AccountService::class)->create([
            'name_en' => 'Test bank '.$company->code,
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
        ]);

        $supplier = app(SupplierService::class)->create([
            'name_en' => 'Funding supplier '.$company->code,
            'credit_limit' => 0,
            'credit_days' => 0,
        ]);

        $mode = TransferMode::query()->create([
            'code' => 'TM-'.random_int(10000, 99999),
            'name_en' => 'Bank transfer',
            'applies_to' => 'bank',
        ]);

        return [
            'mode' => (int) $mode->id,
            'account' => (int) Account::query()->postable()->where('code', '1202')->value('id'),
            'money' => (int) $till->account_id,
            'bank' => (int) $bank->id,
            'till' => (int) $till->id,
            'person' => (int) $person->id,
            'supplier' => (int) $supplier->id,
        ];
    }

    private function asset(): FixedAsset
    {
        return app(FixedAssetService::class)->register([
            'name' => 'Delivery Van',
            'acquired_on' => now()->subMonth()->toDateString(),
            'cost' => '100000',
            'salvage' => '0',
            'life_months' => 60,
            'asset_account_id' => $this->own['account'],
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)?->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)?->id,
            'funded_by' => FixedAssetService::FUNDED_OPENING,
        ]);
    }

    private function cheque(): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => Cheque::RECEIVED,
            'cheque_no' => 'X'.random_int(100000, 999999),
            'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->toDateString(),
            'amount' => '1000',
            'party_type' => 'customer',
            'party_id' => (int) Customer::query()->value('id'),
        ]);
    }

    private function loan(): Loan
    {
        return app(LoanService::class)->create(
            data: [
                'lender' => 'Islami Bank',
                'kind' => Loan::CC,
                'sanctioned' => '500000',
                'interest_rate' => '0',
                'tenure_months' => 12,
                'interest_method' => 'flat',
                'start_date' => now()->startOfMonth()->toDateString(),
                'principal_account_id' => (int) StandardChart::find(StandardChart::PAYABLE)?->id,
                'interest_account_id' => (int) Account::query()->postable()->where('type', 'expense')->value('id'),
            ],
        );
    }
}
