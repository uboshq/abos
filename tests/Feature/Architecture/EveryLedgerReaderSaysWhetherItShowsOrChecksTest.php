<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * খাতা পড়ে এমন প্রতিটা ফাইল বলে দেয়: সে **দেখায়**, নাকি **যাচাই করে** — ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ── কেন ─────────────────────────────────────────────────────────────────
 * মালিকের নির্দেশ: হেডারে শাখা বাছলে সব জায়গায় কেবল সেই শাখার ডাটা। ⛔ কিন্তু
 * খাতার সব পাঠে শাখা-ছাঁকনি বসালে টাকার নিয়মগুলো ভুল সিদ্ধান্ত নিত — বাকির
 * সীমা অন্য শাখার বকেয়া না গুনে পার হতে দিত, টিল শূন্যের নিচে নামত, খাতার
 * সিল অর্ধেক সারি দেখে "অক্ষত" বলত।
 *
 * ⭐ তাই দুই তালিকা:
 *   SHOWS  — পর্দা, ড্যাশবোর্ড, ফোনের দেখানো: **দেখার শাখা** মানবে
 *            ([[DataScope::viewBranchIds()]] / [[DataScope::viewsOneBranch()]])
 *   CHECKS — যাচাই, পোস্টিং, অখণ্ডতা, মাইগ্রেশন আর মূল উপকরণ: **গোটা কোম্পানি**,
 *            দেখার শাখা কখনো নয়
 *
 * ⛔ নতুন কোনো ফাইল খাতা পড়তে শুরু করলে তাকে এখানে উঠতে হয় — নইলে লাল। সিদ্ধান্তটা
 * এড়ানো যায় না, আর সেটাই এই দাবির কাজ।
 *
 * ⓘ রিপোর্টগুলো (`/Reports/`) এখানে নেই: তাদের পাহারা [[ReportEngine::branchWall()]]
 * আর [[EveryReportStandsBehindTheBranchWallTest]]।
 */
final class EveryLedgerReaderSaysWhetherItShowsOrChecksTest extends TestCase
{
    private const PATTERN = '/LedgerEntry::query|table\(\'ledger_entries|LedgerBalances|->balanceOn\(/';

    /** @var array<string, string> দেখায় — দেখার শাখা মানবে (ধাপ খ) */
    private const SHOWS = [
        'app/Modules/Accounts/Dashboard/AccountsWidgets.php' => 'ড্যাশবোর্ডের টাকার ঘর',
        'app/Modules/Accounts/Http/Controllers/CashTillController.php' => 'টিলের তালিকা আর জের',
        'app/Modules/Accounts/Http/Controllers/ChartOfAccountsController.php' => 'খাতের তালিকার জের',
        'app/Modules/Accounts/Http/Controllers/FinanceControlController.php' => 'অর্থ-নিয়ন্ত্রণের পর্দা',
        'app/Modules/Accounts/Http/Controllers/MoneyCustodyController.php' => 'টাকা কার হাতে — পর্দা',
        'app/Modules/Accounts/Http/Controllers/VoucherListController.php' => 'ভাউচারের তালিকার অঙ্ক',
        'app/Modules/Accounts/Resources/views/coa/show.blade.php' => 'একটা খাতের পাতা',
        'app/Modules/Accounts/Services/AccountsFacts.php' => 'হিসাবের পর্দার তথ্য',
        'app/Modules/Accounts/Services/BalanceSheetService.php' => 'স্থিতিপত্রের পর্দা',
        'app/Modules/Accounts/Services/GroupLedgerService.php' => 'দলগত খতিয়ানের পর্দা',
        'app/Modules/Customer/Http/Controllers/CustomerController.php' => 'গ্রাহকের পাতার বকেয়া (সীমার পাশে গোটা কোম্পানির বকেয়াও — সমন্বয়কারীর সিদ্ধান্ত)',
        'app/Modules/Customer/Services/CustomerMetrics.php' => 'গ্রাহকের পাতার অঙ্ক',
        'app/Modules/Customer/Sync/CustomerDueSync.php' => 'ফোনে দেখানো বকেয়া',
        'app/Modules/Finance/Http/Controllers/InstitutionController.php' => 'প্রতিষ্ঠানের পাতা',
        'app/Modules/Finance/Services/AccountAnalysis.php' => 'খাত-বিশ্লেষণের পর্দা',
        'app/Modules/Finance/Services/BudgetService.php' => 'বাজেট বনাম আসল — পর্দা',
        'app/Modules/Finance/Services/CarrierAndLabourLedger.php' => 'বাহক আর শ্রমিকের খতিয়ান — পর্দা',
        'app/Modules/Finance/Services/CfoFigures.php' => 'অর্থ-প্রধানের সংখ্যা — পর্দা',
        'app/Modules/Finance/Services/HeadTotals.php' => 'মাথাভিত্তিক যোগফল — পর্দা',
        'app/Modules/Sales/Services/CustomerPapers.php' => 'গ্রাহকের কাগজে ছাপা বকেয়া',
        'app/Modules/Sales/Services/RouteMetrics.php' => 'রুটের পর্দার অঙ্ক',
        'app/Modules/Sales/Services/SalesCustomerTrade.php' => 'গ্রাহকের কেনাবেচার পর্দা',
        'app/Modules/Supplier/Dashboard/SupplierWidgets.php' => 'ড্যাশবোর্ডের পাওনা',
        'app/Modules/Supplier/Http/Controllers/SupplierController.php' => 'সরবরাহকারীর পাতার পাওনা',
    ];

