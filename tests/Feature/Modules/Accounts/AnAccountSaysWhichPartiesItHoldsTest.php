<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ পক্ষের নাম যেকোনো খাতে বসত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️১২; সমন্বয়কের সিদ্ধান্ত, ৭ অক্টোবর ২০২৬)।
 *
 * ⓘ পক্ষের খাতা আর বকেয়া পক্ষের নামের সব সারি যোগ করে, খাত যা-ই হোক; জাবেদার যেকোনো সারিতে পক্ষ বসানো যেত — খরচের সারিতে গ্রাহকের নাম
 * দিলে তাঁর বকেয়া বদলাত, পাওনার খাত নয়। এখন খাত বলে কোন পক্ষ রাখে ([[Account::holdsParty()]]): প্রমিত পরিবার আর আজকের খাতা থেকে,
 * সন্তান মায়ের থেকে, বদলান কেবল মালিক; ইঞ্জিন নতুন সারিতে মেলায়, পুরনো সারি অক্ষত।
 */
final class AnAccountSaysWhichPartiesItHoldsTest extends TestCase
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
        $this->actingAs($this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_the_control_accounts_hold_their_parties_and_the_rest_hold_none(): void
    {
        $this->assertSame(['customer', 'person'], StandardChart::find(StandardChart::RECEIVABLE)->party_types);
        $this->assertSame(['person', 'supplier'], StandardChart::find(StandardChart::PAYABLE)->party_types, 'দেনার পরিবারের সন্তানও');
        $this->assertSame(['employee', 'person'], StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->party_types);
        $this->assertSame(['person'], StandardChart::find(StandardChart::HAND_LOAN)->party_types);
        $this->assertSame(['person'], StandardChart::find(StandardChart::OWNER_CAPITAL)->party_types);
        $this->assertSame(['customer', 'person'], StandardChart::find(StandardChart::TENANT_DEPOSITS)->party_types);
        $this->assertFalse($this->cash()->holdsParty(), '⛔ নগদের খাত পক্ষ রাখে');
        $this->assertTrue(Account::query()->holdingParty()->whereKey(StandardChart::find(StandardChart::PAYABLE_GROUP)->id)->exists(), 'গ্রুপ খাতও তালিকায়');
    }

    public function test_a_party_lands_only_where_it_belongs(): void
    {
        $receivable = StandardChart::find(StandardChart::RECEIVABLE)->id;
        $expense = Account::query()->where('type', Account::EXPENSE)->where('is_group', false)->orderBy('code')->firstOrFail()->id;

        // ⛔ খরচের সারিতে গ্রাহক
        $this->assertThrows(fn () => $this->book([
            ['account_id' => $expense, 'debit' => '500', 'party_type' => 'customer', 'party_id' => 1],
            ['account_id' => $this->cash()->id, 'credit' => '500'],
        ]), PostingException::class);

        // ⛔ পাওনার খাতে সরবরাহকারী — ধরন মেলে না
        $this->assertThrows(fn () => $this->book([
            ['account_id' => $receivable, 'debit' => '500', 'party_type' => 'supplier', 'party_id' => 1],
            ['account_id' => $this->cash()->id, 'credit' => '500'],
        ]), PostingException::class);

        $this->assertSame(0, LedgerEntry::query()->where('account_id', $expense)->whereNotNull('party_type')->count(), '⛔ ভুল পক্ষ খাতায় বসল');

        // ⓘ ঠিক জায়গায় ঠিক ধরন — বসে; পক্ষ ছাড়া সারিও আগের মতো
        $this->book([
            ['account_id' => $receivable, 'debit' => '500', 'party_type' => 'customer', 'party_id' => 1],
            ['account_id' => $this->cash()->id, 'credit' => '500'],
        ]);
        $this->assertSame(1, LedgerEntry::query()->where('account_id', $receivable)->where('party_type', 'customer')->where('party_id', 1)->count());
    }

    public function test_an_old_line_with_a_party_on_the_wrong_account_can_still_be_reversed(): void
    {
        $expense = Account::query()->where('type', Account::EXPENSE)->where('is_group', false)->orderBy('code')->firstOrFail()->id;
        $row = ['company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->value('id'),
            'trx_date' => now()->toDateString(), 'source_type' => 'journal_voucher', 'source_id' => 777001, 'document_no' => 'JV-OLD',
            'created_at' => now(), 'updated_at' => now()];

        // ⓘ নিয়মের আগের সারি — খরচে গ্রাহকের নাম
        DB::table('ledger_entries')->insert([
            $row + ['account_id' => $expense, 'debit' => '300', 'credit' => '0', 'party_type' => 'customer', 'party_id' => 1],
            $row + ['account_id' => $this->cash()->id, 'debit' => '0', 'credit' => '300', 'party_type' => null, 'party_id' => null],
        ]);

        app(PostingEngine::class)->reverse('journal_voucher', 777001, now()->toDateString(), 'পুরনো ভুল');

        $this->assertSame(2, LedgerEntry::query()->where('source_type', 'journal_voucher:reversal')->where('source_id', 777001)->count(), '⛔ পুরনো কাগজ আর ফেরানো গেল না');
    }

    public function test_a_child_takes_its_parents_parties_and_only_the_owner_changes_them(): void
    {
        $child = app(AccountService::class)->create(['name_en' => 'Dhaka Carriers', 'parent_id' => StandardChart::find(StandardChart::PAYABLE_GROUP)->id]);
        $this->assertSame(['person', 'supplier'], $child->fresh()->party_types, '⛔ দেনার গ্রুপের নিচের নতুন খাত মায়ের ধর্ম পায়নি');

        $clerk = User::factory()->create();
        $clerk->companies()->attach($this->company, ['is_active' => true]);
        $this->actingAs($clerk);

        try {
            app(AccountService::class)->update($child->fresh(), ['party_types' => ['supplier']]);
            $this->fail('⛔ মালিক ছাড়া কেউ পক্ষের ধর্ম বদলালেন');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.party_types_owner_only'), $e->errors()['party_types'][0] ?? null);
        }

        // ⓘ না বদলালে আপত্তি নেই — অন্য ঘর বদলাতে ফর্ম একই তালিকা পাঠায়
        app(AccountService::class)->update($child->fresh(), ['party_types' => ['supplier', 'person'], 'name_en' => 'Dhaka Carriers 2']);
        $this->assertSame(['person', 'supplier'], $child->fresh()->party_types);

        $this->actingAs($this->owner);
        $this->get(route('accounts.coa.edit', $child))->assertOk()->assertSee('data-party-types', false);
        $this->put(route('accounts.coa.update', $child), ['code' => $child->code, 'name_en' => 'Dhaka Carriers', 'parent_id' => $child->parent_id,
            'party_types_shown' => '1', 'party_types' => ['supplier', 'person', 'customer']])->assertSessionHasNoErrors();
        $this->assertSame(['customer', 'person', 'supplier'], $child->fresh()->party_types);

        // ⓘ সব টিক তুলে দিলে — পক্ষ রাখে না
        $this->put(route('accounts.coa.update', $child), ['code' => $child->code, 'name_en' => 'Dhaka Carriers', 'parent_id' => $child->parent_id,
            'party_types_shown' => '1'])->assertSessionHasNoErrors();
        $this->assertFalse($child->fresh()->holdsParty());
    }

    public function test_the_migration_reads_todays_books_and_adds_only(): void
    {
        $loose = Account::query()->where('type', Account::LIABILITY)->where('is_group', false)->whereNull('party_types')->orderBy('code')->firstOrFail();
        $row = ['company_id' => $this->company->id, 'financial_year_id' => FinancialYear::query()->where('is_current', true)->value('id'),
            'trx_date' => now()->toDateString(), 'source_type' => 'journal_voucher', 'source_id' => 777002,
            'created_at' => now(), 'updated_at' => now()];
        DB::table('ledger_entries')->insert($row + ['account_id' => $loose->id, 'debit' => '0', 'credit' => '100', 'party_type' => 'person', 'party_id' => 9]);
        DB::table('accounts')->where('id', StandardChart::find(StandardChart::RECEIVABLE)->id)->update(['party_types' => null]);

        $migration = require base_path('app/Modules/Accounts/Database/Migrations/2027_02_18_100000_an_account_says_which_parties_it_holds.php');
        $migration->up();
        $migration->up();

        $this->assertSame(['person'], $loose->fresh()->party_types, '⛔ আজকের খাতায় পক্ষসহ খাত ধর্ম পায়নি');
        $this->assertSame(['customer', 'person'], StandardChart::find(StandardChart::RECEIVABLE)->party_types, '⛔ প্রমিত পরিবার ধর্ম পায়নি');
        $this->assertFalse($this->cash()->fresh()->holdsParty());
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @param  list<array<string, mixed>>  $lines */
    private function book(array $lines): void
    {
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: $lines);
    }

    private function cash(): Account
    {
        return Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id);
    }
}
