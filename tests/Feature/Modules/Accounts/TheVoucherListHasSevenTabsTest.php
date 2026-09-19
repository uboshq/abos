<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Http\Controllers\VoucherListController;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ভাউচারের তালিকা — সাতটা ট্যাব, আর প্রতিটা ভাউচার ঠিক একটাতে।
 *
 * ── ⭐ মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"Master-এর পাশে 'Voucher List' নামে মেনু বানাও, তাতে ট্যাব করো —
 * Receipt Voucher, Sales Added Deposit, Payment Voucher, Exp. Voucher,
 * Journal, Contra, others voucher।"*
 *
 * ── ⚠️ সবচেয়ে জরুরি দাবিটা ভাগের, পর্দার নয় ─────────────────────────
 * ⛔ একটা ভাউচার দুই ট্যাবে দেখালে দুই ট্যাবের যোগফল মিলিয়ে কেউ দ্বিগুণ
 * টাকা গুনতেন; কোনো ট্যাবে না দেখালে ভাউচারটা তালিকা থেকে **নীরবে**
 * উবে যেত। ⓘ তাই প্রতিটা ধরনের একটা করে ভাউচার বানিয়ে গোনা হয়: সাত
 * ট্যাবের সংখ্যার যোগ = মোট ভাউচার।
 */
final class TheVoucherListHasSevenTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
    }

    /**
     * ⭐ সাতটা ট্যাব, মালিকের ক্রমেই।
     */
    public function test_the_page_shows_seven_tabs_in_the_owners_order(): void
    {
        $page = $this->get(route('accounts.voucher.list'));

        $page->assertOk();

        $page->assertSeeInOrder(array_map(
            fn (string $tab) => __('accounts::voucher.tab.'.$tab),
            VoucherListController::TABS,
        ));
    }

    /**
     * ⭐ মেনুতে Master-এর ঠিক পরে।
     *
     * ⓘ `transactions`-এর সারিগুলো বারে আলাদা বোতাম হয়ে বসে, তাই দাবি দুইটা:
     * গ্রুপটা Master-এর পরে, আর ভাউচার তালিকা তার **প্রথম** সারি।
     *
     * ⚠️ আর পাতাটা খোলে — আলাদা গ্রুপ বসানোর প্রথম চেষ্টায় গোটা অ্যাপ
     * চালু হওয়ার সময়েই ভেঙেছিল (গ্রুপের নাম কোরে বাঁধা)।
     */
    public function test_the_menu_places_it_right_after_master(): void
    {
        $module = require base_path('app/Modules/Accounts/module.php');
        $groups = array_keys($module['menu']);

        $this->assertSame(
            array_search('master', $groups, true) + 1,
            array_search('transactions', $groups, true),
            'Master-এর পরের গ্রুপটা আর transactions নয়।',
        );

        $this->assertSame('accounts.voucher.list', $module['menu']['transactions'][0]['route'] ?? null,
            'ভাউচার তালিকা transactions-এর প্রথম সারি নয় — Master-এর পাশে বসবে না।');
    }

    /**
     * ⭐ প্রতিটা ভাউচার ঠিক একটা ট্যাবে — বাদ নয়, দুইবার নয়।
     */
    public function test_every_voucher_lands_in_exactly_one_tab(): void
    {
        foreach ([
            [Voucher::RECEIPT, null],
            [Voucher::RECEIPT, SalesInvoice::drillSourceType()],
            [Voucher::PAYMENT, null],
            [Voucher::PAYMENT, SalesInvoice::drillSourceType()], // ⚠️ বিলের বিপরীতে ফেরত টাকা
            [Voucher::EXPENSE, null],
            [Voucher::JOURNAL, null],
            [Voucher::CONTRA, null],
            [Voucher::RECEIPT, 'capital_entry'],
            [Voucher::JOURNAL, 'cash_count'],
        ] as $i => [$type, $against]) {
            $this->voucher($type, $against, $i);
        }

        $total = Voucher::query()->count();
        $page = $this->get(route('accounts.voucher.list'));
        $counts = $page->viewData('counts');

        $this->assertSame($total, array_sum($counts),
            'সাত ট্যাবের যোগ মোট ভাউচারের সমান নয় — কোনোটা বাদ পড়েছে বা দুইবার এসেছে।');

        $this->assertSame(1, $counts[VoucherListController::SALES_DEPOSIT],
            'বিক্রয় বিলের রসিদ "বিক্রয়ের ডিপোজিট" ট্যাবে নেই।');

        // ⓘ বিলের বিপরীতে পরিশোধ, Finance-এর রসিদ, টাকা গোনার জাবেদা
        $this->assertSame(3, $counts[VoucherListController::OTHERS]);

        // ⓘ হাতে লেখা রসিদ কেবল একটা — Finance-এর রসিদ এখানে আসে না
        $this->assertSame(1, $counts[Voucher::RECEIPT]);
    }

    /**
     * ⭐ হাতে লেখা নামটা বিক্রয় বিলের আসল নামের সাথে মেলে।
     *
     * ⓘ কারণটা [[VoucherListController::SALES_INVOICE_SOURCE]]-এ: হিসাব
     * মডিউল বিক্রয়ের মডেল ডাকতে পারে না। ⛔ নাম বদলালে এই ট্যাব নীরবে
     * খালি হয়ে যেত — এই দাবিটা তখন লাল হয়।
     */
    public function test_the_sales_invoice_name_matches(): void
    {
        $this->assertSame(
            SalesInvoice::drillSourceType(),
            VoucherListController::SALES_INVOICE_SOURCE,
        );
    }

    private function voucher(string $type, ?string $against, int $n): void
    {
        Voucher::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'financial_year_id' => $this->financialYearId(),
            'type' => $type,
            'document_no' => 'TST-'.$n,
            'trx_date' => now()->toDateString(),
            'amount' => '100',
            'narration' => 'TAB-TEST '.$n,
            'against_type' => $against,
            'against_id' => $against === null ? null : $n + 1,
            'money_account_id' => Account::query()->money()->where('is_group', false)->value('id'),
            'status' => 'draft',
        ]);
    }

    private function financialYearId(): int
    {
        return (int) \App\Models\FinancialYear::query()->value('id');
    }
}