    /** @var array<string, string> যাচাই করে — গোটা কোম্পানি, দেখার শাখা কখনো নয় */
    private const CHECKS = [
        'app/Core/Engines/Posting/PostingEngine.php' => 'খাতায় লেখা — পোস্টিং',
        'app/Core/Security/LedgerChain.php' => 'খাতার সিল — প্রতিটা সারি দেখতেই হয়',
        'app/Core/Services/LedgerBalances.php' => 'মূল উপকরণ — ডাকার জন ঠিক করে শাখা',
        'app/Models/Company.php' => 'কোম্পানিতে পোস্টিং আছে কি না',
        'app/Providers/AppServiceProvider.php' => 'LedgerBalances-এর বাঁধন',
        'app/Modules/Accounts/Database/Migrations/2026_09_09_100000_the_delivery_cost_had_nowhere_to_land.php' => 'তথ্য-সারাইয়ের মাইগ্রেশন',
        'app/Modules/Accounts/Database/Migrations/2026_10_24_100000_one_payable_head_held_three_different_debts.php' => 'তথ্য-সারাইয়ের মাইগ্রেশন',
        'app/Modules/Accounts/Integrity/AccountsChecks.php' => 'অখণ্ডতার পরীক্ষা',
        'app/Modules/Accounts/Models/Account.php' => 'মূল উপকরণ — balanceOn() শাখা নেয়, ডাকার জন ঠিক করে',
        'app/Modules/Accounts/Models/CashTill.php' => 'টিলের জের — টাকা বেরোনোর যাচাইয়ে',
        'app/Modules/Accounts/Models/Loan.php' => 'ঋণের বাকি — কিস্তির নিয়মে',
        'app/Modules/Accounts/Services/CashOnHand.php' => 'টিল শূন্যের নিচে নয়',
        'app/Modules/Accounts/Services/ChequeService.php' => 'চেকের পোস্টিং',
        'app/Modules/Accounts/Services/MonthEndChecklist.php' => 'মাস-শেষের অখণ্ডতা',
        'app/Modules/Accounts/Services/OpeningBalanceService.php' => 'খোলা জের — পোস্টিং',
        'app/Modules/Accounts/Services/YearEndService.php' => 'বছর বন্ধ — পোস্টিং',
        'app/Modules/Customer/Models/Customer.php' => 'মূল উপকরণ — বকেয়া (বাকির সীমা এখান থেকে পড়ে)',
        'app/Modules/Finance/Services/BankCharges.php' => 'ব্যাংক চার্জের পোস্টিং',
        'app/Modules/Finance/Services/BankFacilityService.php' => 'ব্যাংক সুবিধার সীমা',
        'app/Modules/Finance/Services/ProfitDistribution.php' => 'লাভ বণ্টন — পোস্টিং',
        'app/Modules/Sales/Services/CreditExposure.php' => '⛔ বাকির সীমা — মালিকের "সীমা পরম", গোটা কোম্পানি',
        'app/Modules/Sales/Services/DeliveryChallanService.php' => 'চালানে বাকির দেয়াল',
        'app/Modules/Sales/Services/SalesReturnService.php' => 'ফেরতের পোস্টিং',
        'app/Modules/Sales/Services/ShiftService.php' => 'কাউন্টারের পালার নগদ মেলানো',
        'app/Modules/Supplier/Models/Supplier.php' => 'মূল উপকরণ — পাওনা',
    ];

    public function test_every_ledger_reader_is_declared_and_no_declaration_is_stale(): void
    {
        $found = $this->readers();
        $declared = array_merge(array_keys(self::SHOWS), array_keys(self::CHECKS));

        $this->assertGreaterThan(40, count($found), 'খোঁজাটাই ভেঙেছে — খাতা পড়া ফাইল মাত্র '.count($found).'টা।');

        $undeclared = array_values(array_diff($found, $declared));
        $stale = array_values(array_diff($declared, $found));

        $this->assertSame([], $undeclared, implode("\n", [
            'এই ফাইলগুলো খাতা পড়ে, অথচ বলেনি দেখায় না যাচাই করে:', '', ...$undeclared, '',
            'SHOWS (দেখার শাখা মানবে) বা CHECKS (গোটা কোম্পানি) — একটায় কারণসহ তুলুন।',
        ]));
        $this->assertSame([], $stale, "তালিকায় আছে অথচ আর খাতা পড়ে না:\n".implode("\n", $stale));
    }

    public function test_no_file_is_both(): void
    {
        $this->assertSame([], array_values(array_intersect(array_keys(self::SHOWS), array_keys(self::CHECKS))));
    }

    /** @return list<string> */
    private function readers(): array
    {
        $root = base_path();
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (! str_ends_with($path, '.php') || str_contains($path, '/Reports/') || str_contains($path, 'Engines/Report/')) {
                continue;
            }

            if (preg_match(self::PATTERN, (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }
}
