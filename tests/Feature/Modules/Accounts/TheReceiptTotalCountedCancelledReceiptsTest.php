<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * রসিদের তালিকার মোটে বাতিল রসিদও যোগ হত — পাতা-ঝাড়ু, ধাপ ০ (১০ অক্টোবর ২০২৬; ছবিতে রসিদের মোট ৫,১২,৩৩৯-এর ৫,০৫,০০০ বাতিল)।
 *
 * ⭐ ভাউচারের তালিকায় (ধরন ধরে, আর সব ভাউচার) মোটে কেবল পাকা ভাউচার; বাতিল আর খসড়া সারিতে দেখায়, মোটে নয়
 * ([[Voucher::countedAmount()]], [[Voucher::countedAmountSql()]])।
 */
final class TheReceiptTotalCountedCancelledReceiptsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_totals_count_only_posted_vouchers(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $cash = Account::query()->postable()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();
        $income = StandardChart::find(StandardChart::RENT_INCOME);
        $receipt = fn (string $amount) => app(VoucherService::class)->create(
            ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'মোটের পরীক্ষা', 'amount' => $amount],
            [['account_id' => $cash->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $income->id, 'debit' => '0', 'credit' => $amount]],
        );

        $before = [
            route('accounts.voucher.index', 'receipt') => (string) $this->get(route('accounts.voucher.index', 'receipt'))->viewData('grand')['amount'],
            route('accounts.voucher.list') => (string) $this->get(route('accounts.voucher.list'))->viewData('grand')['amount'],
        ];

        app(VoucherService::class)->post($posted = $receipt('1000'));
        app(VoucherService::class)->post($cancelled = $receipt('505000'));
        app(VoucherService::class)->cancel($cancelled->fresh(), 'ভুল রসিদ');
        $receipt('7');   // খসড়া

        $this->assertTrue($cancelled->fresh()->isCancelled(), 'দৃশ্যটাই বানানো যায়নি — রসিদটা বাতিল হওয়ার কথা।');
        $this->assertSame('0', $cancelled->fresh()->countedAmount());

        // ⓘ আরও ৫০টা বাতিল রসিদ — তালিকা দুই পাতা হয়, আর প্রথম পাতার নিজের মোট-সারি দেখায় (তাতেও বাতিল থাকবে না)
        foreach (range(1, 50) as $i) {
            $cancelled->fresh()->replicate(['public_id'])->forceFill(['document_no' => 'RV-X-'.$i, 'created_at' => now()->addSecond()])->save();
        }

        foreach ([route('accounts.voucher.index', 'receipt'), route('accounts.voucher.list')] as $url) {
            $page = $this->get($url)->assertOk();
            $grand = (string) $page->viewData('grand')['amount'];
            $this->assertSame(0, bccomp(bcsub($grand, $before[$url], 4), '1000', 4), '⛔ মোটে বাতিল বা খসড়া রসিদ যোগ হল — '.$url.' '.$grand);

            // ⓘ পাতায় আঁকা সর্বমোট-সারিও একই — বাতিলের ৫,০৫,০০০ নেই
            $html = $page->getContent();
            $foot = substr($html, (int) strpos($html, 'data-grand-total'));
            $this->assertStringContainsString(Money::format($grand), $foot, 'সর্বমোটের সারি পাতায় অন্য অঙ্ক বলে।');
            $this->assertStringNotContainsString(Money::format(bcadd($grand, '505000', 4)), $foot, '⛔ পাতার মোটে বাতিল রসিদ।');
            // ⓘ প্রথম পাতার নিজের মোট — ৫০টা বাতিল রসিদ সেখানে, মোট শূন্যই থাকার কথা
            $this->assertStringContainsString(__('core.table.page_total'), $foot, 'দৃশ্যটাই বানানো যায়নি — পাতার মোটের সারি নেই।');
            $this->assertStringNotContainsString(Money::format('25250000'), $foot, '⛔ পাতার মোটে ৫০টা বাতিল রসিদ যোগ হল।');
        }
    }
}
