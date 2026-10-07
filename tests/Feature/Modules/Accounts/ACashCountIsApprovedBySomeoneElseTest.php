<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashCountService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⛔ নগদ গোনার অনুমোদন — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️১৪)।
 *
 * ⓘ চারটা ফাঁক ছিল [[CashCountService::approve()]]-এ:
 *  · গণনাকারী নিজের গোনা নিজে মানতে পারতেন — ঘাটতি নিজে ক্ষমা।
 *  · জিম্মা চালু থাকলে সমন্বয় ভাউচার টিলের নিয়মে আটকাত, তাই কার্যত ধারক (যিনি গোনেন) ছাড়া কেউ অনুমোদন দিতে পারতেন না।
 *  · "খাতা বলে" লেখার সময়ের — পরে খাতা বদলালেও পুরনো তফাতই খাতায় বসত।
 *  · ৫২৯৯/৪৩০০ না থাকলে কোড-ক্রমে প্রথম খরচ বা আয়ের খাতে।
 */
final class ACashCountIsApprovedBySomeoneElseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $counter;

    private User $supervisor;

    private CashTill $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->counter = $this->staff();
        $this->supervisor = $this->staff();

        // ⓘ জিম্মা চালু — বাক্সটা গণনাকারীর; তত্ত্বাবধায়কের কোনো বাক্স নেই
        $this->till = app(CashTillService::class)->ensurePrimaryTill();
        $this->till->forceFill(['holder_id' => $this->counter->id])->save();

        Account::query()->whereKey($this->till->account_id)->update(['opening_balance' => '10000', 'opening_date' => '2026-07-01']);
        app(OpeningBalanceService::class)->forAccount(Account::query()->findOrFail($this->till->account_id));
    }

    public function test_the_counter_cannot_forgive_their_own_shortfall_and_someone_else_can(): void
    {
        $count = $this->countAs($this->counter, 9);

        $this->actingAs($this->counter);
        $this->assertSame(__('accounts::validation.count_needs_another_approver'), $this->refused(fn () => app(CashCountService::class)->approve($count))['status'][0] ?? null,
            '⛔ গণনাকারী নিজের ঘাটতি নিজে মানলেন');
        $this->assertNull($count->fresh()->approved_by);

        // ⓘ বাক্স নেই এমন তত্ত্বাবধায়ক — সমন্বয় ব্যবস্থার কাগজ, টিলের নিয়মে আটকায় না
        $this->actingAs($this->supervisor);
        $done = app(CashCountService::class)->approve($count->fresh());

        $this->assertSame((int) $this->supervisor->id, (int) $done->approved_by);
        $this->assertSame(Voucher::ORIGIN_CASH_COUNT, Voucher::query()->findOrFail($done->adjustment_voucher_id)->origin);
        $this->assertSame(0, bccomp('9000', $this->till->fresh()->balance(), 4), 'ঘাটতির পরে বাক্সে ৯,০০০ থাকার কথা');
    }

    public function test_the_owner_may_approve_their_own_count(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $count = $this->countAs($owner, 10);

        $this->actingAs($owner);
        $this->assertNotNull(app(CashCountService::class)->approve($count)->approved_at, 'মালিক (সুপার অ্যাডমিন) নিজের গোনা মানতে পারার কথা');
    }

    public function test_a_count_whose_books_moved_is_counted_again(): void
    {
        $count = $this->countAs($this->counter, 9);

        // ⓘ গোনার পরে সেই তারিখে ৫০০ টাকার রসিদ বসল
        app(PostingEngine::class)->post(sourceType: 'receipt_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
            ['account_id' => $this->till->account_id, 'debit' => '500'],
            ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '500'],
        ]);

        $this->actingAs($this->supervisor);
        $this->assertSame(__('accounts::validation.count_books_moved', ['then' => '10,000.00', 'now' => '10,500.00']),
            $this->refused(fn () => app(CashCountService::class)->approve($count))['status'][0] ?? null,
            '⛔ খাতা বদলানোর পরেও পুরনো তফাতে অনুমোদন হল');
        $this->assertNull($count->fresh()->adjustment_voucher_id);
        $this->assertSame(0, bccomp('10500', $this->till->fresh()->balance(), 4));
    }

    public function test_a_missing_adjustment_account_stops_instead_of_guessing(): void
    {
        StandardChart::find('5299')->forceFill(['is_active' => false])->save();
        $count = $this->countAs($this->counter, 9);

        $this->actingAs($this->supervisor);
        $this->assertSame(__('accounts::validation.adjustment_account_missing', ['code' => '5299', 'type' => __('accounts::type.'.Account::EXPENSE)]),
            $this->refused(fn () => app(CashCountService::class)->approve($count))['difference'][0] ?? null,
            '⛔ বিবিধ খরচ না থাকায় ঘাটতি অন্য কোনো খাতে বসল');
        $this->assertNull($count->fresh()->adjustment_voucher_id);
        $this->assertSame(0, bccomp('10000', $this->till->fresh()->balance(), 4));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function countAs(User $who, int $thousands): CashCount
    {
        $this->actingAs($who);

        return app(CashCountService::class)->record(['cash_till_id' => $this->till->id, 'trx_date' => now()->toDateString()], [1000 => $thousands]);
    }

    /** @return array<string, list<string>> */
    private function refused(\Closure $act): array
    {
        try {
            $act();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('⛔ দরজা খোলা — অনুমোদন হয়ে গেল');
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        foreach (['accounts.count.create', 'accounts.count.approve', 'accounts.voucher.create'] as $key) {
            $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }

        return $user;
    }
}
