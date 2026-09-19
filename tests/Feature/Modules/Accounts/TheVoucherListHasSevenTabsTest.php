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
use App\Core\Engines\Posting\PostingEngine;
use App\Modules\Purchase\Models\PurchaseBill;
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

        /*
         * ⓘ ক্রয় আর বিক্রয় ট্যাব ভাউচার নয়, খাতায় বসা বিল — ভাগের যোগে
         * ওদের গোনা হয় না ([[VoucherListController::PURCHASE]])।
         */
        $counts = array_diff_key($page->viewData('counts'), VoucherListController::DOCUMENT_TABS);

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

        $this->assertSame(
            PurchaseBill::drillSourceType(),
            VoucherListController::PURCHASE_BILL_SOURCE,
        );
    }

    /**
     * ⭐ ক্রয় ট্যাব খাতায় বসা বিল দেখায় — নিট অঙ্কে, একবার।
     *
     * ── ⛔ কেন নিট ──────────────────────────────────────────────────
     * নিশ্চিত বিল বদলালে আগের দাখিলা উল্টানো হয় (`purchase_bill:reversal`)
     * আর নতুন দাখিলা আবার আসল নামে বসে। ⚠️ কেবল আসল নামের ডেবিট যোগ
     * করলে বদলানো বিল দ্বিগুণ দেখাত।
     *
     * ⓘ তিনটা বিল: একটা সাধারণ, একটা বদলানো (উল্টে আবার বসানো), একটা
     * বাতিল (কেবল উল্টানো) — বাতিলটা তালিকায় আসে না, কারণ নিট শূন্য।
     *
     * ⓘ বদলানো বিলের অংশটা ১৯ সেপ্টেম্বর কিছুক্ষণ বাদ ছিল: তখন
     * [[PostingEngine]] উল্টানোর পরেও আবার বসাতে দিত না। abos-e8 সেটা সারিয়েছে
     * (861ad66a), আর দাবিটা ফিরে এসেছে — এখন এটাই সবচেয়ে জরুরি অংশ, কারণ
     * নিট হিসাব ভুল হলে বদলানো বিল ঠিক এখানেই দ্বিগুণ দেখাত।
     */
    public function test_the_purchase_tab_shows_posted_bills_at_their_net_amount(): void
    {
        $plain = 9001;
        $edited = 9002;
        $cancelled = 9003;

        $this->postBill($plain, '1000');

        $this->postBill($edited, '500');
        $this->reverse($edited);
        $this->postBill($edited, '700');

        $this->postBill($cancelled, '300');
        $this->reverse($cancelled);

        $page = $this->get(route('accounts.voucher.list', ['tab' => VoucherListController::PURCHASE]));

        $page->assertOk();

        $rows = collect($page->viewData('vouchers')->items())->keyBy('source_id');

        $this->assertEqualsCanonicalizing([$plain, $edited], $rows->keys()->map(fn ($k) => (int) $k)->all(),
            'বাতিল বিলটা তালিকায় এসেছে, বা একটা বিল হারিয়েছে।');

        $this->assertSame('1000.0000', number_format((float) $rows[$plain]->amount, 4, '.', ''),
            'অঙ্কটা খাতার ডেবিটের সাথে মেলে না।');

        $this->assertSame('700.0000', number_format((float) $rows[$edited]->amount, 4, '.', ''),
            'বদলানো বিল নিট অঙ্কে দেখাচ্ছে না — আগের আর নতুন দাখিলা যোগ হয়ে গেছে।');

        $this->assertSame(2, $page->viewData('counts')[VoucherListController::PURCHASE]);
    }

    private function postBill(int $id, string $amount): void
    {
        app(PostingEngine::class)->post(
            sourceType: PurchaseBill::drillSourceType(),
            sourceId: $id,
            trxDate: now()->toDateString(),
            lines: [
                ['account_id' => $this->accountId(StandardChart::INVENTORY), 'debit' => $amount],
                ['account_id' => $this->accountId(StandardChart::PAYABLE), 'credit' => $amount],
            ],
            documentNo: 'PBL-T'.$id,
        );
    }

    private function reverse(int $id): void
    {
        app(PostingEngine::class)->reverse(
            sourceType: PurchaseBill::drillSourceType(),
            sourceId: $id,
            reversalDate: now(),
            reason: 'TEST',
        );
    }

    private function accountId(string $code): int
    {
        return (int) Account::query()->where('code', $code)->value('id');
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
