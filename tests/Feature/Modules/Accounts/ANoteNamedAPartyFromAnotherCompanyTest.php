<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\MasterData\Models\Person;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * নোট আর স্থায়ী সম্পদ অন্য কোম্পানির পক্ষের নামে বসত — চূড়ান্ত অডিট ⛔৯, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * ক্রেডিট বা ডেবিট নোটের `party_id` যাচাই হত কেবল "ধনাত্মক সংখ্যা" হিসেবে — না আছে কি না,
 * না কোন কোম্পানির। তাই অস্তিত্বহীন বা অন্য কোম্পানির গ্রাহকের নামে নোট কাটা যেত, আর
 * খাতায় সেই পক্ষের সারি জমত যাকে কোনো পর্দা চেনে না। স্থায়ী সম্পদের অর্থদাতা (মালিক,
 * বিক্রেতা, টাকার খাত) একই ফাঁকে — অন্য কোম্পানির বিক্রেতার কাছে দেনা বসত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * সেবাই দেখে ([[PartyRegistry::exists()]] আর খাতের কোম্পানি) — তাই ফর্ম, ফোন, আমদানি যে
 * দরজা দিয়েই আসুক। প্রতিটা দাবিতে একই ঘর দুইবার: নিজের কোম্পানির সারিতে চলে, অন্যেরটায়
 * ঠিক ঘরেই ফেরে। ⓘ মালিক (সুপার অ্যাডমিন) নিজেই করেন — নিজের সারিতে তাঁকে কিছু আটকায় না।
 */
final class ANoteNamedAPartyFromAnotherCompanyTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $home;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->home = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->other = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->at($this->home);
        app(StandardChart::class)->install();
    }

    public function test_a_note_names_only_a_party_of_this_company(): void
    {
        [$ownCustomer, $ownSupplier] = $this->partiesIn($this->home);
        [$theirCustomer, $theirSupplier] = $this->partiesIn($this->other);
        $this->at($this->home);

        // ⭐ নিজের পক্ষ — দুই দিকেই চলে
        $this->assertNotNull($this->note(Note::CREDIT, 'customer', $ownCustomer));
        $this->assertNotNull($this->note(Note::DEBIT, 'supplier', $ownSupplier));

        // ⛔ অন্য কোম্পানির পক্ষ, আর অস্তিত্বহীন পক্ষ
        $this->assertRefusedOn('party_id', fn () => $this->note(Note::CREDIT, 'customer', $theirCustomer));
        $this->assertRefusedOn('party_id', fn () => $this->note(Note::DEBIT, 'supplier', $theirSupplier));
        $this->assertRefusedOn('party_id', fn () => $this->note(Note::CREDIT, 'customer', 999999));
    }

    public function test_a_fixed_asset_is_funded_only_from_this_companys_people_suppliers_and_money(): void
    {
        [, $ownSupplier, $ownPerson, $ownMoney] = $this->partiesIn($this->home);
        [, $theirSupplier, $theirPerson, $theirMoney] = $this->partiesIn($this->other);
        $this->at($this->home);

        // ⓘ নগদে কেনা সম্পদের টাকা টিলে থাকতে হয় ([[CashOnHand]])
        $this->putMoneyIn(Account::query()->findOrFail($ownMoney), '100000');

        $this->assertNotNull($this->asset(['funded_by' => FixedAssetService::FUNDED_CREDIT, 'funding_supplier_id' => $ownSupplier]));
        $this->assertNotNull($this->asset(['funded_by' => FixedAssetService::FUNDED_CAPITAL, 'funding_person_id' => $ownPerson]));
        $this->assertNotNull($this->asset(['funded_by' => FixedAssetService::FUNDED_MONEY, 'funding_account_id' => $ownMoney]));

        $this->assertRefusedOn('funding_supplier_id',
            fn () => $this->asset(['funded_by' => FixedAssetService::FUNDED_CREDIT, 'funding_supplier_id' => $theirSupplier]));
        $this->assertRefusedOn('funding_person_id',
            fn () => $this->asset(['funded_by' => FixedAssetService::FUNDED_CAPITAL, 'funding_person_id' => $theirPerson]));
        $this->assertRefusedOn('funding_account_id',
            fn () => $this->asset(['funded_by' => FixedAssetService::FUNDED_MONEY, 'funding_account_id' => $theirMoney]));
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function assertRefusedOn(string $field, callable $act): void
    {
        $errors = null;

        try {
            $act();
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }

        $this->assertNotNull($errors, "⛔ অন্য কোম্পানির বা অস্তিত্বহীন সারি '{$field}'-এ মেনে নেওয়া হলো।");
        $this->assertArrayHasKey($field, $errors, "⛔ ফিরেছে, কিন্তু অন্য কারণে: ".json_encode($errors, JSON_UNESCAPED_UNICODE));
    }

    private function note(string $direction, string $type, int $party): Note
    {
        return app(NoteService::class)->create([
            'direction' => $direction,
            'party_type' => $type,
            'party_id' => $party,
            'trx_date' => now()->toDateString(),
            'amount' => '100',
            'reason' => Note::REASONS[0],
        ]);
    }

    /** @param  array<string, mixed>  $funding */
    private function asset(array $funding): mixed
    {
        return app(FixedAssetService::class)->register([
            'name' => 'Delivery Van',
            'acquired_on' => now()->subMonth()->toDateString(),
            'cost' => '100000',
            'salvage' => '0',
            'life_months' => 60,
            'asset_account_id' => Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)?->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)?->id,
            ...$funding,
        ]);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} গ্রাহক, বিক্রেতা, মানুষ, টাকার খাত */
    private function partiesIn(Company $company): array
    {
        $this->at($company);
        app(StandardChart::class)->install();

        $customer = app(CustomerService::class)->create(['name_en' => 'Note customer '.$company->code, 'credit_limit' => '0', 'credit_days' => 0]);
        $supplier = app(SupplierService::class)->create(['name_en' => 'Note supplier '.$company->code, 'credit_limit' => 0, 'credit_days' => 0]);
        $person = Person::query()->create(['code' => 'P-'.random_int(10000, 99999), 'name_en' => 'Owner '.$company->code]);
        $till = app(CashTillService::class)->ensurePrimaryTill();

        return [(int) $customer->id, (int) $supplier->id, (int) $person->id, (int) $till->account_id];
    }

    private function at(Company $company): void
    {
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }
}
