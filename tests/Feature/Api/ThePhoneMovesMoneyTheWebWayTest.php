<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\ApprovalFlowService;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ টাকার লেখা ফোন থেকে — হস্তান্তর, নগদ গোনা, হাতধার (মালিক, ১৭ অক্টোবর ২০২৬; fe; [[MoneyPhoneController]],
 * [[HandLoanPhoneController]])।
 *
 * দাবি, আসল HTTP দিয়ে, IFRS-এর খাতা মিলিয়ে:
 *  - হস্তান্তর পাঠালে Dr ১১০৩ পথের টাকা / Cr টিল; প্রাপক নিলে Dr গন্তব্য / Cr ১১০৩ — দুই ধাপ শেষে ১১০৩ শূন্য; একই চাবিতে একবারই।
 *  - পাঠানো মানুষ নিজে নিতে পারেন না (প্রাপক বা টিলের দায়িত্বে যিনি); ছক থাকলে পাঠানো সইয়ের অপেক্ষায়, খাতা নড়ে না।
 *  - নগদ গোনা খাতা নাড়ায় না; যিনি গুনেছেন তিনি মেনে নিতে পারেন না; অন্যজন মানলে ঘাটতি Dr ৫২৯৯ / Cr টিল; নিরীক্ষায় সারি।
 *  - হাতধার দেওয়া Dr ১১৭০ (মানুষের নামে) / Cr টাকার খাত; একই চাবিতে একবারই; ছক থাকলে ভাউচার খসড়া, খাতা নড়ে না।
 *  - চাবি ছাড়া প্রতিটা দরজা ৪০৩; অর্থের পাতা আর নতুন কাগজের তালিকা খোলে।
 */
final class ThePhoneMovesMoneyTheWebWayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private CashTill $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $branch = (int) $this->company->defaultBranch()?->id;
        $this->owner->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $branch])->save();
        CompanyContext::set((int) $this->company->id, $branch);
        $this->actingAs($this->owner);

        $this->till = app(CashTillService::class)->create(['name_en' => 'Phone counter', 'holder_id' => $this->owner->id]);
        $this->fund($this->till->account_id, '10000');
    }

    public function test_a_transfer_goes_through_transit_once_and_only_the_receiver_takes_it(): void
    {
        $receiver = $this->staff(['accounts.transfer.confirm', 'accounts.transfer.create']);
        $safe = app(CashTillService::class)->create(['name_en' => 'Phone safe', 'holder_id' => $receiver->id]);
        $body = ['trx_date' => now()->toDateString(), 'from_till' => $this->till->public_id,
            'destination' => 'till:'.$safe->public_id, 'amount' => '4000', 'narration' => 'দিনের নগদ'];

        $sent = $this->phonePost($this->owner, '/api/v1/accounts/transfers', $body, 'mt-key-0001')->assertCreated()->json();
        $this->phonePost($this->owner, '/api/v1/accounts/transfers', $body, 'mt-key-0001')->assertCreated();
        $this->assertSame(1, MoneyTransfer::query()->where('to_till_id', $safe->id)->count(), '⛔ একই চাবিতে দুটো হস্তান্তর।');

        $this->assertSame(0, bccomp($this->net(StandardChart::CASH_IN_TRANSIT), '4000', 4), '⛔ পাঠানো টাকা পথের খাতে বসেনি।');
        $this->assertSame(0, bccomp($this->balance($this->till->account_id), '6000', 4), '⛔ টিল থেকে টাকা কমেনি।');

        // ⛔ পাঠানো মানুষ নিজে নেন না — প্রাপক টিলের দায়িত্বে অন্যজন
        $this->phonePost($this->owner, '/api/v1/accounts/transfers/'.$sent['id'].'/confirm', [], 'mt-key-0002')->assertStatus(422);
        $this->phonePost($receiver, '/api/v1/accounts/transfers/'.$sent['id'].'/confirm', [], 'mt-key-0003')->assertCreated();

        $this->assertSame(0, bccomp($this->net(StandardChart::CASH_IN_TRANSIT), '0', 4), '⛔ নেওয়ার পরে পথের খাত শূন্য হয়নি — দুই খাতা মেলে না।');
        $this->assertSame(0, bccomp($this->balance($safe->account_id), '4000', 4), '⛔ প্রাপকের টিলে টাকা ওঠেনি।');
        $this->assertTrue(AuditTrail::query()->where('auditable_type', (new MoneyTransfer)->getMorphClass())->exists(), '⛔ হস্তান্তর নিরীক্ষায় নেই।');
    }

    public function test_with_a_flow_the_transfer_waits_for_its_signature_and_the_books_do_not_move(): void
    {
        $this->flow('accounts', 'transfer');
        $safe = app(CashTillService::class)->create(['name_en' => 'Phone safe 2', 'holder_id' => $this->owner->id]);

        $this->phonePost($this->owner, '/api/v1/accounts/transfers', ['trx_date' => now()->toDateString(), 'from_till' => $this->till->public_id,
            'destination' => 'till:'.$safe->public_id, 'amount' => '1000'], 'mt-key-0101')->assertStatus(202)->assertJsonPath('status', 'held');

        $this->assertSame(0, bccomp($this->net(StandardChart::CASH_IN_TRANSIT), '0', 4), '⛔ সইয়ের আগেই পথের খাতে টাকা।');
        $this->assertSame(0, bccomp($this->balance($this->till->account_id), '10000', 4), '⛔ সইয়ের আগেই টিল কমল।');
    }

    public function test_a_cash_count_moves_nothing_until_someone_else_settles_it_into_the_books(): void
    {
        $counter = $this->staff(['accounts.count.create', 'accounts.count.approve']);
        $approver = $this->staff(['accounts.count.approve', 'accounts.count.create']);

        // ⓘ ১০,০০০-এর বদলে ৯,৫০০ গোনা — ৫০০ ঘাটতি
        $made = $this->phonePost($counter, '/api/v1/accounts/cash-counts', ['till' => $this->till->public_id, 'trx_date' => now()->toDateString(),
            'counts' => ['1000' => 9, '500' => 1]], 'cc-key-0001')->assertCreated()->json();
        $this->assertSame(0, bccomp($this->balance($this->till->account_id), '10000', 4), '⛔ গোনা লেখার সাথেই খাতা নড়ল।');

        $this->phonePost($counter, '/api/v1/accounts/cash-counts/'.$made['id'].'/approve', [], 'cc-key-0002')->assertStatus(422);
        $this->assertSame('draft', CashCount::query()->where('public_id', $made['id'])->value('status'), '⛔ যিনি গুনেছেন তিনিই মেনে নিলেন।');

        $expenseBefore = $this->net('5299');
        $this->phonePost($approver, '/api/v1/accounts/cash-counts/'.$made['id'].'/approve', [], 'cc-key-0003')->assertCreated();

        $this->assertSame(0, bccomp($this->balance($this->till->account_id), '9500', 4), '⛔ মেনে নেওয়ার পরে টিল গোনার সমান নয়।');
        $this->assertSame(0, bccomp(bcsub($this->net('5299'), $expenseBefore, 4), '500', 4), '⛔ ৫০০ ঘাটতি খরচে বসেনি — দুই খাতা মেলে না।');
    }

    public function test_a_hand_loan_goes_out_once_into_its_own_head_and_waits_when_a_flow_says_so(): void
    {
        $person = Person::query()->create(['code' => 'P-PH1', 'name_en' => 'Rahim', 'name_bn' => 'রহিম', 'is_active' => true]);
        $loan = app(HandLoanService::class)->open(['person_id' => $person->id]);
        $cash = Account::query()->whereKey($this->till->account_id)->firstOrFail();
        $body = ['direction' => HandLoanMovement::OUT, 'amount' => '700', 'moved_on' => now()->toDateString(), 'money_account' => $cash->public_id];

        $this->phonePost($this->owner, '/api/v1/finance/hand-loans/'.$loan->public_id.'/move', $body, 'hl-key-0001')->assertCreated();
        $this->phonePost($this->owner, '/api/v1/finance/hand-loans/'.$loan->public_id.'/move', $body, 'hl-key-0001')->assertCreated();

        $this->assertSame(1, HandLoanMovement::query()->where('account_id', $loan->id)->count(), '⛔ একই চাবিতে হাতধার দুবার।');
        $this->assertSame(0, bccomp($this->net(StandardChart::HAND_LOAN), '700', 4), '⛔ হাতধার ১১৭০-এ বসেনি।');
        $this->assertSame(0, bccomp($this->balance($cash->id), '9300', 4), '⛔ টাকার খাত থেকে কমেনি — দুই খাতা মেলে না।');

        $this->flow('finance', 'hand_loan');
        $this->phonePost($this->owner, '/api/v1/finance/hand-loans/'.$loan->public_id.'/move', ['amount' => '200'] + $body, 'hl-key-0002')
            ->assertStatus(202)->assertJsonPath('status', 'held');
        $this->assertSame(0, bccomp($this->net(StandardChart::HAND_LOAN), '700', 4), '⛔ সইয়ের আগেই হাতধার খাতায় উঠল।');
    }

    public function test_every_money_door_asks_the_webs_key_and_the_finance_pages_open(): void
    {
        $nobody = $this->staff([]);
        $this->phoneGet($nobody, '/api/v1/accounts/custody')->assertForbidden();
        $this->phoneGet($nobody, '/api/v1/accounts/money/setup')->assertForbidden();
        $this->phoneGet($nobody, '/api/v1/accounts/cash-counts/setup')->assertForbidden();
        $this->phonePost($nobody, '/api/v1/accounts/transfers', ['amount' => '1'], 'no-key-0001')->assertForbidden();
        $this->phonePost($nobody, '/api/v1/accounts/cash-counts', ['till' => $this->till->public_id], 'no-key-0002')->assertForbidden();
        $this->phoneGet($nobody, '/api/v1/finance/cfo')->assertForbidden();
        $this->phoneGet($nobody, '/api/v1/finance/income')->assertForbidden();

        foreach (['/api/v1/accounts/custody', '/api/v1/accounts/money/setup', '/api/v1/accounts/cash-counts/setup', '/api/v1/accounts/transfers',
            '/api/v1/accounts/cash-counts', '/api/v1/finance/cfo', '/api/v1/finance/cash-forecast', '/api/v1/finance/income',
            '/api/v1/finance/budget', '/api/v1/finance/hand-loans/money-accounts',
            '/api/v1/papers/accounts_inter_company', '/api/v1/papers/finance_capital', '/api/v1/papers/finance_insurance'] as $url) {
            $this->phoneGet($this->owner, $url)->assertOk();
        }
        $this->assertContains($this->till->public_id, array_column($this->phoneGet($this->owner, '/api/v1/accounts/money/setup')->json('tills'), 'id'));
    }

    // ── যন্ত্রপাতি ──

    private function fund(int $accountId, string $amount): void
    {
        app(PostingEngine::class)->post(
            sourceType: 'test:fund', sourceId: random_int(1, PHP_INT_MAX), trxDate: now()->subDay()->toDateString(),
            lines: [
                ['account_id' => $accountId, 'debit' => $amount, 'narration' => 'শুরুর নগদ'],
                ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => $amount, 'narration' => 'শুরুর নগদ'],
            ],
        );
    }

    private function flow(string $module, string $action): void
    {
        app(ApprovalFlowService::class)->create(
            ['module' => $module, 'action' => $action, 'is_active' => true],
            [['level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => User::query()->where('email', 'accounts@abos.test')->value('id'), 'requires_all' => false]],
        );
    }

    private function balance(int $accountId): string
    {
        return (string) LedgerEntry::query()->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
    }

    /** খাতের নিট (ডেবিট − ক্রেডিট), নিচের সব খাতসহ */
    private function net(string $code): string
    {
        $ids = StandardChart::find($code)?->selfAndDescendants()->pluck('id')->all() ?? [0];

        return (string) LedgerEntry::query()->whereIn('account_id', $ids)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id,
            'current_branch_id' => $this->company->defaultBranch()?->id]);
        $user->companies()->attach($this->company, ['is_active' => true]);

        if ($keys !== []) {
            CompanyContext::forCompany((int) $this->company->id, fn () => $user->givePermissionTo(
                ...array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $user->fresh();
    }

    private function phoneGet(User $user, string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }

    private function phonePost(User $user, string $url, array $body, string $key): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->withHeader('Idempotency-Key', $key)->postJson($url, $body);
    }
}
