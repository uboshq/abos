<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ হিসাবের ছক আর খাতের খাতা ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta mobile app style e zate bujazay"*।
 *
 * ⓘ দাবি: ফোনের দরজা ([[AccountLedgerApiController]]) ওয়েবের পাতার একই সংখ্যা পাঠায় — একই চলমান জের, সব পাতার একই ডেবিট,
 * ক্রেডিট ও শেষ জের (Dr/Cr), একই মাথার জের; খাত চেনে public_id-তে; ছকের চাবি ছাড়া ৪০৩।
 */
final class TheAccountLedgerOpensAsAPhoneScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->account = Account::query()->create([
            'company_id' => $company->id, 'code' => '1985', 'name_en' => 'Phone Ledger', 'name_bn' => 'ফোনের খাতা', 'parent_id' => null,
            'type' => Account::ASSET, 'nature' => Account::DEBIT, 'is_group' => false, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
        $other = StandardChart::find(StandardChart::SALARY_PAYABLE)->id;

        // ⓘ ৫০টা ডেবিট ১০০ আর ৩টা ক্রেডিট ২৫০ — দুই পাতা
        for ($i = 1; $i <= 53; $i++) {
            $amount = $i <= 50 ? '100' : '250';
            app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: 890000 + $i, trxDate: now()->subDays(60 - $i)->toDateString(), lines: $i <= 50
                ? [['account_id' => $this->account->id, 'debit' => $amount], ['account_id' => $other, 'credit' => $amount]]
                : [['account_id' => $other, 'debit' => $amount], ['account_id' => $this->account->id, 'credit' => $amount]]);
        }
    }

    public function test_the_phone_ledger_reads_the_web_pages_own_figures_on_every_page(): void
    {
        $web = $this->get(route('accounts.coa.show', [$this->account, 'page' => 2]))->assertOk();

        $this->phone($this->owner);
        $first = $this->getJson('/api/v1/accounts/chart/'.$this->account->public_id.'/ledger')->assertOk();

        $this->assertSame(53, $first->json('count'));
        $this->assertCount(50, $first->json('entries'));
        $this->assertSame(2, $first->json('next_page'));
        $this->assertSame(['debit' => '5,000.00', 'credit' => '750.00', 'closing' => '(Dr) 4,250.00'], $first->json('totals'),
            '⛔ ফোনের সর্বমোট পাতার, বা ওয়েবের পাতার সংখ্যা নয়।');
        $this->assertSame('(Dr) 4,250.00', $first->json('account.balance'), '⛔ মাথার জের ওয়েবের পাতার নয়।');

        $second = $this->getJson('/api/v1/accounts/chart/'.$this->account->public_id.'/ledger?page=2')->assertOk();
        $this->assertNull($second->json('next_page'));
        $this->assertSame(
            $web->viewData('entries')->getCollection()->map(fn ($e) => \App\Core\Support\Money::drCr($e->running_balance))->all(),
            array_column($second->json('entries'), 'balance'),
            '⛔ দ্বিতীয় পাতার চলমান জের ওয়েবের পাতার নয় — প্রথম পাতার জের থেকে শুরু হয়নি।',
        );
        $this->assertSame('(Dr) 4,250.00', $second->json('entries.2.balance'), 'শেষ সারির জের = শেষ জের');
    }

    public function test_the_chart_lists_accounts_by_public_id_with_their_balance_and_a_search(): void
    {
        $this->phone($this->owner);
        $all = collect($this->getJson('/api/v1/accounts/chart')->assertOk()->json('accounts'))->keyBy('code');

        $this->assertSame((string) $this->account->public_id, $all['1985']['id'], '⛔ খাত সংখ্যার id-তে, public_id নয়।');
        $this->assertSame('(Dr) 4,250.00', $all['1985']['balance']);
        $this->assertFalse($all['1985']['is_group']);

        $found = $this->getJson('/api/v1/accounts/chart?q=ফোনের')->assertOk()->json('accounts');
        $this->assertSame(['1985'], array_column($found, 'code'));
    }

    public function test_someone_without_the_chart_key_is_refused_both_doors(): void
    {
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->owner->current_company_id]);
        $clerk->companies()->attach($this->owner->current_company_id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->owner->current_company_id, fn () => $clerk->givePermissionTo(
            Permission::findOrCreate('accounts.voucher.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->phone($clerk);
        $this->getJson('/api/v1/accounts/chart')->assertForbidden();
        $this->getJson('/api/v1/accounts/chart/'.$this->account->public_id.'/ledger')->assertForbidden();
    }

    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
    }
}
