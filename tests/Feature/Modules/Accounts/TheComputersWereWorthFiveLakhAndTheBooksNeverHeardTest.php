<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সম্পদ বসালে টাকাটাও খাতায় ওঠে — কোথা থেকে এল, সেটা ধরে।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিক জিজ্ঞেস করলেন অফিসের পাঁচ লাখ টাকার কম্পিউটার নিয়ে: *"এই টাকাটা
 * কোথা থেকে যাবে আর কোথায় এটা জমা হবে?"* ⓘ দেখা গেল ফর্মটা প্রশ্নটাই করে
 * না — সম্পদ বসালে কেবল একটা রেকর্ড হত, খাতায় একটা সারিও উঠত না।
 *
 * ⚠️ অথচ অবচয় ঠিকই বসত (খরচ ডেবিট / সঞ্চিত অবচয় ক্রেডিট)। ⛔ ফল:
 * স্থিতিপত্রে সম্পদের দাম ঋণাত্মক, আর লাভ-ক্ষতিতে এমন জিনিসের খরচ যেটা
 * খাতা অনুযায়ী নেই-ই। মালিক: *"ok tik koro"*।
 */
final class TheComputersWereWorthFiveLakhAndTheBooksNeverHeardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_an_owner_who_provides_the_computers_gets_the_capital(): void
    {
        $person = Person::query()->create([
            'company_id' => $this->company->id, 'code' => 'OWN9', 'name_en' => 'Rafiq Owner', 'is_active' => true,
        ]);

        $asset = app(FixedAssetService::class)->register([
            'name' => 'Office computers',
            'asset_account_id' => $this->anAssetAccount()->id,
            'cost' => '500000',
            'acquired_on' => now()->toDateString(),
            'method' => FixedAsset::STRAIGHT_LINE,
            'life_months' => 60,
            'accumulated_account_id' => Account::query()->where('code', StandardChart::ACCUMULATED_DEPRECIATION)->value('id'),
            'expense_account_id' => Account::query()->where('code', StandardChart::DEPRECIATION_EXPENSE)->value('id'),
            'funded_by' => FixedAssetService::FUNDED_CAPITAL,
            'funding_person_id' => $person->id,
        ]);

        $rows = LedgerEntry::query()
            ->where('source_type', FixedAsset::drillSourceType())
            ->where('source_id', $asset->id)
            ->get();

        $this->assertCount(2, $rows, 'সম্পদ বসল, অথচ খাতায় দুইটা সারি ওঠেনি।');

        $debit = $rows->firstWhere('account_id', $asset->asset_account_id);
        $this->assertSame('500000.0000', $debit->debit, 'সম্পদের খাতে পুরো দামটা ডেবিট হয়নি।');

        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
        $credit = $rows->firstWhere('account_id', $capital->id);

        $this->assertNotNull($credit, 'মূলধনে ক্রেডিট হয়নি — তাহলে টাকাটা কোথা থেকে এল বলা নেই।');
        $this->assertSame('500000.0000', $credit->credit);
        $this->assertSame('person', $credit->party_type, 'কার মূলধন, সেটা সারিতে লেখা নেই।');
        $this->assertSame($person->id, (int) $credit->party_id);
    }

    /** ⛔ যিনি আগেই ভাউচার কেটেছেন, তাঁর কেনা দুইবার উঠবে না। */
    public function test_already_recorded_posts_nothing(): void
    {
        $asset = app(FixedAssetService::class)->register([
            'name' => 'Already booked shelf',
            'asset_account_id' => $this->anAssetAccount()->id,
            'cost' => '12000',
            'acquired_on' => now()->toDateString(),
            'method' => FixedAsset::STRAIGHT_LINE,
            'life_months' => 24,
            'accumulated_account_id' => Account::query()->where('code', StandardChart::ACCUMULATED_DEPRECIATION)->value('id'),
            'expense_account_id' => Account::query()->where('code', StandardChart::DEPRECIATION_EXPENSE)->value('id'),
            'funded_by' => FixedAssetService::FUNDED_ALREADY,
        ]);

        $this->assertSame(0, LedgerEntry::query()
            ->where('source_type', FixedAsset::drillSourceType())
            ->where('source_id', $asset->id)
            ->count(), '"আগেই বসানো" বলা সত্ত্বেও আবার বসেছে।');
    }

    /** ব্যাংক থেকে দিলে ঐ খাতটাই কমে — আর সেটা ফর্ম থেকেই হয়। */
    public function test_the_form_posts_against_the_account_the_money_left(): void
    {
        $bank = Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();

        $this->post(route('accounts.asset.store'), [
            'name' => 'Delivery van',
            'asset_account_id' => $this->anAssetAccount()->id,
            'cost' => '800000',
            'salvage' => '0',
            'acquired_on' => now()->toDateString(),
            'method' => FixedAsset::STRAIGHT_LINE,
            'life_months' => 60,
            'funded_by' => FixedAssetService::FUNDED_MONEY,
            'funding_account_id' => $bank->id,
        ])->assertSessionHasNoErrors();

        $asset = FixedAsset::query()->where('name', 'Delivery van')->firstOrFail();

        $credit = LedgerEntry::query()
            ->where('source_type', FixedAsset::drillSourceType())
            ->where('source_id', $asset->id)
            ->where('account_id', $bank->id)
            ->first();

        $this->assertNotNull($credit, 'যে খাত থেকে টাকা গেল, সেটা কমেনি।');
        $this->assertSame('800000.0000', $credit->credit);
    }

    /** ⚠️ উৎস না বললে ফর্মটা থামে — এটাই পুরো সারাইয়ের মূল কথা। */
    public function test_the_form_refuses_an_asset_with_no_source(): void
    {
        $this->post(route('accounts.asset.store'), [
            'name' => 'Nameless money',
            'asset_account_id' => $this->anAssetAccount()->id,
            'cost' => '1000',
            'acquired_on' => now()->toDateString(),
            'method' => FixedAsset::STRAIGHT_LINE,
            'life_months' => 12,
        ])->assertSessionHasErrors('funded_by');
    }

    private function anAssetAccount(): Account
    {
        return Account::query()
            ->where('code', 'like', '12%')
            ->where('is_group', false)
            ->orderBy('code')
            ->firstOrFail();
    }
}
