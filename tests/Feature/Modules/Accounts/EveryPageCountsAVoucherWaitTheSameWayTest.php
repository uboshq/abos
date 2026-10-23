<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\MonthEndChecklist;
use App\Modules\Accounts\Services\VoucherApproval;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⭐ কোম্পানির প্রতিটা পাতা ভাউচারের অপেক্ষা একইভাবে গোনে — টাটকা অনুরোধ "সইয়ের অপেক্ষায়", বাসি অনুরোধ বদলানো খসড়া
 * ([[VoucherApproval::waitingIds()]]; fe, ২৪ অক্টোবর ২০২৬)।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────────
 * ড্যাশবোর্ড, খরচের পাতা, পোস্টিং মনিটরের আটকে-থাকা ট্যাব আর মাস-শেষের তালিকা প্রত্যেকে নিজে `pending()` গুনত — অনুরোধের পরে
 * ভাউচার বদলালেও "সইয়ের অপেক্ষায়" বলত, অথচ পরের পোস্টে সেই অনুরোধ তুলে নতুন চাওয়া হয়, আর ভাউচারের পাতা ও তালিকার মোট
 * সেটাকে খসড়া বলে।
 *
 * ⓘ মাস বন্ধ দুটোতেই আটকায় — কেবল কথা আলাদা: টাটকা হলে "সইয়ের অপেক্ষা", বাসি হলে "বদলানো খসড়া, আবার পোস্ট করুন"।
 * ⓘ ডেমোর নিজের কাগজ থাকতে পারে — তাই নমুনার আগে আর পরে, তফাতটা মাপা।
 */
