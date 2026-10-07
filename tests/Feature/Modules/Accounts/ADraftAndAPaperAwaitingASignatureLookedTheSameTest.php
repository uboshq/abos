<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খসড়া আর সইয়ের অপেক্ষা — তালিকায় দুই নামে।
 *
 * ── কেন, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিক ৫ লাখের রসিদ অনুমোদনে পাঠালেন, আর আদায়ের তালিকায় সেটা দেখাল
 * "Draft" — ঠিক একটা ভুলে-রাখা খসড়ার মতো। জিজ্ঞেস করলেন: *"Status ki Draft
 * thakbe naki pending, ba waiting for approval likha dekhabe?"*
 *
 * ⭐ পাঠানোটা দেখায় "অনুমোদনের অপেক্ষায়", রেখে দেওয়া খসড়া আগের মতো "খসড়া"।
 * মাপ রেন্ডার হওয়া পাতায়, তিন জায়গায়: ধরনের তালিকা, সব ভাউচার, একক পাতা।
 */
final class ADraftAndAPaperAwaitingASignatureLookedTheSameTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_voucher_sent_for_approval_is_not_called_a_draft(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($user);

        $sent = $this->draft('SENT-ONE');
        $kept = $this->draft('KEPT-ONE');

        Approval::query()->create([
            'company_id' => $company->id,
            'approvable_type' => Voucher::class,
            'approvable_id' => $sent->id,
            'module' => VoucherApproval::MODULE,
            'action' => Voucher::JOURNAL,
            'amount' => '1000',
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);

        $awaiting = [__('core.status.awaiting_approval', [], 'bn'), __('core.status.awaiting_approval', [], 'en')];
        $draft = [__('core.status.draft', [], 'bn'), __('core.status.draft', [], 'en')];

        foreach ([
            'ধরনের তালিকা' => route('accounts.voucher.index', ['type' => Voucher::JOURNAL]),
            'সব ভাউচার' => route('accounts.voucher.list', ['tab' => Voucher::JOURNAL]),
        ] as $where => $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertTrue($this->rowSays($html, 'SENT-ONE', $awaiting), "{$where}: পাঠানো ভাউচারে \"অনুমোদনের অপেক্ষায়\" নেই।");
            $this->assertTrue($this->rowSays($html, 'KEPT-ONE', $draft), "{$where}: রেখে দেওয়া খসড়ায় \"খসড়া\" নেই।");
            $this->assertFalse($this->rowSays($html, 'SENT-ONE', $draft), "{$where}: পাঠানো ভাউচার এখনো \"খসড়া\" বলছে।");
        }

        $page = $this->get(route('accounts.voucher.show', $sent))->assertOk()->getContent();
        $this->assertTrue(str_contains($page, $awaiting[0]) || str_contains($page, $awaiting[1]), 'একক পাতায় "অনুমোদনের অপেক্ষায়" নেই।');
    }

    private function draft(string $narration): Voucher
    {
        return app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => $narration],
            [
                ['account_id' => Account::query()->where('code', StandardChart::RECEIVABLE)->value('id'), 'debit' => '1000'],
                ['account_id' => Account::query()->where('code', StandardChart::PAYABLE)->value('id'), 'credit' => '1000'],
            ],
        );
    }

    /** @param list<string> $words */
    private function rowSays(string $html, string $narration, array $words): bool
    {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $rows);

        foreach ($rows[1] as $row) {
            if (str_contains($row, $narration)) {
                foreach ($words as $word) {
                    if (str_contains(strip_tags($row), $word)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