final class EveryPageCountsAVoucherWaitTheSameWayTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $company = Company::query()->findOrFail($this->owner->current_company_id);
        CompanyContext::set($company->id, $this->owner->current_branch_id ?? $company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        // ⓘ আগের মাস — চলতি মাস বন্ধ করা যায় না
        $this->month = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();

        DB::table('approval_flows')->where('company_id', $company->id)->where('module', VoucherApproval::MODULE)->delete();
        $approver = User::query()->where('id', '!=', $this->owner->id)->firstOrFail();
        $this->flowOnExpenses((int) $company->id, (int) $approver->id);
        app()->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);
        app()->forgetInstance(VoucherApproval::class);
    }

    public function test_every_page_calls_only_the_fresh_request_waiting(): void
    {
        $before = $this->pages();
        [$fresh, $stale] = $this->freshAndStale();
        $after = $this->pages();

        $this->assertSame(1, $after['dashboard'] - $before['dashboard'], '⛔ ড্যাশবোর্ড বাসি অনুরোধকেও সইয়ের অপেক্ষায় গুনল');
        $this->assertSame(1, $after['expenses'] - $before['expenses'], '⛔ খরচের পাতা বাসি অনুরোধকেও সইয়ের অপেক্ষায় গুনল');
        $this->assertSame(1, $after['awaiting'] - $before['awaiting'], '⛔ মাস-শেষের "অপেক্ষা" সারিতে বাসি অনুরোধও এল');
        $this->assertSame(1, $after['stale'] - $before['stale'], '⛔ মাস-শেষে বদলানো খসড়ার সারিতে বাসি অনুরোধ এল না');

        $stuck = $this->get(route('accounts.control.posting', ['tab' => 'stuck']))->assertOk()->viewData('awaitingIds');
        $this->assertContains((int) $fresh->id, $stuck, '⛔ পোস্টিং মনিটর টাটকা অনুরোধকে সইয়ের অপেক্ষায় দেখাল না');
        $this->assertNotContains((int) $stale->id, $stuck, '⛔ পোস্টিং মনিটর বাসি অনুরোধকে সইয়ের অপেক্ষায় দেখাল');
    }

    public function test_the_month_will_not_close_either_way_and_says_which(): void
    {
        [$fresh] = $this->freshAndStale();

        $both = $this->closeErrors();
        $this->assertNotEmpty($both, '⛔ টাটকা আর বাসি অনুরোধ থাকতেও মাস বন্ধ হয়ে গেল');
        $this->assertContains(__('accounts::validation.month_has_changed_drafts', ['stale' => 1]), $both,
            '⛔ মাস বন্ধের বাধায় বদলানো খসড়ার কথা নেই — বাসি অনুরোধ "অপেক্ষা" হয়েই রইল');

        // ⓘ টাটকাটা চলে গেলে কেবল বাসিটা থাকে — তবু মাস আটকায়, নিজের কথায়
        $fresh->forceFill(['status' => DocumentStatus::CANCELLED])->save();
        Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $fresh->id)->delete();

        $onlyStale = $this->closeErrors();
        $this->assertNotEmpty($onlyStale, '⛔ কেবল বাসি অনুরোধের খসড়া থাকতে মাস বন্ধ হয়ে গেল — খসড়া খাতায় উঠবে না');
        $this->assertContains(__('accounts::validation.month_has_changed_drafts', ['stale' => 1]), $onlyStale,
            '⛔ কেবল বাসি অনুরোধে মাস আটকাল, কিন্তু "আবার পোস্ট করুন" বলল না');
    }

    /** @return array{0: Voucher, 1: Voucher} */
    private function freshAndStale(): array
    {
        $approval = app(VoucherApproval::class);
        $fresh = $this->expense('TAZA');
        $stale = $this->expense('BASI');

        $this->assertNotNull($approval->stopping($fresh->fresh()), 'নমুনা: টাটকা অনুরোধ লেখা হলো না');
        $this->assertNotNull($approval->stopping($stale->fresh()), 'নমুনা: পরে-বাসি অনুরোধ লেখা হলো না');
        $stale->fresh()->forceFill(['narration' => 'অনুরোধের পরে বদলানো'])->save();

        $this->assertSame(VoucherApproval::AWAITING, $approval->waitFor($fresh->fresh()), 'নমুনা: টাটকা অনুরোধ অপেক্ষা নয়');
        $this->assertSame(VoucherApproval::STALE, $approval->waitFor($stale->fresh()), 'নমুনা: বদলানোর পরেও অনুরোধ বাসি হলো না');

        return [$fresh, $stale];
    }

    /** @return array{dashboard: int, expenses: int, awaiting: int, stale: int} */
    private function pages(): array
    {
        $rows = collect(app(MonthEndChecklist::class)->run($this->month))->mapWithKeys(fn (array $r) => [$r['key'] => $r['count']]);

        return [
            'dashboard' => app(AccountsFacts::class)->vouchersAwaitingSignature(),
            'expenses' => (int) $this->get(route('finance.expense.index', [
                'from' => $this->month->toDateString(), 'to' => $this->month->endOfMonth()->toDateString(),
            ]))->assertOk()->viewData('waitingTotal'),
            'awaiting' => (int) $rows['awaiting'],
            'stale' => (int) $rows['stale'],
        ];
    }

    /** @return list<string> */
    private function closeErrors(): array
    {
        $this->post(route('accounts.period.close'), ['year' => $this->month->year, 'month' => $this->month->month])->assertRedirect();

        // ⓘ পরীক্ষায় সেশনের errors কখনো অ্যারে, কখনো ব্যাগ — দুই রূপই পড়া
        $errors = session('errors');
        $month = is_array($errors) ? ($errors['month'] ?? []) : ($errors?->getBag('default')->get('month') ?? []);

        return array_values(array_map('strval', (array) $month));
    }

    private function expense(string $narration): Voucher
    {
        $this->post(route('accounts.voucher.store', Voucher::EXPENSE), [
            'type' => Voucher::EXPENSE,
            'trx_date' => $this->month->addDays(9)->toDateString(),
            'from_account_id' => (int) Account::query()->money()->postable()->active()->orderBy('code')->value('id'),
            'to_account_id' => (int) Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->value('id'),
            'amount' => '650',
            'narration' => $narration,
            'save_as_draft' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Voucher::query()->where('narration', $narration)->latest('id')->firstOrFail();
    }

    private function flowOnExpenses(int $companyId, int $approverId): void
    {
        $flowId = DB::table('approval_flows')->insertGetId([
            'company_id' => $companyId, 'module' => VoucherApproval::MODULE, 'action' => Voucher::EXPENSE, 'document_type' => '',
            'threshold_amount' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            'public_id' => (string) Str::uuid(),
        ]);

        DB::table('approval_flow_steps')->insert([
            'approval_flow_id' => $flowId, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $approverId,
            'requires_all' => false, 'created_at' => now(), 'updated_at' => now(), 'public_id' => (string) Str::uuid(),
        ]);
    }
}
